<?php

namespace App\Livewire\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatDirectory;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * One chat in the dock: a small tab when minimised, the conversation and its
 * composer when open. Membership is checked when it mounts and on every
 * action, so a tab for a chat one has left shows nothing.
 */
class Window extends Component
{
    private const PAGE = 30;

    #[Locked]
    public int $conversationId;

    #[Reactive]
    public bool $open = false;

    /** On a phone, the one chat that fills the screen. */
    #[Reactive]
    public bool $mobileActive = false;

    /** On the bar (desktop); false while it waits behind "+N". */
    #[Reactive]
    public bool $inBar = true;

    /** 'dock' (a tab) or 'page' (the full chat page, after maximise). */
    #[Locked]
    public string $mode = 'dock';

    /** How many of the latest messages are shown; "older" adds a page. */
    public int $limit = self::PAGE;

    public string $draft = '';

    public function mount(int $conversationId, string $mode = 'dock'): void
    {
        $this->conversationId = $conversationId;
        $this->mode = $mode === 'page' ? 'page' : 'dock';
        Gate::forUser($this->viewer())->authorize('view', $this->conversation());
    }

    public function send(ChatService $chat): void
    {
        $this->resetErrorBag('draft');

        try {
            $chat->send($this->viewer(), $this->conversation(), $this->draft);
        } catch (ValidationException $e) {
            $this->addError('draft', collect($e->errors())->flatten()->first());

            return;
        }

        $this->draft = '';
        $this->dispatch('chat-sent', conversationId: $this->conversationId);
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE;
    }

    /** Another member changed this chat (chat.js relays the realtime signal). */
    #[On('chat-updated')]
    public function onUpdated(int $conversationId = 0): void
    {
        if ($conversationId !== $this->conversationId) {
            $this->skipRender();
        }
    }

    public function render(ChatDirectory $directory, ChatService $chat): View
    {
        $viewer = $this->viewer();
        $conversation = $this->conversation();
        Gate::forUser($viewer)->authorize('view', $conversation);

        $messages = collect();
        $hasOlder = false;

        if ($this->open) {
            $query = $conversation->messages()
                ->visibleTo($viewer)
                ->with(['sender.profile', 'attachments'])
                ->latest('id');

            $messages = (clone $query)->limit($this->limit + 1)->get();
            $hasOlder = $messages->count() > $this->limit;
            $messages = $messages->take($this->limit)->reverse()->values();

            // An open tab counts as seen.
            if ($last = $messages->last()) {
                $chat->markRead($viewer, $conversation, $last->id);
            }
        }

        return view('livewire.chat.window', [
            'summary' => $directory->conversation($viewer, $this->conversationId),
            'messages' => $messages,
            'hasOlder' => $hasOlder,
            'canSend' => Gate::forUser($viewer)->allows('send', $conversation),
            'viewerId' => $viewer->id,
            'directory' => $directory,
            'isGroup' => $conversation->isGroup(),
        ]);
    }

    /** For the view: whether to start a new date group before this message. */
    public static function startsNewDay(?ChatMessage $previous, ChatMessage $message): bool
    {
        return $previous === null || ! $previous->created_at->isSameDay($message->created_at);
    }

    private function conversation(): ChatConversation
    {
        return ChatConversation::query()->findOrFail($this->conversationId);
    }

    private function viewer(): User
    {
        /** @var User */
        return Auth::user();
    }
}
