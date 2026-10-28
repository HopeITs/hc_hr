<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HandlesNotificationActions;
use App\Services\NotificationRequestState;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    use HandlesLoadFailure;
    use HandlesNotificationActions;

    #[On('notification-read')]
    public function refreshList(): void {}

    public function render(): View
    {
        // Reads only. retryLoad() re-renders and never marks anything read, so
        // retrying a failed load cannot repeat or duplicate a write.
        [$notifications, $states, $unreadCount, $hasUnread] = $this->guardedLoad(function () {
            $notifications = auth()->user()->notifications()->latest()->paginate(10);

            return [
                $notifications,
                $this->requestStates($notifications->getCollection()),
                // The bell's number (Counter): unread less already handled (G-C-12).
                app(NotificationRequestState::class)->unreadCount(auth()->user()),
                auth()->user()->unreadNotifications()->exists(),
            ];
        }, fallback: [new LengthAwarePaginator([], 0, 10), [], 0, false]);

        return view('livewire.notifications.index', compact('notifications', 'states', 'unreadCount', 'hasUnread'));
    }
}
