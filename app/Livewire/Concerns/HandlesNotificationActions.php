<?php

namespace App\Livewire\Concerns;

use App\Services\NotificationRequestState;

trait HandlesNotificationActions
{
    use WithFeedback;

    public function openNotification(string $id): void
    {
        $notification = auth()->user()
            ->notifications()
            ->find($id);

        abort_unless($notification, 404);

        if ($notification->unread()) {
            $notification->markAsRead();
            $this->dispatch('notification-read');
        }

        // The record may have been deleted, or this user's role/city may have
        // changed since the message was written. Say so instead of landing on a
        // 403/404 page, and never reveal anything about the record.
        $state = app(NotificationRequestState::class)->forOne(auth()->user(), $notification->data);

        if ($state !== null && $state['state'] === NotificationRequestState::UNAVAILABLE) {
            $this->toast(__('general.notification-target-unavailable'), 'info');

            return;
        }

        $url = $this->safeNotificationUrl($notification->data['url'] ?? null);

        if ($url !== null && ! str_starts_with($url, 'mailto:')) {
            // Navigation starts only after the read state is persisted, avoiding
            // the race caused by wire:click and wire:navigate firing together.
            // This application still includes global page scripts that are not
            // idempotent under wire:navigate. A normal internal redirect keeps
            // the atomic read-before-navigation guarantee without a failed DOM
            // swap leaving the user on the previous page.
            $this->redirect($url);
        }
    }

    public function markAsRead(string $id): void
    {
        $updated = auth()->user()
            ->unreadNotifications()
            ->whereKey($id)
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            $this->dispatch('notification-read');
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        $this->dispatch('notification-read');
    }

    /**
     * Convert an application URL into an internal path. Invalid, external, and
     * protocol-relative destinations are never rendered or redirected to.
     */
    public function safeNotificationUrl(mixed $url): ?string
    {
        if (
            ! is_string($url)
            || $url === ''
            || preg_match('/[\x00-\x1F\x7F]/', $url)
        ) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        if (preg_match('/^mailto:[^?\s@]+@[^?\s@]+(?:,[^?\s@]+@[^?\s@]+)*(?:\?[^\\s]*)?$/i', $url) === 1) {
            return $url;
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || ! isset($parts['host'], $parts['scheme'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            return null;
        }

        $allowedHosts = array_unique(array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower(request()->getHost()),
        ]));

        if (! in_array(strtolower($parts['host']), $allowedHosts, true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    /**
     * Current record state for each listed notification, for the views.
     *
     * @param  iterable<int, \Illuminate\Notifications\DatabaseNotification>  $notifications
     * @return array<string, array{state: string, status: ?string, next_step: ?string}>
     */
    protected function requestStates(iterable $notifications): array
    {
        return app(NotificationRequestState::class)->forMany(auth()->user(), $notifications);
    }
}
