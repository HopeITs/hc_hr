<?php

namespace App\Livewire\Notifications;

use App\Services\NotificationRequestState;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class Counter extends Component
{
    public int $count = 0;

    /**
     * Whether this instance polls. The page has two counters (header bell and
     * sidebar link); only the bell polls, the other follows its events, so one
     * browser tab asks the server once per interval, not once per counter.
     */
    #[Locked]
    public bool $poll = true;

    #[On('notification-read')]
    #[On('notification-created')]
    #[On('center-created')]
    #[On('center-closed')]
    public function updateCount(): void
    {
        $new = $this->unreadTotal();

        // A failed count keeps what is on screen; the next poll or event retries.
        if ($new === null) {
            return;
        }

        // A new notification landed since we last checked → tell the UI to react.
        if ($this->poll && $new > $this->count) {
            $this->dispatch('notif-arrived');
        }

        $this->count = $new;
    }

    /** Follows the polling counter's "new notification" event without announcing it a second time. */
    #[On('notif-arrived')]
    public function syncCount(): void
    {
        $this->count = $this->unreadTotal() ?? $this->count;
    }

    public function mount(bool $poll = true): void
    {
        $this->poll = $poll;
        $this->count = $this->unreadTotal() ?? 0;
    }

    public function render()
    {
        return view('livewire.notifications.counter');
    }

    private function unreadTotal(): ?int
    {
        try {
            // Unread, less those already handled (G-C-12): the same number as the list summary.
            return app(NotificationRequestState::class)->unreadCount(auth()->user());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
