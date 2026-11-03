<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationOutboxEntry;
use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrunePushDataTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_it_removes_expired_and_stale_devices_and_old_outbox_rows(): void
    {
        config(['webpush.stale_after_days' => 120]);
        $user = $this->makeNotifiedUser();

        $this->makeDevice($user, ['expires_at' => now()->subMinute()]);
        $this->makeDevice($user, ['last_seen_at' => now()->subDays(121)]);
        $neverSeenOld = $this->makeDevice($user, ['last_seen_at' => null]);
        PushSubscription::query()->whereKey($neverSeenOld->id)->update(['updated_at' => now()->subDays(130)]);
        $fresh = $this->makeDevice($user);

        $this->makeOutboxEntry($user, ['sent_at' => now()->subDays(31)]);
        $this->makeOutboxEntry($user, ['failed_at' => now()->subDays(40)]);
        $recentSent = $this->makeOutboxEntry($user, ['sent_at' => now()->subDays(2)]);
        $pending = $this->makeOutboxEntry($user, ['deliver_after' => now()->subDays(60)]);

        $this->artisan('push:prune')->assertSuccessful();

        $this->assertSame([$fresh->id], PushSubscription::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$recentSent->id, $pending->id],
            NotificationOutboxEntry::query()->pluck('id')->all(),
        );
    }
}
