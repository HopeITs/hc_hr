<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ScheduleMode;
use App\Livewire\Dashboard\ClockCard;
use App\Models\City;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Dashboard\WorkdayClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The big clock's working day (ported from media-request's big clock, with
 * the day read from the viewer's own contract): a bar of how far through the
 * day it is, time at work (breaks not counted), the hours, and the holiday's
 * name on an official holiday. The browser keeps it current (clock.js,
 * workProgress); the server draws the first frame.
 */
class ClockWorkdayProgressTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_progress_counts_working_minutes_only(): void
    {
        $day = ['date' => '2026-10-29', 'off' => false, 'periods' => [[540, 780], [900, 1080]], 'holiday' => null];

        $this->assertSame(['done' => 0, 'total' => 420, 'pct' => 0.0], WorkdayClock::progress($day, 480));
        $this->assertSame(['done' => 120, 'total' => 420, 'pct' => 28.6], WorkdayClock::progress($day, 660));
        // During the break the bar stands still.
        $this->assertSame(240, WorkdayClock::progress($day, 800)['done']);
        $this->assertSame(240, WorkdayClock::progress($day, 899)['done']);
        $this->assertSame(['done' => 420, 'total' => 420, 'pct' => 100.0], WorkdayClock::progress($day, 1200));

        $this->assertNull(WorkdayClock::progress(['off' => true] + $day, 600));
        $this->assertNull(WorkdayClock::progress(null, 600));
        $this->assertSame('09:00–13:00 · 15:00–18:00', WorkdayClock::hours($day));
    }

    public function test_the_big_clock_follows_the_day(): void
    {
        // Sunday to Thursday, 09:00 to 16:30. 2026-10-29 is a Thursday.
        $this->actingAs($this->employeeWithSchedule());

        foreach ([
            '07:40' => [__('dashboard_clock.work.not-started'), 'aria-valuenow="0"'],
            '11:15' => [__('dashboard_clock.work.done', ['time' => '2:15']), 'aria-valuenow="30"'],
            '16:30' => [__('dashboard_clock.work.over'), 'aria-valuenow="100"'],
        ] as $time => [$words, $bar]) {
            Carbon::setTestNow(Carbon::parse('2026-10-29 '.$time, WorkdayClock::TIMEZONE));

            Livewire::test(ClockCard::class)
                ->assertSeeHtml('data-clock-work')
                ->assertSee($words)
                ->assertSeeHtml($bar)
                ->assertSee('09:00–16:30');
        }
    }

    public function test_a_day_off_has_no_bar_and_a_holiday_is_named(): void
    {
        $this->actingAs($this->employeeWithSchedule());

        // Friday: off, no bar, nothing to name.
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00', WorkdayClock::TIMEZONE));
        Livewire::test(ClockCard::class)
            ->assertSee(__('dashboard.clock.off'))
            ->assertDontSeeHtml('role="progressbar"')
            ->assertDontSeeHtml('data-clock-holiday');

        // Wednesday, an official holiday.
        Holiday::query()->create([
            'name_en' => 'Teachers Day', 'name_ar' => 'عيد المعلم', 'start_date' => '2026-10-28', 'end_date' => '2026-10-28',
            'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', WorkdayClock::TIMEZONE));

        Livewire::test(ClockCard::class)
            ->assertSee(__('dashboard.clock.off'))
            ->assertSee(__('dashboard_clock.work.holiday', ['name' => 'Teachers Day']))
            ->assertDontSeeHtml('role="progressbar"');

        app()->setLocale('ar');
        Livewire::test(ClockCard::class)->assertSee('عطلة رسمية: عيد المعلم');
    }

    public function test_without_a_schedule_there_is_no_working_day_but_presence_still_shows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));
        $this->actingAs($this->makeUser($this->city, 'employee'));

        Livewire::test(ClockCard::class)
            ->assertDontSeeHtml('data-clock-work')
            ->assertSeeHtml('data-clock-presence');
    }

    public function test_the_phone_and_big_clock_styles_ship_with_the_card(): void
    {
        // @assets travel beside the HTML, not in it: read them from the view.
        $view = file_get_contents(resource_path('views/livewire/dashboard/clock-card.blade.php'));

        // One row, digital, on a phone (the saved size and look are untouched)...
        $this->assertStringContainsString('.hc-tile[data-key="clock"] { grid-row: span 1 !important; }', $view);
        $this->assertStringContainsString('.hc-tile[data-look="analog"] .hc-clock-digital { display: flex; }', $view);
        // ...and the bar only moves smoothly for those who have not asked for less motion.
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $view);
        // Identity only: no colour but the brand variables.
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,6}\b|rgb\(/i', $view);
    }

    private function employeeWithSchedule(array $overrides = []): User
    {
        $user = $this->makeUser($this->city, 'employee');

        $this->makeContractFor($user->profile, array_merge([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '16:30:00',
        ], $overrides));

        return $user;
    }
}
