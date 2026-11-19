<?php

namespace App\Livewire\Chat;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatDirectory;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The full chat page: the chats list beside one open conversation. Reached
 * only from a tab's maximise button (there is no menu link), and left with
 * its minimise button, which puts the chat back on the dock as a tab.
 *
 * While it is open the dock steps aside (chat.js: [data-chat-page]).
 */
class Page extends Component
{
    #[Url(as: 'c')]
    public ?int $conversationId = null;

    public string $search = '';

    public function mount(): void
    {
        abort_unless(config('chat.enabled'), 404);

        if ($this->conversationId !== null && ! $this->mayView($this->conversationId)) {
            $this->conversationId = null;
        }
    }

    public function select(int $conversationId): void
    {
        if ($this->mayView($conversationId)) {
            $this->conversationId = $conversationId;
        }
    }

    public function startWith(int $userId): void
    {
        $other = User::query()->find($userId);

        if (! $other) {
            return;
        }

        try {
            $conversation = app(ChatService::class)->openPrivate($this->viewer(), $other);
        } catch (ValidationException $e) {
            $this->addError('search', collect($e->errors())->flatten()->first());

            return;
        }

        $this->search = '';
        $this->conversationId = $conversation->id;
    }

    #[On('chat-updated')]
    public function onUpdated(): void {}

    public function render(ChatDirectory $directory): View
    {
        $viewer = $this->viewer();
        $chats = $directory->conversations($viewer, $this->search);

        return view('livewire.chat.page', [
            'chats' => $chats,
            'colleagues' => trim($this->search) !== ''
                ? $directory->people($viewer, $this->search, 8, $chats->pluck('other_user_id')->filter()->all())
                : collect(),
        ])->title(__('chat.ui.chats'));
    }

    private function mayView(int $conversationId): bool
    {
        $conversation = ChatConversation::query()->find($conversationId);

        return $conversation !== null && Gate::forUser($this->viewer())->allows('view', $conversation);
    }

    private function viewer(): User
    {
        /** @var User */
        return Auth::user();
    }
}
