<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationOutboxEntry;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\PushClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\Fakes\FakePushClient;

/** Users, preferences, devices and outbox rows for the notification-delivery tests. */
trait NotificationFixtures
{
    protected function makeNotifiedUser(?string $locale = null): User
    {
        $user = User::query()->create([
            'email' => 'notif-'.Str::random(8).'@example.test',
            'password' => 'password',
        ]);

        if ($locale !== null) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function setPreference(User $user, array $attributes): NotificationPreference
    {
        $preference = NotificationPreference::for($user);
        $preference->fill($attributes)->save();

        return $preference;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeDevice(User $user, array $attributes = []): PushSubscription
    {
        $endpoint = $attributes['endpoint'] ?? 'https://push.example.test/'.Str::random(16);

        $subscription = new PushSubscription;
        $subscription->forceFill(array_merge([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => 'BPublicKey',
            'auth_token' => 'authToken',
            'device_type' => 'desktop',
            'enabled' => true,
            'urgent_only' => false,
            'last_seen_at' => now(),
        ], $attributes))->save();

        return $subscription->fresh();
    }

    protected function configurePush(): FakePushClient
    {
        config([
            'webpush.vapid.subject' => 'mailto:it@example.test',
            'webpush.vapid.public_key' => 'test-public-key',
            'webpush.vapid.private_key' => 'test-private-key',
        ]);

        $fake = new FakePushClient;
        $this->app->instance(PushClient::class, $fake);

        return $fake;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeOutboxEntry(User $user, array $attributes = []): NotificationOutboxEntry
    {
        $entry = new NotificationOutboxEntry;
        $entry->forceFill(array_merge([
            'user_id' => $user->id,
            'notification_id' => (string) Str::uuid(),
            'channel' => NotificationOutboxEntry::PUSH,
            'category' => 'leave_updates',
            'urgent' => false,
            'payload' => [
                'title' => ['en' => 'Leave approved', 'ar' => 'تمت الموافقة على الإجازة'],
                'body' => ['en' => 'Your annual leave was approved.', 'ar' => 'تمت الموافقة على إجازتك السنوية.'],
                'url' => route('notifications.index'),
            ],
            'deliver_after' => now()->subMinute(),
        ], $attributes))->save();

        return $entry->fresh();
    }

    protected function makeDatabaseNotification(User $user, string $id, bool $read = false): void
    {
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'x', 'body' => 'y']),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
