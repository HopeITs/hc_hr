<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Livewire\Settings\NotificationSettings;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The notification settings page (settings.notifications): autosaved
 * preferences, the device list (only ever the signed-in user's own) and the
 * test send. Browser-only state (permission, this browser's subscription) is
 * pushDevice's, so it is checked here only for its contract with the markup.
 */
class NotificationSettingsPageTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_the_page_renders_for_a_signed_in_user(): void
    {
        $user = $this->user();
        $this->device($user);

        $html = $this->actingAs($user)->get(route('settings.notifications'))
            ->assertOk()
            ->assertSee(__('notification-settings.title'))
            ->assertSee(__('notification-settings.general.master.label'))
            ->assertSee(__('notification-settings.matrix.none'))
            ->assertSee('Work laptop')
            ->assertSee('x-data="pushDevice"', false)
            ->assertSee('push-device-changed.window', false)
            ->getContent();

        foreach (NotificationCategory::cases() as $category) {
            $this->assertStringContainsString(e($category->label()), $html);
        }
        foreach (NotificationChannel::cases() as $channel) {
            $this->assertStringContainsString(e($channel->label()), $html);
        }
        // Only security is urgent, and it is marked.
        $this->assertStringContainsString(e(__('notification-settings.matrix.urgent')), $html);
    }

    public function test_the_page_reads_in_arabic(): void
    {
        $user = $this->user();
        app()->setLocale('ar');

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->assertOk()
            ->assertSee('الإشعارات')
            ->assertSee('ساعات الهدوء والجدول')
            ->assertSee('طلبات إجازة بانتظارك')
            ->assertSee('لا أجهزة بعد');
    }

    public function test_every_key_used_by_the_enums_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach (NotificationCategory::cases() as $category) {
                $this->assertNotSame('notification-settings.categories.'.$category->value.'.label', $category->label());
                $this->assertNotSame('notification-settings.categories.'.$category->value.'.description', $category->description());
            }
            foreach (NotificationChannel::cases() as $channel) {
                $this->assertNotSame('notification-settings.channels.'.$channel->value.'.label', $channel->label());
            }
            $this->assertNotSame('notification-settings.menu', __('notification-settings.menu'));
        }
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('settings.notifications'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_master_switch_pauses_without_touching_the_rest(): void
    {
        $user = $this->user();

        $component = Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('prefs.email', true)
            ->set('matrix.files.email', true)
            ->set('prefs.desktop', false)
            ->set('prefs.enabled', false)
            ->assertSet('saveState', 'saved')
            ->assertSee(__('notification-settings.general.master.paused'));

        $saved = NotificationPreference::for($user->fresh());
        $this->assertTrue($saved->exists);
        $this->assertFalse($saved->enabled);
        $this->assertTrue($saved->email);
        $this->assertFalse($saved->desktop);
        $this->assertTrue($saved->categoryChannels(NotificationCategory::Files)['email']);

        $component->set('prefs.enabled', true)
            ->assertSet('prefs.email', true)
            ->assertSet('prefs.desktop', false)
            ->assertSet('matrix.files.email', true)
            ->assertDontSee(__('notification-settings.general.master.paused'));

        $saved = NotificationPreference::for($user->fresh());
        $this->assertTrue($saved->enabled);
        $this->assertTrue($saved->email);
        $this->assertFalse($saved->desktop);
        $this->assertTrue($saved->categoryChannels(NotificationCategory::Files)['email']);
    }

    public function test_a_matrix_cell_persists(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('matrix.leave_updates.mobile', false)
            ->assertHasNoErrors()
            ->assertSet('saveState', 'saved');

        $matrix = NotificationPreference::for($user->fresh())->matrix();
        $this->assertFalse($matrix['leave_updates']['mobile']);
        $this->assertTrue($matrix['leave_updates']['in_app']);
        $this->assertTrue($matrix['leave_actions']['mobile']);
    }

    public function test_unknown_matrix_cells_are_refused(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('matrix.payroll.email', true)
            ->assertHasErrors('matrix')
            ->assertSet('saveState', 'error');

        $this->assertFalse($user->notificationPreference()->exists());
    }

    public function test_schedule_values_persist(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('prefs.quiet_hours', true)
            ->set('prefs.quiet_start', '21:30')
            ->set('prefs.quiet_end', '06:15')
            ->set('prefs.timezone', 'Asia/Damascus')
            ->set('prefs.frequency', 'daily')
            ->set('prefs.digest_time', '08:00')
            ->set('workingDays', ['0', '1', '2', '3', '4'])
            ->assertHasNoErrors();

        $saved = NotificationPreference::for($user->fresh());
        $this->assertTrue($saved->quiet_hours);
        $this->assertSame('21:30', $saved->quiet_start);
        $this->assertSame('06:15', $saved->quiet_end);
        $this->assertSame('Asia/Damascus', $saved->timezone);
        $this->assertSame('daily', $saved->frequency);
        $this->assertSame('08:00', $saved->digest_time);
        $this->assertSame([0, 1, 2, 3, 4], $saved->workingDays());
    }

    public function test_invalid_values_are_rejected_and_put_back(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('prefs.timezone', 'Mars/Olympus_Mons')
            ->assertHasErrors('prefs.timezone')
            ->assertSet('prefs.timezone', '')
            ->set('prefs.frequency', 'weekly')
            ->assertHasErrors('prefs.frequency')
            ->assertSet('prefs.frequency', 'instant')
            ->set('prefs.quiet_start', '25:00')
            ->assertHasErrors('prefs.quiet_start')
            ->assertSet('prefs.quiet_start', '22:00')
            ->set('prefs.digest_time', '9am')
            ->assertHasErrors('prefs.digest_time')
            ->set('workingDays', [])
            ->assertHasErrors('workingDays')
            ->set('workingDays', ['1', '9'])
            ->assertHasErrors('workingDays')
            ->assertSet('saveState', 'error');

        $this->assertFalse($user->notificationPreference()->exists());
    }

    public function test_devices_can_be_renamed_toggled_and_removed(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->call('renameDevice', $device->id, '  Office PC  ')
            ->assertReturned(true)
            ->call('setDeviceEnabled', $device->id, false)
            ->call('setDeviceUrgentOnly', $device->id, true)
            ->assertSee('Office PC')
            ->assertSee(__('notification-settings.devices.state.off'));

        $device->refresh();
        $this->assertSame('Office PC', $device->device_name);
        $this->assertFalse($device->enabled);
        $this->assertTrue($device->urgent_only);

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->call('renameDevice', $device->id, str_repeat('x', 81))
            ->assertReturned(false)
            ->assertHasErrors('device.'.$device->id)
            ->call('removeDevice', $device->id)
            ->assertDispatched('push-device-removed', id: $device->id)
            ->assertSee(__('notification-settings.devices.empty-title'));

        $this->assertModelMissing($device);
    }

    public function test_another_users_device_cannot_be_touched(): void
    {
        $owner = $this->user('owner');
        $device = $this->device($owner, ['device_name' => 'Owner phone']);
        $intruder = $this->user('intruder');

        Livewire::actingAs($intruder)->test(NotificationSettings::class)->assertDontSee('Owner phone');

        // A 404 ends a test component's life, so each attempt gets a fresh page.
        $attempts = [
            ['renameDevice', $device->id, 'Mine now'],
            ['setDeviceEnabled', $device->id, false],
            ['setDeviceUrgentOnly', $device->id, true],
            ['removeDevice', $device->id],
            ['testDevice', $device->id],
            ['sendTest', $device->id, 'device'],
        ];

        foreach ($attempts as $attempt) {
            Livewire::actingAs($intruder)->test(NotificationSettings::class)->call(...$attempt)->assertNotFound();
        }

        $device->refresh();
        $this->assertSame('Owner phone', $device->device_name);
        $this->assertTrue($device->enabled);
        $this->assertFalse($device->urgent_only);
    }

    public function test_the_test_notification_calls_the_tester_and_shows_its_answer(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        $this->mock(NotificationTester::class, function (MockInterface $mock) use ($user, $device) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($u, $target, $id) => $u->is($user) && $target === 'email' && $id === null)
                ->andReturn(['ok' => true, 'message' => 'Sent to the mail server.']);
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($u, $target, $id) => $u->is($user) && $target === 'device' && $id === $device->id)
                ->andReturn(['ok' => false, 'message' => 'The push service refused it (410).']);
        });

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->set('testTarget', 'email')
            ->call('sendTest')
            ->assertSet('testResult', ['ok' => true, 'message' => 'Sent to the mail server.'])
            ->assertSee('Sent to the mail server.')
            ->assertSee(__('notification-settings.test.sent'))
            ->call('sendTest', $device->id, 'device')
            ->assertSet('testResult.ok', false)
            ->assertSee('The push service refused it (410).')
            ->assertSee(__('notification-settings.test.not-sent'));
    }

    public function test_a_device_test_without_this_browsers_subscription_is_not_sent(): void
    {
        $user = $this->user();

        $this->mock(NotificationTester::class, fn (MockInterface $mock) => $mock->shouldNotReceive('send'));

        Livewire::actingAs($user)->test(NotificationSettings::class)
            ->call('sendTest', null, 'device')
            ->assertSet('testResult', ['ok' => false, 'message' => __('notification-settings.test.no-device')])
            ->call('sendTest', null, 'carrier-pigeon')
            ->assertSet('testResult.ok', false);
    }

    private function user(string $username = 'employee'): User
    {
        static $cities = 0;
        $cities++;

        return $this->makeUser($this->makeCity('ALP'.$cities, str_pad((string) $cities, 3, '0', STR_PAD_LEFT)), $username.'-'.uniqid());
    }

    private function device(User $user, array $attributes = []): PushSubscription
    {
        $endpoint = 'https://push.example.test/'.uniqid();

        return $user->pushSubscriptions()->create(array_merge([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => 'key',
            'auth_token' => 'auth',
            'device_name' => 'Work laptop',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'last_seen_at' => now(),
        ], $attributes));
    }
}
