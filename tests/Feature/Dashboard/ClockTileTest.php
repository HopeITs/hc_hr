<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ScheduleMode;
use App\Livewire\Dashboard\ClockCard;
use App\Livewire\Dashboard\Index;
use App\Models\City;
use App\Models\Contract;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\WorkdayClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The clock tile (prompt 5): Damascus time for everyone, and the HR line from
 * the viewer's active contract, only when its schedule is known. The ticking
 * and the looks are browser side (resources/js/dashboard/clock.js, tested in
 * tests/js/clock.test.mjs).
 */
class ClockTileTest extends TestCase
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

    public function test_the_tile_is_available_to_everyone(): void
    {
        $layout = app(DashboardLayout::class);

        foreach ([
            $this->makeUser($this->city, 'employee'),
            $this->makeUser($this->city, 'manager'),
            $this->makeUser($this->city, 'HR-ALP'),
            $this->makeUser($this->city, 'super-admin'),
            User::factory()->create(['employee_id' => null]),
        ] as $user) {
            $this->assertTrue($layout->allowed($user, 'clock'), (string) $user->myRole());
            $this->assertContains('clock', $layout->available($user));
        }

        $this->assertSame(ClockCard::class, $layout->component('clock'));
    }

    public function test_the_page_draws_the_clock_once_it_is_added(): void
    {
        $this->actingAs($this->makeUser($this->city, 'employee'));

        Livewire::test(Index::class)
            ->call('saveLayout', [['key' => 'clock', 'size' => '3x3', 'pinned' => false, 'hidden' => false, 'style' => 'analog']]);

        // saveLayout is renderless: the next visit draws it.
        $html = Livewire::test(Index::class)->html();

        $this->assertMatchesRegularExpression('/<div class="hc-tile[^"]*"[^>]*\sdata-key="clock"/', $html);
    }

    public function test_it_shows_damascus_time_whatever_the_server_clock_says(): void
    {
        // 04:40 UTC is 07:40 in Damascus.
        Carbon::setTestNow(Carbon::parse('2026-10-29 04:40:00', 'UTC'));
        $this->actingAs($this->makeUser($this->city, 'employee'));

        Livewire::test(ClockCard::class)
            ->assertSee('07:40')
            ->assertSee('Thursday')
            ->assertSee('29 October 2026');
    }

    public function test_arabic_names_the_day_and_month_with_latin_digits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 07:40:00', WorkdayClock::TIMEZONE));
        app()->setLocale('ar');
        $this->actingAs($this->makeUser($this->city, 'employee'));

        Livewire::test(ClockCard::class)
            ->assertSee('07:40')
            ->assertSee('الخميس')
            ->assertSee('29 تشرين الأول 2026')
            ->assertSee('ar-SY-u-nu-latn');
    }

    public function test_no_hr_line_without_an_active_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));
        $this->actingAs($this->makeUser($this->city, 'employee'));

        Livewire::test(ClockCard::class)
            ->assertSee('10:00')
            ->assertDontSeeHtml('data-clock-line');
    }

    public function test_no_hr_line_when_the_schedule_is_not_known(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));

        foreach ([
            'flexible' => ['schedule_mode' => ScheduleMode::Flexible],
            'unknown' => ['schedule_mode' => ScheduleMode::Unknown],
            'no day range' => ['work_day_from' => null, 'work_day_to' => null],
            'no hours' => ['work_hours_from' => null, 'work_hours_to' => null],
            'half a second period' => ['work_hours_2_from' => '17:00'],
        ] as $case => $override) {
            $this->actingAs($this->employeeWithSchedule($override));

            Livewire::test(ClockCard::class)->assertDontSeeHtml('data-clock-line');
            $this->assertNull(app(WorkdayClock::class)->today(auth()->user()->profile->latestActiveContract()), $case);
        }
    }

    public function test_an_ended_contract_gives_no_hr_line(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));
        $this->actingAs($this->employeeWithSchedule(['end_date' => '2026-10-01']));

        Livewire::test(ClockCard::class)->assertDontSeeHtml('data-clock-line');
    }

    public function test_the_line_follows_a_known_schedule_through_the_day(): void
    {
        // Sunday to Thursday, 09:00 to 16:30. 2026-10-29 is a Thursday.
        $this->actingAs($this->employeeWithSchedule());

        foreach ([
            '07:40' => __('dashboard.clock.starts', ['time' => '1:20']),
            '09:00' => __('dashboard.clock.ends', ['time' => '7:30']),
            '14:15' => __('dashboard.clock.ends', ['time' => '2:15']),
            '16:30' => __('dashboard.clock.outside'),
            '22:00' => __('dashboard.clock.outside'),
        ] as $time => $line) {
            Carbon::setTestNow(Carbon::parse('2026-10-29 '.$time, WorkdayClock::TIMEZONE));

            Livewire::test(ClockCard::class)->assertSeeHtml('data-clock-line')->assertSee($line);
        }
    }

    public function test_a_day_outside_the_contract_range_is_a_day_off(): void
    {
        // Friday.
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00', WorkdayClock::TIMEZONE));
        $this->actingAs($this->employeeWithSchedule());

        Livewire::test(ClockCard::class)->assertSee(__('dashboard.clock.off'));
    }

    public function test_an_official_holiday_is_a_day_off(): void
    {
        // Wednesday, a working day, but a national holiday.
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', WorkdayClock::TIMEZONE));
        Holiday::query()->create([
            'name_en' => 'Holiday', 'name_ar' => 'عطلة', 'start_date' => '2026-10-28', 'end_date' => '2026-10-28',
            'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL,
        ]);
        $this->actingAs($this->employeeWithSchedule());

        Livewire::test(ClockCard::class)->assertSee(__('dashboard.clock.off'));

        // The day after is an ordinary working day again.
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));
        Livewire::test(ClockCard::class)->assertSee(__('dashboard.clock.ends', ['time' => '6:30']));
    }

    public function test_a_split_day_has_a_break_and_resumes(): void
    {
        $this->actingAs($this->employeeWithSchedule([
            'work_hours_from' => '09:00', 'work_hours_to' => '13:00',
            'work_hours_2_from' => '15:00', 'work_hours_2_to' => '18:00',
        ]));

        foreach ([
            '12:00' => __('dashboard.clock.break', ['time' => '1:00']),
            '14:00' => __('dashboard.clock.resumes', ['time' => '1:00']),
            '16:00' => __('dashboard.clock.ends', ['time' => '2:00']),
        ] as $time => $line) {
            Carbon::setTestNow(Carbon::parse('2026-10-29 '.$time, WorkdayClock::TIMEZONE));

            Livewire::test(ClockCard::class)->assertSee($line);
        }
    }

    public function test_a_range_that_wraps_the_week_is_read_as_such(): void
    {
        // Saturday to Wednesday: Thursday is off, Saturday is not.
        $this->actingAs($this->employeeWithSchedule(['work_day_from' => 6, 'work_day_to' => 3]));

        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', WorkdayClock::TIMEZONE));
        Livewire::test(ClockCard::class)->assertSee(__('dashboard.clock.off'));

        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00', WorkdayClock::TIMEZONE));
        Livewire::test(ClockCard::class)->assertSee(__('dashboard.clock.ends', ['time' => '6:30']));
    }

    public function test_the_browser_gets_the_day_not_a_guess(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 07:40:00', WorkdayClock::TIMEZONE));
        $contract = $this->contractWithSchedule();

        $this->assertSame(
            ['date' => '2026-10-29', 'off' => false, 'periods' => [[540, 990]], 'holiday' => null],
            app(WorkdayClock::class)->today($contract),
        );
    }

    /** An employee whose active contract has this schedule (Sunday to Thursday, 09:00 to 16:30 unless overridden). */
    private function employeeWithSchedule(array $overrides = []): User
    {
        $user = $this->makeUser($this->city, 'employee');
        $this->contractWithSchedule($overrides, $user);

        return $user;
    }

    private function contractWithSchedule(array $overrides = [], ?User $user = null): Contract
    {
        $user ??= $this->makeUser($this->city, 'employee');

        return $this->makeContractFor($user->profile, array_merge([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '16:30:00',
        ], $overrides));
    }
}
