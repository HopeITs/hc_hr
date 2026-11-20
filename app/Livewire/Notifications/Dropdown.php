<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HandlesNotificationActions;
use App\Services\NotificationRequestState;
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
            // A notice about a request this user can no longer open leads nowhere:
            // the short list leaves it out (the full page still lists it, with why).
            $openable = function ($query, int $limit) use ($user) {
                $rows = $query->latest()->take($limit + 6)->get();
                $states = app(NotificationRequestState::class)->forMany($user, $rows);

                return $rows
                    ->reject(fn ($n) => ($states[$n->id]['state'] ?? null) === NotificationRequestState::UNAVAILABLE)
                    ->take($limit)
                    ->values();
            };

            $unread = $openable($user->unreadNotifications(), 3);

            $read = $unread->count() < 3
                ? $openable($user->readNotifications(), 4 - $unread->count())
                : collect();

            $notifications = $unread->merge($read);

            $hasUnread = $user->unreadNotifications()->exists();
            // The bell's number (Counter): unread less already handled (G-C-12).
            $unreadCount = app(NotificationRequestState::class)->unreadCount($user);

            return [$notifications, $this->requestStates($notifications), $hasUnread, $unreadCount];
        }, fallback: [collect(), [], false, 0]);

        return view('livewire.notifications.dropdown', compact('notifications', 'states', 'hasUnread', 'unreadCount'));
    }
}
