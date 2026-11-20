<?php

namespace App\Services\Chat;

use App\Events\ChatConversationUpdated;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Messages: open a private chat, send, edit, delete (for me / for everyone),
 * forward, and track reading. Every entry point checks the conversation
 * policy itself, so a caller cannot skip it.
 */
class ChatService
{
    public function __construct(
        private readonly ChatSearchIndex $index,
        private readonly ChatFileVault $vault,
        private readonly ChatNotifier $notifier,
    ) {}

    /** The private chat between two people, created the first time. */
    public function openPrivate(User $me, User $other): ChatConversation
    {
        if ($me->is($other)) {
            throw ValidationException::withMessages(['user' => __('chat.errors.self_chat')]);
        }

        if (! $this->isActive($other)) {
            throw ValidationException::withMessages(['user' => __('chat.errors.inactive_user')]);
        }

        return DB::transaction(function () use ($me, $other) {
            $conversation = ChatConversation::query()->firstOrCreate(
                ['direct_key' => ChatConversation::directKey($me->id, $other->id)],
                ['type' => ChatConversation::TYPE_PRIVATE],
            );

            foreach ([$me, $other] as $person) {
                ChatParticipant::query()->firstOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $person->id],
                    ['role' => ChatParticipant::ROLE_MEMBER, 'joined_at' => now()],
                );
            }

            return $conversation;
        });
    }

    /**
     * Send a message: text, files, or files with a caption, optionally as a
     * reply to another message in the same chat.
     *
     * @param  list<UploadedFile>  $files
     */
    public function send(User $sender, ChatConversation $conversation, ?string $body, array $files = [], ?int $replyToId = null): ChatMessage
    {
        Gate::forUser($sender)->authorize('send', $conversation);

        $body = $this->cleanBody($body);
        $this->validateFiles($files);

        if ($body === null && $files === []) {
            throw ValidationException::withMessages(['body' => __('chat.errors.empty_message')]);
        }

        $replyTo = $replyToId === null ? null : ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->visibleTo($sender)
            ->find($replyToId);

        if ($replyToId !== null && $replyTo === null) {
            throw ValidationException::withMessages(['reply_to' => __('chat.errors.reply_missing')]);
        }

        // Encrypt the files before the transaction; undo them if it fails.
        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->storeUpload($file);
            }

            return DB::transaction(function () use ($sender, $conversation, $body, $stored, $replyTo) {
                $message = ChatMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'type' => $stored === [] ? ChatMessage::TYPE_TEXT : ChatMessage::TYPE_FILE,
                    'body' => $body,
                    'reply_to_id' => $replyTo?->id,
                ]);

                foreach ($stored as $file) {
                    $message->attachments()->create($file);
                }

                $this->index->index($message, array_column($stored, 'original_name'));
                $this->touch($conversation, $message);
                $this->markRead($sender, $conversation, $message->id, announce: false);
                $this->notifier->changed($conversation, $message->id, ChatConversationUpdated::KIND_MESSAGE, $sender->id);

                return $message->load('attachments');
            });
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->vault->delete($file['path']);
            }

            throw $e;
        }
    }

    /** Change the text of one's own message. Files stay as they are. */
    public function edit(User $user, ChatMessage $message, ?string $body): ChatMessage
    {
        $conversation = $message->conversation;
        Gate::forUser($user)->authorize('view', $conversation);

        if ($message->sender_id !== $user->id || $message->isService()) {
            throw ValidationException::withMessages(['message' => __('chat.errors.not_your_message')]);
        }

        $body = $this->cleanBody($body);

        if ($body === null && $message->attachments()->doesntExist()) {
            throw ValidationException::withMessages(['body' => __('chat.errors.empty_message')]);
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
        $this->index->index($message, $message->attachments->map(fn ($a) => (string) $a->original_name)->all());
        $this->notifier->changed($conversation, $message->id, ChatConversationUpdated::KIND_EDITED, $user->id);

        return $message;
    }

    /** Hide a message from this user only; everyone else still has it. */
    public function deleteForMe(User $user, ChatMessage $message): void
    {
        Gate::forUser($user)->authorize('view', $message->conversation);

        DB::table('messenger_message_hidden')->insertOrIgnore([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Remove a message for everybody, with no trace: its text, its files and
     * its search entries are gone. One's own message at any time; in a group,
     * also anyone's, with the "delete messages" right.
     */
    public function deleteForEveryone(User $user, ChatMessage $message): void
    {
        $conversation = $message->conversation;
        Gate::forUser($user)->authorize('view', $conversation);

        $own = $message->sender_id === $user->id && ! $message->isService();

        if (! $own && ! Gate::forUser($user)->allows('deleteOthersMessages', $conversation)) {
            throw ValidationException::withMessages(['message' => __('chat.errors.cannot_delete')]);
        }

        $paths = $message->attachments
            ->flatMap(fn (ChatAttachment $a) => [$a->path, $a->thumbnail_path])
            ->filter()
            ->all();

        $messageId = $message->id;

        DB::transaction(function () use ($message, $conversation) {
            $message->delete(); // tokens, attachments rows and "hidden" rows cascade
            $this->refreshLastMessage($conversation);
        });

        $this->notifier->changed($conversation, $messageId, ChatConversationUpdated::KIND_DELETED, $user->id);

        foreach ($paths as $path) {
            $this->vault->delete($path);
        }
    }

    /**
     * Forward messages into one or more chats, in their original order. The
     * copies say whose message it originally was, unless $hideSender, in
     * which case they read as the forwarder's own. An optional comment goes
     * first, as its own message.
     *
     * @param  iterable<ChatMessage>  $messages
     * @param  iterable<ChatConversation>  $targets
     * @return Collection<int, ChatMessage> the new messages
     */
    public function forward(User $user, iterable $messages, iterable $targets, bool $hideSender = false, ?string $comment = null): Collection
    {
        $messages = collect($messages)->sortBy('id')->values();
        $targets = collect($targets)->unique('id')->values();

        if ($messages->isEmpty() || $targets->isEmpty()) {
            throw ValidationException::withMessages(['forward' => __('chat.errors.nothing_to_forward')]);
        }

        foreach ($messages as $message) {
            Gate::forUser($user)->authorize('view', $message->conversation);

            if ($message->isService()) {
                throw ValidationException::withMessages(['forward' => __('chat.errors.nothing_to_forward')]);
            }
        }

        foreach ($targets as $target) {
            Gate::forUser($user)->authorize('send', $target);
        }

        $comment = $this->cleanBody($comment);
        $created = collect();
        $copiedPaths = [];

        try {
            DB::transaction(function () use ($user, $messages, $targets, $hideSender, $comment, $created, &$copiedPaths) {
                foreach ($targets as $target) {
                    if ($comment !== null) {
                        $created->push($this->send($user, $target, $comment));
                    }

                    foreach ($messages as $original) {
                        $author = $original->forwarded_from_user_id ?? ($original->forward_hidden ? null : $original->sender_id);

                        $copy = ChatMessage::query()->create([
                            'conversation_id' => $target->id,
                            'sender_id' => $user->id,
                            'type' => $original->type,
                            'body' => $original->body,
                            'forwarded_from_user_id' => $hideSender ? null : $author,
                            'forwarded_from_message_id' => $hideSender ? null : $original->id,
                            'forward_hidden' => $hideSender,
                        ]);

                        $names = [];

                        foreach ($original->attachments as $attachment) {
                            $path = $this->vault->copy($attachment->path);
                            $copiedPaths[] = $path;
                            $thumbnail = $attachment->thumbnail_path ? $this->vault->copy($attachment->thumbnail_path) : null;

                            if ($thumbnail) {
                                $copiedPaths[] = $thumbnail;
                            }

                            $copy->attachments()->create([
                                'path' => $path,
                                'original_name' => $attachment->original_name,
                                'mime' => $attachment->mime,
                                'size' => $attachment->size,
                                'width' => $attachment->width,
                                'height' => $attachment->height,
                                'thumbnail_path' => $thumbnail,
                            ]);
                            $names[] = (string) $attachment->original_name;
                        }

                        $this->index->index($copy, $names);
                        $this->touch($target, $copy);
                        $this->markRead($user, $target, $copy->id, announce: false);
                        $this->notifier->changed($target, $copy->id, ChatConversationUpdated::KIND_MESSAGE, $user->id);
                        $created->push($copy);
                    }
                }
            });
        } catch (Throwable $e) {
            foreach ($copiedPaths as $path) {
                $this->vault->delete($path);
            }

            throw $e;
        }

        return $created;
    }

    /** Move this user's read marker forward (never back) to $messageId. */
    /**
     * Move this user's read marker forward (never back) to $messageId.
     * Returns whether it moved. When it does, and $announce is on, the other
     * members are told (their ✓ turns into ✓✓); a sender marking their own
     * new message read announces nothing.
     */
    public function markRead(User $user, ChatConversation $conversation, int $messageId, bool $announce = true): bool
    {
        $participant = $conversation->participantFor($user);

        if (! $participant) {
            return false;
        }

        $messageId = min($messageId, (int) $conversation->messages()->max('id'));

        if ($messageId <= (int) $participant->last_read_message_id) {
            return false;
        }

        $participant->forceFill(['last_read_message_id' => $messageId])->save();

        if ($announce) {
            $this->notifier->changed($conversation, $messageId, ChatConversationUpdated::KIND_READ, $user->id);
        }

        return true;
    }

    /** The newest message id any other current member has read up to (for ✓✓). */
    public function readByOthersUpTo(ChatConversation $conversation, User $viewer): int
    {
        return (int) $conversation->activeParticipants()
            ->where('user_id', '!=', $viewer->id)
            ->max('last_read_message_id');
    }

    /** Messages from others this user has not read yet. */
    public function unreadCount(User $user, ChatConversation $conversation): int
    {
        $participant = $conversation->participantFor($user);

        if (! $participant) {
            return 0;
        }

        return $conversation->messages()
            ->where('id', '>', (int) $participant->last_read_message_id)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->visibleTo($user)
            ->count();
    }

    /**
     * ✓ (sent) or ✓✓ (read) for the sender: read once the other person has
     * read it, or in a group, once anyone else has.
     */
    public function isReadByOthers(ChatMessage $message): bool
    {
        return $message->conversation->activeParticipants()
            ->where('user_id', '!=', $message->sender_id)
            ->where('last_read_message_id', '>=', $message->id)
            ->exists();
    }

    /** Point the conversation at its newest message after one is removed. */
    public function refreshLastMessage(ChatConversation $conversation): void
    {
        $last = $conversation->messages()->latest('id')->first();

        $conversation->forceFill([
            'last_message_id' => $last?->id,
            'last_message_at' => $last?->created_at,
        ])->save();
    }

    private function touch(ChatConversation $conversation, ChatMessage $message): void
    {
        $conversation->forceFill([
            'last_message_id' => $message->id,
            'last_message_at' => $message->created_at,
        ])->save();
    }

    private function cleanBody(?string $body): ?string
    {
        $body = $body === null ? '' : trim($body);

        if ($body === '') {
            return null;
        }

        if (mb_strlen($body) > (int) config('chat.max_message_length', 4096)) {
            throw ValidationException::withMessages(['body' => __('chat.errors.too_long', ['max' => config('chat.max_message_length')])]);
        }

        return $body;
    }

    /** @param list<UploadedFile> $files */
    private function validateFiles(array $files): void
    {
        if (count($files) > (int) config('chat.max_files_per_message', 10)) {
            throw ValidationException::withMessages(['files' => __('chat.errors.too_many_files', ['max' => config('chat.max_files_per_message')])]);
        }

        $blocked = array_map('strtolower', (array) config('chat.blocked_extensions', []));
        $maxBytes = (int) config('chat.max_file_kb', 51200) * 1024;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['files' => __('chat.errors.upload_failed')]);
            }

            $extension = strtolower($file->getClientOriginalExtension());

            if (in_array($extension, $blocked, true) || in_array(strtolower((string) $file->guessExtension()), $blocked, true)) {
                throw ValidationException::withMessages(['files' => __('chat.errors.blocked_type', ['name' => $file->getClientOriginalName()])]);
            }

            if ($file->getSize() > $maxBytes) {
                throw ValidationException::withMessages(['files' => __('chat.errors.too_large', ['name' => $file->getClientOriginalName(), 'max' => (int) ($maxBytes / 1024 / 1024)])]);
            }
        }
    }

    /** @return array{path: string, original_name: string, mime: string, size: int, width: ?int, height: ?int, thumbnail_path: null} */
    private function storeUpload(UploadedFile $file): array
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $dimensions = str_starts_with($mime, 'image/') ? @getimagesize($file->getRealPath()) : false;
        $stored = $this->vault->store($file->getRealPath());

        return [
            'path' => $stored['path'],
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $stored['size'],
            'width' => $dimensions ? (int) $dimensions[0] : null,
            'height' => $dimensions ? (int) $dimensions[1] : null,
            // Thumbnails come with the attachment screens (stage 4).
            'thumbnail_path' => null,
        ];
    }

    private function isActive(User $user): bool
    {
        return (bool) $user->profile?->is_active;
    }
}
