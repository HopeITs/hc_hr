<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationOutboxEntry;
use App\Notifications\PreferenceEmail;
use App\Services\Notifications\NotificationTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTesterTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_device_test_reports_what_the_push_service_accepted(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);

        $result = $this->tester()->send($user, 'device', $device->id);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Accepted by the push service', $result['message']);
        $this->assertSame([$device->id], $push->sentTo());
        $this->assertTrue($push->sent[0]['message']['test']);
    }

    public function test_device_test_never_reaches_another_persons_device(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $someoneElse = $this->makeNotifiedUser();
        $theirDevice = $this->makeDevice($someoneElse);

        $result = $this->tester()->send($user, 'device', $theirDevice->id);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $push->sent);
    }

    public function test_device_test_reports_a_gone_device(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);
        $push->respondWith($device->endpoint, 410);

        $result = $this->tester()->send($user, 'device', $device->id);

        $this->assertFalse($result['ok']);
        $this->assertSame(__('notifications-delivery.tester.device_gone'), $result['message']);
    }

    public function test_push_not_configured_is_said_plainly(): void
    {
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);

        $result = $this->tester()->send($user, 'device', $device->id);

        $this->assertFalse($result['ok']);
        $this->assertSame(__('notifications-delivery.tester.push_not_configured'), $result['message']);
        $this->assertFalse($this->tester()->send($user, 'devices')['ok']);
    }

    public function test_all_devices_counts_what_was_accepted(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $broken = $this->makeDevice($user);
        $push->respondWith($broken->endpoint, 500);

        $result = $this->tester()->send($user, 'devices');

        $this->assertTrue($result['ok']);
        $this->assertSame(
            __('notifications-delivery.tester.devices_accepted', ['accepted' => 1, 'total' => 2]),
            $result['message'],
        );
    }

    public function test_all_devices_without_any_device_fails(): void
    {
        $this->configurePush();
        $user = $this->makeNotifiedUser();

        $this->assertFalse($this->tester()->send($user, 'devices')['ok']);
    }

    public function test_in_app_test_adds_a_row_and_nothing_else(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);

        $result = $this->tester()->send($user, 'in_app');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame([], $push->sent);
        $this->assertSame(0, NotificationOutboxEntry::query()->count());
    }

    public function test_in_app_test_says_so_when_in_app_is_off(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['in_app' => false]);

        $result = $this->tester()->send($user, 'in_app');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_email_test_is_handed_to_the_mailer(): void
    {
        Notification::fake();
        $user = $this->makeNotifiedUser('ar');

        $result = $this->tester()->send($user, 'email');

        $this->assertTrue($result['ok']);
        Notification::assertSentTo($user, PreferenceEmail::class, fn (PreferenceEmail $mail) => $mail->test && $mail->locale === 'ar');
    }

    public function test_email_test_says_so_when_email_is_off(): void
    {
        Notification::fake();
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['email' => false]);

        $result = $this->tester()->send($user, 'email');

        $this->assertFalse($result['ok']);
        Notification::assertNothingSent();
    }

    public function test_unknown_target_fails(): void
    {
        $this->assertFalse($this->tester()->send($this->makeNotifiedUser(), 'pigeon')['ok']);
    }

    private function tester(): NotificationTester
    {
        return $this->app->make(NotificationTester::class);
    }
}
