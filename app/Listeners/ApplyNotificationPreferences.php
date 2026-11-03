<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Jobs\DeliverNotificationOutbox;
use App\Models\NotificationOutboxEntry;
use App\Models\User;
use App\Notifications\Contracts\BypassesPreferences;
use App\Services\Notifications\NotificationPreferenceGate;
use App\Services\Notifications\WebPushSender;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Applies a person's notification preferences around the existing
 * notification classes, which stay untouched (docs/notifications/architecture.md,
 * pipeline step 1):
 *
 *   database  writes the push / email copies to the outbox, then cancels the
 *             in-app row when in-app is off. The outbox is written first so
 *             someone who turned the bell off still gets their phone push.
 *   mail      cancelled when email is not allowed for the category.
 *
 * The handler is apply(), not handle(): Laravel discovers handle*() methods
 * in app/Listeners on its own, and AppServiceProvider registers this one
 * explicitly, so a handle() would run twice.
 */
class ApplyNotificationPreferences
{
    public function __construct(private NotificationPreferenceGate $gate) {}

    /** false cancels the channel; null lets it (and any other listener) proceed. */
    public function apply(NotificationSending $event): ?bool
    {
        $user = $event->notifiable;
        $notification = $event->notification;

        if (! $user instanceof User || $notification instanceof BypassesPreferences) {
            return null;
        }

        try {
            $category = NotificationCategory::forNotification($notification);

            return match ($event->channel) {
                'database' => $this->database($user, $notification, $category),
                'mail' => $this->gate->allows($user, $category, NotificationChannel::Email) ? null : false,
                default => null,
            };
        } catch (Throwable $e) {
            // A preference problem must never cost someone the notification itself.
            report($e);

            return null;
        }
    }

    private function database(User $user, Notification $notification, NotificationCategory $category): ?bool
    {
        try {
            $this->fanOut($user, $notification, $category);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->gate->allows($user, $category, NotificationChannel::InApp) ? null : false;
    }

    /**
     * One push row when desktop or mobile is allowed (and the person has a
     * device to send to), one email row when email is allowed and the
     * notification does not mail by itself. The unique
     * (notification_id, channel) key makes a retried notification a no-op.
     */
    private function fanOut(User $user, Notification $notification, NotificationCategory $category): void
    {
        $channels = [];

        if (($this->gate->allows($user, $category, NotificationChannel::Desktop)
                || $this->gate->allows($user, $category, NotificationChannel::Mobile))
            && WebPushSender::configured()
            && $user->pushSubscriptions()->deliverable()->exists()) {
            $channels[] = NotificationOutboxEntry::PUSH;
        }

        if ($this->gate->allows($user, $category, NotificationChannel::Email)
            && filled($user->email)
            && ! in_array('mail', (array) $notification->via($user), true)) {
            $channels[] = NotificationOutboxEntry::EMAIL;
        }

        if ($channels === []) {
            return;
        }

        $deliverAfter = $this->gate->deliverAt($user, $category);
        $payload = json_encode($this->payload($user, $notification), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = now();

        NotificationOutboxEntry::query()->insertOrIgnore(array_map(fn (string $channel) => [
            'user_id' => $user->getKey(),
            'notification_id' => $notification->id,
            'channel' => $channel,
            'category' => $category->value,
            'urgent' => $category->urgent(),
            'payload' => $payload,
            'deliver_after' => $deliverAfter,
            'created_at' => $now,
            'updated_at' => $now,
        ], $channels));

        $due = NotificationOutboxEntry::query()
            ->where('notification_id', $notification->id)
            ->whereIn('channel', $channels)
            ->due()
            ->pluck('id')
            ->all();

        if ($due !== []) {
            // After commit: the notification (and its database row) may still
            // be inside a transaction the job must not race.
            DeliverNotificationOutbox::dispatch($due)->afterCommit();
        }
    }

    /** @return array{title: mixed, body: mixed, url: ?string} */
    private function payload(User $user, Notification $notification): array
    {
        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($user)
            : (method_exists($notification, 'toArray') ? $notification->toArray($user) : []);

        if ($data instanceof DatabaseMessage) {
            $data = $data->data;
        }

        return [
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
        ];
    }
}
