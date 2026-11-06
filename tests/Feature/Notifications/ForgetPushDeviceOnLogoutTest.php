<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ForgetPushDeviceOnLogoutTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_signing_out_removes_only_this_browsers_subscriptions_of_this_user(): void
    {
        $user = $this->makeNotifiedUser();
        $other = $this->makeNotifiedUser();
        $thisBrowser = $this->makeDevice($user, ['device_id' => 'device-a']);
        $otherBrowser = $this->makeDevice($user, ['device_id' => 'device-b']);
        $otherUserSameBrowser = $this->makeDevice($other, ['device_id' => 'device-a']);

        $this->signOutFrom('device-a', $user);

        $this->assertNull(PushSubscription::query()->find($thisBrowser->id));
        $this->assertNotNull(PushSubscription::query()->find($otherBrowser->id));
        $this->assertNotNull(PushSubscription::query()->find($otherUserSameBrowser->id));
    }

    public function test_without_the_cookie_nothing_is_removed(): void
    {
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user, ['device_id' => 'device-a']);
        $this->makeDevice($user, ['device_id' => null]);

        $this->signOutFrom(null, $user);

        $this->assertSame(2, PushSubscription::query()->count());
    }

    private function signOutFrom(?string $deviceId, User $user): void
    {
        $request = Request::create('/logout', 'POST', [], $deviceId === null ? [] : ['hopehr_device' => $deviceId]);
        $this->app->instance('request', $request);

        event(new Logout('web', $user));
    }
}
