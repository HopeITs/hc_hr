<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationPreferenceGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceGateTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_defaults_follow_the_category_matrix(): void
    {
        $user = $this->makeNotifiedUser();

        $this->assertTrue($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::InApp));
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::Desktop));
        $this->assertFalse($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::Email));
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::Contracts, NotificationChannel::Email));
        $this->assertFalse($this->gate()->allows($user, NotificationCategory::Organization, NotificationChannel::Mobile));
    }

    public function test_master_switch_off_blocks_every_channel(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['enabled' => false]);

        foreach (NotificationChannel::cases() as $channel) {
            $this->assertFalse($this->gate()->allows($user, NotificationCategory::Contracts, $channel), $channel->value);
        }
    }

    public function test_channel_switch_off_blocks_that_channel_only(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['desktop' => false]);

        $this->assertFalse($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::Desktop));
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::Mobile));
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::InApp));
    }

    public function test_category_cell_off_blocks_that_category_only(): void
    {
        $user = $this->makeNotifiedUser();
        $preference = NotificationPreference::for($user);
        $preference->setCategoryChannel(NotificationCategory::Files, NotificationChannel::InApp, false);
        $preference->save();

        $this->assertFalse($this->gate()->allows($user, NotificationCategory::Files, NotificationChannel::InApp));
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::Checklists, NotificationChannel::InApp));
    }

    public function test_master_switch_back_on_restores_the_previous_choices(): void
    {
        $user = $this->makeNotifiedUser();
        $preference = NotificationPreference::for($user);
        $preference->email = true;
        $preference->desktop = false;
        $preference->setCategoryChannel(NotificationCategory::Files, NotificationChannel::Email, true);
        $preference->save();

        $this->setPreference($user, ['enabled' => false]);
        $this->assertFalse($this->gate()->allows($user, NotificationCategory::Files, NotificationChannel::Email));

        $this->setPreference($user, ['enabled' => true]);
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::Files, NotificationChannel::Email));
        $this->assertFalse($this->gate()->allows($user, NotificationCategory::Files, NotificationChannel::Desktop));
    }

    public function test_instant_outside_quiet_hours_is_now(): void
    {
        $user = $this->makeNotifiedUser();
        $now = CarbonImmutable::parse('2026-11-03 10:15:00', config('app.timezone'));

        $this->assertTrue($this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, $now)->equalTo($now));
    }

    public function test_quiet_hours_wrapping_midnight_hold_until_they_end(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'UTC',
        ]);

        // Late evening: held until 07:00 the next morning.
        $late = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 23:30', 'UTC'));
        $this->assertSame('2026-11-04 07:00', $late->setTimezone('UTC')->format('Y-m-d H:i'));

        // Early morning: held until 07:00 the same day.
        $early = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-04 03:00', 'UTC'));
        $this->assertSame('2026-11-04 07:00', $early->setTimezone('UTC')->format('Y-m-d H:i'));

        // Daytime: now.
        $day = CarbonImmutable::parse('2026-11-04 12:00', 'UTC');
        $this->assertTrue($this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, $day)->equalTo($day));
    }

    public function test_quiet_hours_within_one_day(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'quiet_hours' => true, 'quiet_start' => '13:00', 'quiet_end' => '15:30', 'timezone' => 'UTC',
        ]);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 14:00', 'UTC'));

        $this->assertSame('2026-11-03 15:30', $at->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_quiet_hours_are_read_in_the_persons_time_zone(): void
    {
        $user = $this->makeNotifiedUser();
        // Damascus is UTC+3: 20:30 UTC is 23:30 there, inside 22:00-07:00.
        $this->setPreference($user, [
            'quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'Asia/Damascus',
        ]);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 20:30', 'UTC'));

        $this->assertSame('2026-11-04 07:00', $at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-04 04:00', $at->setTimezone('UTC')->format('Y-m-d H:i'));
        $this->assertSame(config('app.timezone'), $at->getTimezone()->getName());
    }

    public function test_non_working_days_move_to_the_next_working_day(): void
    {
        $user = $this->makeNotifiedUser();
        // Sunday to Thursday; 2026-11-06 is a Friday.
        $this->setPreference($user, ['working_days' => [0, 1, 2, 3, 4], 'timezone' => 'UTC']);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-06 10:00', 'UTC'));

        $this->assertSame('2026-11-08 00:00', $at->setTimezone('UTC')->format('Y-m-d H:i'));
        $this->assertSame(0, $at->setTimezone('UTC')->dayOfWeek);
    }

    public function test_next_working_day_also_waits_for_quiet_hours_to_end(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'working_days' => [0, 1, 2, 3, 4], 'timezone' => 'UTC',
            'quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00',
        ]);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-06 10:00', 'UTC'));

        $this->assertSame('2026-11-08 07:00', $at->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_hourly_digest_goes_at_the_next_top_of_the_hour(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['frequency' => 'hourly', 'timezone' => 'UTC']);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 10:20:15', 'UTC'));

        $this->assertSame('2026-11-03 11:00:00', $at->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_daily_digest_goes_at_the_digest_time(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['frequency' => 'daily', 'digest_time' => '09:00', 'timezone' => 'UTC']);

        $before = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 08:00', 'UTC'));
        $after = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 10:00', 'UTC'));

        $this->assertSame('2026-11-03 09:00', $before->setTimezone('UTC')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-04 09:00', $after->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_daily_digest_inside_quiet_hours_waits_for_them_to_end(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'frequency' => 'daily', 'digest_time' => '06:00', 'timezone' => 'UTC',
            'quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00',
        ]);

        $at = $this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, CarbonImmutable::parse('2026-11-03 10:00', 'UTC'));

        $this->assertSame('2026-11-04 07:00', $at->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_urgent_category_bypasses_quiet_hours_working_days_and_digest(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'frequency' => 'daily', 'working_days' => [1], 'timezone' => 'UTC',
            'quiet_hours' => true, 'quiet_start' => '00:00', 'quiet_end' => '23:59',
        ]);
        $now = CarbonImmutable::parse('2026-11-06 03:00', 'UTC');

        $this->assertTrue($this->gate()->deliverAt($user, NotificationCategory::Security, $now)->equalTo($now));
        $this->assertTrue($this->gate()->deliverAt($user, NotificationCategory::LeaveUpdates, $now)->greaterThan($now));
    }

    public function test_urgent_bypass_off_holds_urgent_ones_too(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, [
            'urgent_bypass' => false, 'timezone' => 'UTC',
            'quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00',
        ]);

        $at = $this->gate()->deliverAt($user, NotificationCategory::Security, CarbonImmutable::parse('2026-11-03 23:00', 'UTC'));

        $this->assertSame('2026-11-04 07:00', $at->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_a_saved_change_is_seen_within_the_same_request(): void
    {
        $user = $this->makeNotifiedUser();
        $this->assertTrue($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::InApp));

        $this->setPreference($user, ['in_app' => false]);

        $this->assertFalse($this->gate()->allows($user, NotificationCategory::LeaveUpdates, NotificationChannel::InApp));
    }

    private function gate(): NotificationPreferenceGate
    {
        return $this->app->make(NotificationPreferenceGate::class);
    }
}
