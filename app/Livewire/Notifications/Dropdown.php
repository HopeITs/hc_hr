<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HandlesNotificationActions;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Dropdown extends Component
{
    use HandlesLoadFailure;
    use HandlesNotificationActions;

    #[On('notification-read')]
    #[On('notification-created')]
    #[On('notif-arrived')]
    #[On('notification-panel-opened')]
    #[On('center-created')]
    #[On('center-closed')]
    public function refreshList(): void {}

    public function render(): View
    {
        $user = auth()->user();

        // Reads only; retryLoad() never writes, so a retry cannot mark anything read.
        [$notifications, $states, $hasUnread, $unreadCount] = $this->guardedLoad(function () use ($user) {
            $unread = $user->unreadNotifications()
                ->latest()
                ->take(3)
                ->get();

            $read = $unread->count() < 3
                ? $user->readNotifications()->latest()->take(4 - $unread->count())->get()
                : collect();

            $notifications = $unread->merge($read);

            $unreadCount = $user->unreadNotifications()->count();

            return [$notifications, $this->requestStates($notifications), $unreadCount > 0, $unreadCount];
        }, fallback: [collect(), [], false, 0]);

        return view('livewire.notifications.dropdown', compact('notifications', 'states', 'hasUnread', 'unreadCount'));
    }
}
