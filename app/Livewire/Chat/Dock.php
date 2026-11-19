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
use Livewire\Component;

/**
 * The chat dock: docked tabs along the bottom edge (Facebook style), the
 * "Chats" launcher with the list of chats, and the new-chat picker.
 *
 * Mounted once in layouts/app inside @persist, so tabs survive wire:navigate.
 * Which tabs are there is also kept in the viewer's browser (chat.js), so a
 * refresh brings them back; every id coming back from the browser is checked
 * against the conversation policy again.
 *
 * A new message never opens a tab: it marks the tab (or the launcher) as
 * unread, and the person opens it.
 */
class Dock extends Component
{
    /** Tabs side by side; the rest wait behind "+N". */
    public const VISIBLE_TABS = 3;

    /** All tabs, most recent first. @var list<array{id: int, open: bool}> */
    public array $tabs = [];

    public bool $launcherOpen = false;

    /** 'chats' (the list) or 'people' (start a new chat). */
    public string $launcherMode = 'chats';

    public string $search = '';

    public string $peopleSearch = '';

    /** The browser hands back the tabs it remembered. */
    public function restore(array $saved): void
    {
        $tabs = [];

        foreach (array_slice($saved, 0, 20) as $tab) {
            $id = (int) ($tab['id'] ?? 0);

            if ($id > 0 && ! collect($tabs)->contains('id', $id) && $this->mayView($id)) {
                $tabs[] = ['id' => $id, 'open' => (bool) ($tab['open'] ?? false)];
            }
        }

        $this->tabs = $tabs;
    }

    #[On('chat-open')]
    public function openConversation(int $conversationId): void
    {
        if (! $this->mayView($conversationId)) {
            return;
        }

        $this->tabs = [
            ['id' => $conversationId, 'open' => true],
            ...array_values(array_filter($this->tabs, fn ($t) => $t['id'] !== $conversationId)),
        ];
        $this->launcherOpen = false;
        $this->saved();
    }

    /** "New chat" → a colleague: their private chat, opened. */
    public function startWith(int $userId): void
    {
        $other = User::query()->find($userId);

        if (! $other) {
            return;
        }

        try {
            $conversation = app(ChatService::class)->openPrivate($this->viewer(), $other);
        } catch (ValidationException $e) {
            $this->addError('people', collect($e->errors())->flatten()->first());

            return;
        }

        $this->launcherMode = 'chats';
        $this->peopleSearch = '';
        $this->openConversation($conversation->id);
    }

    public function toggle(int $conversationId): void
    {
        $this->tabs = array_map(fn ($t) => $t['id'] === $conversationId ? [...$t, 'open' => ! $t['open']] : $t, $this->tabs);
        $this->saved();
    }

    public function minimize(int $conversationId): void
    {
        $this->tabs = array_map(fn ($t) => $t['id'] === $conversationId ? [...$t, 'open' => false] : $t, $this->tabs);
        $this->saved();
    }

    /** Off the bar; the chat itself stays. */
    public function close(int $conversationId): void
    {
        $this->tabs = array_values(array_filter($this->tabs, fn ($t) => $t['id'] !== $conversationId));
        $this->saved();
    }

    #[On('chat-launcher-toggle')]
    public function toggleLauncher(): void
    {
        $this->launcherOpen = ! $this->launcherOpen;
        $this->launcherMode = 'chats';
    }

    public function showPeople(): void
    {
        $this->launcherMode = 'people';
        $this->resetErrorBag('people');
    }

    public function showChats(): void
    {
        $this->launcherMode = 'chats';
    }

    /** Something changed in one of the viewer's chats: badges and the list re-render. */
    #[On('chat-updated')]
    public function onUpdated(): void {}

    public function render(ChatDirectory $directory): View
    {
        $viewer = $this->viewer();
        $visible = array_slice($this->tabs, 0, self::VISIBLE_TABS);
        $overflow = array_slice($this->tabs, self::VISIBLE_TABS);
        $summaries = collect($this->tabs)->mapWithKeys(fn ($t) => [$t['id'] => $directory->conversation($viewer, $t['id'])]);
        $chats = $this->launcherOpen && $this->launcherMode === 'chats' ? $directory->conversations($viewer, $this->search) : collect();

        return view('livewire.chat.dock', [
            'visible' => collect($visible)->filter(fn ($t) => $summaries[$t['id']] !== null)->values(),
            'overflow' => collect($overflow)->filter(fn ($t) => $summaries[$t['id']] !== null)->values(),
            'summaries' => $summaries,
            // On a phone one chat at a time fills the screen: the latest opened.
            'mobileActiveId' => collect($this->tabs)->firstWhere('open', true)['id'] ?? null,
            'chats' => $chats,
            // Searching the list also finds colleagues there is no chat with yet (Telegram's global search).
            'colleagues' => $this->launcherOpen && $this->launcherMode === 'chats' && trim($this->search) !== ''
                ? $directory->people($viewer, $this->search, 8, $chats->pluck('other_user_id')->filter()->all())
                : collect(),
            'people' => $this->launcherOpen && $this->launcherMode === 'people' ? $directory->people($viewer, $this->peopleSearch) : collect(),
            'unreadChats' => $directory->unreadChats($viewer),
        ]);
    }

    /** The tabs, for the browser to remember (chat.js). */
    private function saved(): void
    {
        $this->dispatch('chat-tabs-changed', tabs: $this->tabs);
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
