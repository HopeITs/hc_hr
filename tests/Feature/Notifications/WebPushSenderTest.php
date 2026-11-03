<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Services\Notifications\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushSenderTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    private const PAYLOAD = [
        'title' => ['en' => 'Leave approved', 'ar' => 'تمت الموافقة على الإجازة'],
        'body' => ['en' => 'Your annual leave was approved.', 'ar' => 'تمت الموافقة على إجازتك السنوية.'],
        'url' => '/en/leaves',
    ];

    public function test_configured_needs_the_whole_vapid_pair(): void
    {
        $this->assertFalse(WebPushSender::configured());

        $this->configurePush();

        $this->assertTrue(WebPushSender::configured());
    }

    public function test_gone_subscription_is_deleted(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);
        $push->respondWith($device->endpoint, 410);

        $result = $this->sender()->sendToSubscription($device, ['title' => 't']);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['expired']);
        $this->assertSame(410, $result['status']);
        $this->assertNull(PushSubscription::query()->find($device->id));
    }

    public function test_other_failures_are_counted_and_the_device_dropped_at_the_limit(): void
    {
        config(['webpush.max_failures' => 3]);
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);
        $push->respondWith($device->endpoint, 500);

        $this->sender()->sendToSubscription($device, ['title' => 't']);
        $device->refresh();
        $this->assertSame(1, $device->failure_count);
        $this->assertNotNull($device->last_failure_at);
        $this->assertSame('HTTP 500', $device->last_error);

        $this->sender()->sendToSubscription($device, ['title' => 't']);
        $this->sender()->sendToSubscription($device->refresh(), ['title' => 't']);

        $this->assertNull(PushSubscription::query()->find($device->id));
    }

    public function test_success_resets_the_failure_count(): void
    {
        $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user, ['failure_count' => 2, 'last_error' => 'HTTP 500']);

        $result = $this->sender()->sendToSubscription($device, ['title' => 't']);

        $this->assertTrue($result['ok']);
        $device->refresh();
        $this->assertSame(0, $device->failure_count);
        $this->assertNull($device->last_error);
        $this->assertNotNull($device->last_success_at);
    }

    public function test_urgent_only_devices_get_urgent_notifications_only(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $normal = $this->makeDevice($user);
        $urgentOnly = $this->makeDevice($user, ['urgent_only' => true]);

        $this->assertSame(1, $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false));
        $this->assertSame([$normal->id], $push->sentTo());

        $push->sent = [];
        $this->assertSame(2, $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::Security, true));
        $this->assertEqualsCanonicalizing([$normal->id, $urgentOnly->id], $push->sentTo());
    }

    public function test_desktop_and_mobile_devices_follow_their_own_channel(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $desktop = $this->makeDevice($user, ['device_type' => 'desktop']);
        $phone = $this->makeDevice($user, ['device_type' => 'mobile']);
        $tablet = $this->makeDevice($user, ['device_type' => 'tablet']);

        $this->setPreference($user, ['mobile' => false]);
        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false);
        $this->assertSame([$desktop->id], $push->sentTo());

        $push->sent = [];
        $this->setPreference($user, ['mobile' => true, 'desktop' => false]);
        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false);
        $this->assertEqualsCanonicalizing([$phone->id, $tablet->id], $push->sentTo());

        // Organization is off on mobile by default.
        $push->sent = [];
        $this->setPreference($user, ['desktop' => true]);
        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::Organization, false);
        $this->assertSame([$desktop->id], $push->sentTo());
    }

    public function test_disabled_and_expired_devices_are_skipped(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user, ['enabled' => false]);
        $this->makeDevice($user, ['expires_at' => now()->subDay()]);
        $live = $this->makeDevice($user);

        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false);

        $this->assertSame([$live->id], $push->sentTo());
    }

    public function test_category_cell_off_for_a_channel_skips_those_devices(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user, ['device_type' => 'desktop']);
        $preference = NotificationPreference::for($user);
        $preference->setCategoryChannel(NotificationCategory::Files, NotificationChannel::Desktop, false);
        $preference->save();

        $this->assertSame(0, $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::Files, false));
        $this->assertSame([], $push->sent);
    }

    public function test_message_is_built_in_the_persons_language(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser('ar');
        $this->makeDevice($user);

        $this->sender()->sendToUser($user, self::PAYLOAD + ['tag' => 'abc'], NotificationCategory::LeaveUpdates, false);

        $message = $push->sent[0]['message'];
        $this->assertSame('تمت الموافقة على الإجازة', $message['title']);
        $this->assertSame('تمت الموافقة على إجازتك السنوية.', $message['body']);
        $this->assertSame(url('/en/leaves'), $message['url']);
        $this->assertSame('hopehr-abc', $message['tag']);
        $this->assertSame('/favicons/android-chrome-192x192.png', $message['icon']);
        $this->assertSame('/favicons/favicon-32x32.png', $message['badge']);
        $this->assertFalse($message['silent']);
        $this->assertFalse($message['urgent']);
        $this->assertFalse($message['test']);
        $this->assertSame('leave_updates', $message['category']);
        $this->assertSame('normal', $push->sent[0]['options']['urgency']);
    }

    public function test_plain_string_payloads_are_accepted(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);

        $this->sender()->sendToUser($user, ['title' => 'Plain title', 'body' => 'Plain body'], NotificationCategory::System, false);

        $this->assertSame('Plain title', $push->sent[0]['message']['title']);
        $this->assertSame('Plain body', $push->sent[0]['message']['body']);
        $this->assertSame(route('notifications.index'), $push->sent[0]['message']['url']);
    }

    public function test_previews_off_hide_the_content(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->setPreference($user, ['previews' => false]);

        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false);

        $message = $push->sent[0]['message'];
        $this->assertSame(__('pwa.app-name'), $message['title']);
        $this->assertSame(__('notifications-delivery.push.generic_body'), $message['body']);
        $this->assertStringNotContainsString('leave', strtolower($message['title'].$message['body']));
    }

    public function test_sound_off_makes_it_silent(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->setPreference($user, ['sound' => false]);

        $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false);

        $this->assertTrue($push->sent[0]['message']['silent']);
    }

    public function test_foreign_links_are_replaced_by_the_notifications_page(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);

        $this->sender()->sendToUser($user, ['title' => 't', 'url' => 'https://evil.example/x'], NotificationCategory::System, false);

        $this->assertSame(route('notifications.index'), $push->sent[0]['message']['url']);
    }

    public function test_nothing_is_sent_when_push_is_not_configured(): void
    {
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);

        $this->assertSame(0, $this->sender()->sendToUser($user, self::PAYLOAD, NotificationCategory::LeaveUpdates, false));
    }

    private function sender(): WebPushSender
    {
        return $this->app->make(WebPushSender::class);
    }
}
