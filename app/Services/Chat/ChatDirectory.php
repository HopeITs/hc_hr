<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the chat screens show about conversations and people: names, photos,
 * the last message, unread counts. Read-only, and always for one viewer, so
 * it only ever looks at that viewer's own conversations.
 */
class ChatDirectory
{
    private const PREVIEW_LENGTH = 80;

    /**
     * The viewer's chats for the list: pinned first, then newest activity.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function conversations(User $viewer, string $search = '', int $limit = 50): Collection
    {
        $query = $this->baseQuery($viewer)->limit($limit);
        $items = $query->get()->map(fn (ChatConversation $c) => $this->present($viewer, $c));

        $search = trim(mb_strtolower($search));

        if ($search !== '') {
            // A person's name in either language, whatever the page is in.
            $items = $items->filter(fn (array $item) => str_contains($item['search_text'], $search))->values();
        }

        return $items;
    }

    /** One chat, as the list and the tab show it, or null if not the viewer's. */
    public function conversation(User $viewer, int $conversationId): ?array
    {
        $conversation = $this->baseQuery($viewer)->whereKey($conversationId)->first();

        return $conversation ? $this->present($viewer, $conversation) : null;
    }

    /** How many chats have something unread (muted ones not counted). */
    public function unreadChats(User $viewer): int
    {
        return $this->baseQuery($viewer)
            ->get()
            ->filter(fn (ChatConversation $c) => $c->unread_count > 0
                && ($c->viewer_muted_until === null || now()->gte($c->viewer_muted_until)))
            ->count();
    }

    /**
     * Active colleagues to start a chat with, by name in either language.
     *
     * @return Collection<int, array{id: int, name: string, avatar: string}>
     */
    public function people(User $viewer, string $search = '', int $limit = 30, array $exceptUserIds = []): Collection
    {
        $search = trim($search);

        return User::query()
            ->whereKeyNot($viewer->id)
            ->when($exceptUserIds !== [], fn (Builder $q) => $q->whereNotIn('id', $exceptUserIds))
            ->whereHas('profile', function (Builder $employee) use ($search) {
                $employee->where('is_active', true);

                if ($search !== '') {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                    $employee->where(fn (Builder $q) => $q
                        ->where('first_name_en', 'like', $like)
                        ->orWhere('last_name_en', 'like', $like)
                        ->orWhere('first_name_ar', 'like', $like)
                        ->orWhere('last_name_ar', 'like', $like));
                }
            })
            ->with('profile')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $this->nameOf($u), 'avatar' => $this->avatarOf($u)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function nameOf(?User $user): string
    {
        $name = trim((string) $user?->profile?->name);

        return $name !== '' ? $name : __('chat.ui.unknown_person');
    }

    public function avatarOf(?User $user): string
    {
        $employee = $user?->profile;

        return $employee instanceof Employee && $employee->is_active
            ? $employee->avatar_url
            : asset('images/default-avatar.png');
    }

    /** The one-line preview of a message, as the list shows it. */
    public function preview(?ChatMessage $message): string
    {
        if (! $message) {
            return '';
        }

        if ($message->isService()) {
            return $this->serviceText($message);
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $message->body) ?? '');

        if ($text === '' && $message->type === ChatMessage::TYPE_FILE) {
            $first = $message->attachments->first();
            $text = $first?->isImage() ? __('chat.ui.photo') : __('chat.ui.file');
        }

        return Str::limit($text, self::PREVIEW_LENGTH);
    }

    /** "Rana created the group", from the ids a service message records. */
    public function serviceText(ChatMessage $message): string
    {
        $meta = (array) $message->meta;
        $actor = isset($meta['actor']) ? $this->nameOf(User::query()->with('profile')->find($meta['actor'])) : '';
        $users = collect($meta['users'] ?? [])
            ->map(fn ($id) => $this->nameOf(User::query()->with('profile')->find($id)))
            ->implode(__('chat.ui.list_separator'));

        return __('chat.service.'.($meta['event'] ?? 'unknown'), ['actor' => $actor, 'users' => $users]);
    }

    /** The viewer's memberships, with what the list needs, in list order. */
    private function baseQuery(User $viewer): Builder
    {
        return ChatConversation::query()
            ->forUser($viewer)
            ->with(['lastMessage.attachments', 'participants.user.profile'])
            ->addSelect([
                'unread_count' => ChatMessage::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('messenger_messages.conversation_id', 'messenger_conversations.id')
                    // Raw SQL: table names need the connection prefix by hand.
                    ->whereRaw($this->table('messenger_messages').'.id > coalesce((select last_read_message_id from '.$this->table('messenger_participants').' where conversation_id = '.$this->table('messenger_conversations').'.id and user_id = ?), 0)', [$viewer->id])
                    ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $viewer->id))
                    ->visibleTo($viewer),
                'viewer_pinned_at' => ChatParticipant::query()
                    ->select('pinned_at')
                    ->whereColumn('conversation_id', 'messenger_conversations.id')
                    ->where('user_id', $viewer->id)
                    ->limit(1),
                'viewer_muted_until' => ChatParticipant::query()
                    ->select('muted_until')
                    ->whereColumn('conversation_id', 'messenger_conversations.id')
                    ->where('user_id', $viewer->id)
                    ->limit(1),
            ])
            ->orderByRaw('case when viewer_pinned_at is null then 1 else 0 end')
            ->orderByDesc('viewer_pinned_at')
            ->orderByDesc('last_message_at')
            ->orderByDesc('messenger_conversations.id');
    }

    /** @return array<string, mixed> */
    private function present(User $viewer, ChatConversation $conversation): array
    {
        $other = $conversation->isPrivate()
            ? $conversation->participants->first(fn (ChatParticipant $p) => $p->user_id !== $viewer->id)?->user
            : null;

        $last = $conversation->lastMessage;
        $muted = $conversation->viewer_muted_until !== null && now()->lt($conversation->viewer_muted_until);
        $conversation->setAttribute('viewer_muted', $muted);

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->isGroup() ? (string) $conversation->title : $this->nameOf($other),
            'avatar' => $conversation->isGroup() ? null : $this->avatarOf($other),
            'other_user_id' => $other?->id,
            'other_active' => $other ? (bool) $other->profile?->is_active : true,
            'members' => $conversation->participants->whereNull('left_at')->count(),
            'last_preview' => $this->preview($last),
            'last_mine' => $last && $last->sender_id === $viewer->id,
            'last_at' => $conversation->last_message_at,
            'unread' => (int) $conversation->unread_count,
            'pinned' => $conversation->viewer_pinned_at !== null,
            'muted' => $muted,
            'search_text' => mb_strtolower($conversation->isGroup() ? (string) $conversation->title : $this->bothNames($other)),
        ];
    }

    /** "Lana Abdo لانا عبدو": what a search over a person matches, in both languages. */
    private function bothNames(?User $user): string
    {
        $e = $user?->profile;

        return $e ? trim(implode(' ', array_filter([
            $e->first_name_en, $e->last_name_en, $e->first_name_ar, $e->last_name_ar,
        ]))) : '';
    }

    private function table(string $name): string
    {
        return ChatConversation::query()->getConnection()->getTablePrefix().$name;
    }
}
