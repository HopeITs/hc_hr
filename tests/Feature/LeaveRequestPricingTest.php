<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\ScheduleMode;
use App\Models\City;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Program;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveQuote;
use App\Support\Leave\LeaveWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Fixtures F1–F20 of docs/ux/leave-calculation-contract.md §7.
 *
 * Center A: weekend Fri+Sat (5,6). Center B: weekend Sat+Sun (0,6).
 * Full-time 40 h/week unless stated. Dates are November/December 2026
 * (1 Nov 2026 is a Sunday). "Today" is frozen at 2026-10-21.
 */
class LeaveRequestPricingTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestPricing $pricing;

    private int $nationalCityId;

    private int $cityId;

    private int $otherCityId;

    private int $programId;

    private int $centerA;

    private int $centerB;

    private int $departmentId;

    private int $jobLevelId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->pricing = new LeaveRequestPricing;

        $this->nationalCityId = $this->insert('cities', ['name_en' => 'Syria', 'name_ar' => 'سوريا', 'slug' => City::NATIONAL_SLUG, 'code' => '000']);
        $this->cityId = $this->insert('cities', ['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $this->otherCityId = $this->insert('cities', ['name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001']);
        $this->programId = $this->insert('programs', ['name' => 'Program', 'code' => 'PRG', 'slug' => 'program']);

        $this->centerA = $this->insert('centers', [
            'name_en' => 'Center A', 'name_ar' => 'المركز أ', 'weekends' => '5,6',
            'open_at' => '08:00:00', 'close_at' => '17:00:00', 'city_id' => $this->cityId,
        ]);
        $this->centerB = $this->insert('centers', [
            'name_en' => 'Center B', 'name_ar' => 'المركز ب', 'weekends' => '0,6',
            'open_at' => '08:00:00', 'close_at' => '17:00:00', 'city_id' => $this->cityId,
        ]);

        $this->departmentId = $this->insert('departments', ['name' => 'Department', 'code' => 'DEP']);
        $this->jobLevelId = $this->insert('job_levels', ['name_en' => 'Officer', 'name_ar' => 'موظف']);

        foreach ([1 => 'Annual', 3 => 'Trial', 5 => 'Service agreement'] as $id => $name) {
            DB::table('contract_types')->insert(['id' => $id, 'name_en' => $name, 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ([
            LeaveTypeCases::Annual => 0,
            LeaveTypeCases::FullSick => 3,
            LeaveTypeCases::MaternityFirst => 120,
        ] as $id => $baseDays) {
            DB::table('leave_types')->insert(['id' => $id, 'name_en' => 'Type '.$id, 'name_ar' => 'نوع '.$id, 'base_days' => $baseDays, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // --- §7 fixtures ----------------------------------------------------------

    public function test_f1_center_a_sunday_to_thursday_is_five_days(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-05'));

        $this->assertSame(5, $quote->countedDays);
        $this->assertSame(40.0, $quote->requestedHours);
        $this->assertSame(5.0, $quote->requestedDaysDisplay);
        $this->assertSame(8.0, $quote->hoursPerDay);
        $this->assertSame([], $quote->excludedDates);
        $this->assertSame([], $quote->errors);
        $this->assertSame([], $quote->warnings);
    }

    public function test_whole_days_survive_a_repeating_day_length(): void
    {
        // 40 h over a six-day week: 6.6667 h a day, five days stored as 33.33 h.
        $perDay = 40 / 6;

        $this->assertSame([5, 0.0], LeaveRequestPricing::splitDays(33.33, $perDay));
        $this->assertSame([5, 0.0], LeaveRequestPricing::splitDays(33.34, $perDay));
        $this->assertSame([4, 3.0], LeaveRequestPricing::splitDays(29.67, $perDay));
        // 17.34 h = two days + 4.0067 h: four hours, not "4 hours and 1 minute".
        $this->assertSame([2, 4.0], LeaveRequestPricing::splitDays(17.34, $perDay));
        $this->assertSame([0, 2.6667], LeaveRequestPricing::splitDays(2.67, $perDay));
        $this->assertSame([0, 2.5], LeaveRequestPricing::splitDays(2.5, $perDay));
        $this->assertSame([0, 12.5], LeaveRequestPricing::splitDays(12.5, null));
    }

    public function test_f2_center_a_skips_friday_and_saturday(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-08'));

        $this->assertSame(6, $quote->countedDays);
        $this->assertSame(48.0, $quote->requestedHours);
        $this->assertSame([
            ['date' => '2026-11-06', 'reason' => 'weekend'],
            ['date' => '2026-11-07', 'reason' => 'weekend'],
        ], $quote->excludedDates);
        $this->assertFalse($quote->hasErrors());
    }

    public function test_f3_center_b_monday_to_monday_is_six_days(): void
    {
        [$contract, $balance] = $this->annual('B');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-02', '2026-11-09'));

        $this->assertSame(6, $quote->countedDays);
        $this->assertSame(48.0, $quote->requestedHours);
        $this->assertSame(['2026-11-07', '2026-11-08'], array_column($quote->excludedDates, 'date'));
    }

    public function test_f4_a_national_holiday_inside_the_range_is_skipped(): void
    {
        [$contract, $balance] = $this->annual('A');
        $this->holiday('2026-11-04');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-05'));

        $this->assertSame(4, $quote->countedDays);
        $this->assertSame(32.0, $quote->requestedHours);
        $this->assertSame([['date' => '2026-11-04', 'reason' => 'holiday']], $quote->excludedDates);
        $this->assertTrue($quote->hasWarning(LeaveQuote::HOLIDAY_INSIDE));
        $this->assertFalse($quote->hasErrors());
    }

    public function test_f5_a_weekend_only_range_has_no_countable_days(): void
    {
        [$contract, $balance] = $this->annual('B');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-07', '2026-11-08'));

        $this->assertSame(0, $quote->countedDays);
        $this->assertSame(0.0, $quote->requestedHours);
        $this->assertSame([LeaveQuote::NO_COUNTABLE_DAYS], $quote->errors);
    }

    public function test_f6_hourly_leave_is_exact(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '12:20'));

        $this->assertSame(3.33, $quote->requestedHours);
        $this->assertSame(1, $quote->countedDays);
        $this->assertFalse($quote->hasErrors());
    }

    public function test_f7_exactly_four_hours_stays_four_hours(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '13:00'));

        $this->assertSame(4.0, $quote->requestedHours);
        $this->assertFalse($quote->hasErrors());
    }

    public function test_f8_full_time_over_four_hours_is_one_full_day(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '13:15'));

        $this->assertSame(8.0, $quote->requestedHours);
        $this->assertSame(1.0, $quote->requestedDaysDisplay);
        $this->assertFalse($quote->hasErrors());
        $this->assertContains('leave_quote.explain.hours_promoted', array_column($quote->explanation, 'key'));
    }

    public function test_f9_part_time_hourly_is_never_promoted_to_a_day(): void
    {
        [$contract, $balance] = $this->annual('B', weeklyHours: 20);

        $three = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '12:00'));
        $five = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '14:00'));

        $this->assertSame(4.0, $three->hoursPerDay);
        $this->assertSame(3.0, $three->requestedHours);
        $this->assertSame(5.0, $five->requestedHours);
        $this->assertFalse($three->hasErrors());
        $this->assertFalse($five->hasErrors());
    }

    public function test_f10_six_day_schedule_divides_by_six(): void
    {
        $friday = $this->insert('centers', [
            'name_en' => 'Friday only', 'name_ar' => 'الجمعة فقط', 'weekends' => '5', 'city_id' => $this->cityId,
        ]);
        [$contract, $balance] = $this->annual($friday, weeklyHours: 48);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-03'));

        $this->assertSame(8.0, $quote->hoursPerDay);
        $this->assertSame(3, $quote->countedDays);
        $this->assertSame(24.0, $quote->requestedHours);
    }

    public function test_f11_maternity_counts_calendar_days(): void
    {
        $contract = $this->contract('B');
        $balance = $this->balance($contract, LeaveTypeCases::MaternityFirst, deserved: 960);
        $this->holiday('2026-11-04');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-02', '2026-12-01'));

        $this->assertTrue($quote->calendarDays);
        $this->assertSame(30, $quote->countedDays);
        $this->assertSame(240.0, $quote->requestedHours);
        $this->assertSame([], $quote->excludedDates);
        $this->assertFalse($quote->hasWarning(LeaveQuote::HOLIDAY_INSIDE));
        // The range ends on 1 December, so D-7 applies to it like any type.
        $this->assertSame([LeaveQuote::DECEMBER_RESTRICTED], $quote->errors);
    }

    public function test_f12_pending_requests_reserve_balance(): void
    {
        [$contract, $balance] = $this->annual('A', deserved: 96, used: 16);
        // Legacy-priced pending request (no snapshot): Sun 15 – Tue 17 Nov = 3 × 8 h.
        $this->leaveRequest($balance, '2026-11-15', '2026-11-17');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-04'));

        $this->assertSame(80.0, $quote->availableHours);
        $this->assertSame(24.0, $quote->pendingHours);
        $this->assertSame(32.0, $quote->requestedHours);
        $this->assertSame(24.0, $quote->projectedHours);
        $this->assertTrue($quote->hasWarning(LeaveQuote::PENDING_RESERVED));
        $this->assertFalse($quote->hasErrors());
    }

    public function test_f13_annual_over_balance_is_an_error(): void
    {
        [$contract, $balance] = $this->annual('A', deserved: 96, used: 16);
        $this->leaveRequest($balance, '2026-11-15', '2026-11-17');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-10'));

        $this->assertSame(64.0, $quote->requestedHours);
        $this->assertSame(-8.0, $quote->projectedHours);
        $this->assertSame([LeaveQuote::EXCEEDS_BALANCE], $quote->errors);
        $this->assertFalse($quote->hasWarning(LeaveQuote::EXCEEDS_BALANCE));
    }

    public function test_f14_zero_weekly_hours_is_schedule_unknown(): void
    {
        [$contract, $balance] = $this->annual('A', weeklyHours: 0);

        $days = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-02'));
        $hours = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '10:00'));

        $this->assertTrue($days->hasError(LeaveQuote::SCHEDULE_UNKNOWN));
        $this->assertSame(0.0, $days->requestedHours);
        $this->assertNull($days->hoursPerDay);
        $this->assertTrue($hours->hasError(LeaveQuote::SCHEDULE_UNKNOWN));
    }

    public function test_f15_edit_of_an_accepted_request_prices_the_difference(): void
    {
        [$contract, $balance] = $this->annual('A', used: 16);
        $accepted = $this->leaveRequest($balance, '2026-11-01', '2026-11-02', status: LeaveRequestStatus::Accepted, computedHours: 16);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-03'), $accepted);

        $this->assertSame(24.0, $quote->requestedHours);
        $this->assertSame(8.0, $quote->requestedHours - $accepted->computed_hours);
        // An accepted request is in `used`, never in pending.
        $this->assertSame(0.0, $quote->pendingHours);
        $this->assertSame(16.0, (float) $balance->fresh()->used);
    }

    public function test_f15_the_request_being_edited_is_left_out_of_pending(): void
    {
        [$contract, $balance] = $this->annual('A');
        $pending = $this->leaveRequest($balance, '2026-11-01', '2026-11-02', computedHours: 16);

        $this->assertSame(16.0, $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-03'))->pendingHours);
        $this->assertSame(0.0, $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-03'), $pending)->pendingHours);
    }

    public function test_f16_historical_requests_keep_the_legacy_rule_and_used_is_untouched(): void
    {
        [$contract, $balance] = $this->annual('B', used: 8);
        // Weekend-only range: the legacy rule priced it as one day (finding 3).
        $old = $this->leaveRequest($balance, '2026-11-07', '2026-11-08', status: LeaveRequestStatus::Accepted);

        $this->assertSame(8.0, $this->pricing->quoteLegacy($old));
        $this->assertSame($this->legacyAccessor($old), $this->pricing->quoteLegacy($old));

        $this->quote($contract, $balance, LeaveWindow::days('2026-11-02', '2026-11-03'));

        $this->assertSame(8.0, (float) $balance->fresh()->used);
        $this->assertNull($old->fresh()->computed_hours);
    }

    public function test_quote_legacy_matches_the_accessor_on_center_weekends(): void
    {
        [, $balance] = $this->annual('A');
        $day = $this->leaveRequest($balance, '2026-11-01', '2026-11-08');
        $hour = $this->leaveRequest($balance, '2026-11-03 09:00', '2026-11-03 11:30', type: 'hour');

        $this->assertSame($this->legacyAccessor($day), $this->pricing->quoteLegacy($day));
        $this->assertSame(48.0, $this->pricing->quoteLegacy($day));
        $this->assertSame(2.5, $this->pricing->quoteLegacy($hour));
    }

    public function test_f17_irregular_part_time_gets_hourly_requests_only(): void
    {
        [$contract, $balance] = $this->annual('B', weeklyHours: 20, scheduleMode: ScheduleMode::Flexible);

        $days = $this->quote($contract, $balance, LeaveWindow::days('2026-11-02', '2026-11-03'));
        $hours = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '14:00'));

        $this->assertFalse($this->pricing->offersDayRequests($contract));
        $this->assertSame([LeaveQuote::SCHEDULE_UNKNOWN], $days->errors);
        $this->assertSame(0.0, $days->requestedHours);
        $this->assertNull($days->hoursPerDay);
        $this->assertFalse($hours->hasErrors());
        $this->assertSame(5.0, $hours->requestedHours);
    }

    public function test_f18_service_agreement_has_no_leave_entitlement(): void
    {
        $contract = $this->contract('A', contractTypeId: 5);
        $balance = $this->balance($contract, LeaveTypeCases::Annual);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-02'));

        $this->assertSame([LeaveQuote::NO_LEAVE_ENTITLEMENT], $quote->errors);
        $this->assertSame(0.0, $quote->requestedHours);
        $this->assertFalse($this->pricing->offersDayRequests($contract));
    }

    public function test_f19_hourly_leave_below_fifteen_minutes_is_refused(): void
    {
        [$contract, $balance] = $this->annual('A');

        $short = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '09:10'));
        $quarter = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '09:15'));

        $this->assertSame([LeaveQuote::BELOW_MINIMUM], $short->errors);
        $this->assertFalse($quarter->hasErrors());
        $this->assertSame(0.25, $quarter->requestedHours);
    }

    public function test_f20_december_needs_the_exceptional_flag(): void
    {
        [$contract, $balance] = $this->annual('A');

        $plain = $this->quote($contract, $balance, LeaveWindow::days('2026-12-15', '2026-12-15'));
        $exceptional = $this->quote($contract, $balance, LeaveWindow::days('2026-12-15', '2026-12-15', exceptional: true));

        $this->assertSame([LeaveQuote::DECEMBER_RESTRICTED], $plain->errors);
        $this->assertFalse($exceptional->hasErrors());
        $this->assertContains('leave_quote.explain.december_exceptional', array_column($exceptional->explanation, 'key'));
    }

    // --- Other rules ------------------------------------------------------------

    public function test_quote_never_changes_carbons_global_weekend_days(): void
    {
        [$contract, $balance] = $this->annual('A');
        $old = $this->leaveRequest($balance, '2026-11-01', '2026-11-05');
        $before = Carbon::getWeekendDays();

        $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-08'));
        $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '10:00'));
        $this->pricing->quoteLegacy($old);

        $this->assertSame($before, Carbon::getWeekendDays());
    }

    public function test_no_center_uses_a_center_of_the_same_program_in_the_governorate(): void
    {
        // A sibling position of the program at center A (Fri+Sat) in Homs.
        $this->position($this->centerA);
        [$contract, $balance] = $this->annual(null);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-08'));

        $this->assertSame([[5, 6], 'program'], $this->pricing->weekendFor($contract));
        $this->assertSame(6, $quote->countedDays);
        $this->assertFalse($quote->hasWarning(LeaveQuote::BALANCE_UNKNOWN_WEEKEND));
    }

    public function test_no_center_anywhere_falls_back_to_the_default_with_a_warning(): void
    {
        [$contract, $balance] = $this->annual(null);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-02', '2026-11-09'));

        $this->assertSame([[0, 6], 'default'], $this->pricing->weekendFor($contract));
        $this->assertSame(6, $quote->countedDays);
        $this->assertTrue($quote->hasWarning(LeaveQuote::BALANCE_UNKNOWN_WEEKEND));
    }

    /** §6.1: national + the holder's project (program × governorate); no city- or program-wide level. */
    public function test_national_project_and_recurring_holidays_apply_but_other_projects_do_not(): void
    {
        [$contract, $balance] = $this->annual('A');
        $this->holiday('2026-11-02', projects: [[$this->programId, $this->cityId]]);
        // Shared with another project: still applies.
        $this->holiday('2026-11-03', projects: [[$this->programId, $this->otherCityId], [$this->programId, $this->cityId]]);
        $this->holiday('2019-11-04', recurring: true);
        // Same program, other governorate: not this holder's project.
        $this->holiday('2026-11-05', projects: [[$this->programId, $this->otherCityId]]);
        // On Friday (weekend at A): listed once, as weekend.
        $this->holiday('2026-11-06');

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-07'));

        $this->assertSame(2, $quote->countedDays);
        $this->assertSame([
            ['date' => '2026-11-02', 'reason' => 'holiday'],
            ['date' => '2026-11-03', 'reason' => 'holiday'],
            ['date' => '2026-11-04', 'reason' => 'holiday'],
            ['date' => '2026-11-06', 'reason' => 'weekend'],
            ['date' => '2026-11-07', 'reason' => 'weekend'],
        ], $quote->excludedDates);
    }

    public function test_hourly_request_outside_center_hours_is_refused(): void
    {
        [$contract, $balance] = $this->annual('A');

        $early = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '07:30', '09:00'));
        $late = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '16:00', '17:30'));

        $this->assertSame([LeaveQuote::OUTSIDE_WORKING_HOURS], $early->errors);
        $this->assertSame([LeaveQuote::OUTSIDE_WORKING_HOURS], $late->errors);
    }

    public function test_hourly_request_on_a_weekend_has_no_countable_days(): void
    {
        [$contract, $balance] = $this->annual('A');

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-06', '09:00', '10:00'));

        $this->assertSame([LeaveQuote::NO_COUNTABLE_DAYS], $quote->errors);
        $this->assertSame(0.0, $quote->requestedHours);
    }

    public function test_non_annual_over_type_max_is_an_error_and_over_balance_only_a_warning(): void
    {
        $contract = $this->contract('A');
        $sick = $this->balance($contract, LeaveTypeCases::FullSick, deserved: 16);

        $quote = $this->quote($contract, $sick, LeaveWindow::days('2026-11-01', '2026-11-04'));

        $this->assertSame([LeaveQuote::EXCEEDS_TYPE_MAX], $quote->errors);
        $this->assertTrue($quote->hasWarning(LeaveQuote::EXCEEDS_BALANCE));
        $this->assertSame(-16.0, $quote->projectedHours);
    }

    public function test_probation_beyond_three_days_warns_without_an_amount(): void
    {
        $contract = $this->contract('A', contractTypeId: 3);
        $balance = $this->balance($contract, LeaveTypeCases::Annual);

        $three = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-03'));
        $four = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-04'));

        $this->assertFalse($three->hasWarning(LeaveQuote::PROBATION_EXCESS));
        $this->assertTrue($four->hasWarning(LeaveQuote::PROBATION_EXCESS));
        $this->assertFalse($four->hasErrors());
    }

    public function test_pending_uses_the_stored_snapshot_and_ignores_other_statuses(): void
    {
        [$contract, $balance] = $this->annual('A');
        $this->leaveRequest($balance, '2026-11-01', '2026-11-05', computedHours: 5.5);
        $this->leaveRequest($balance, '2026-11-08', '2026-11-09', status: LeaveRequestStatus::Rejected);
        $this->leaveRequest($balance, '2026-11-10', '2026-11-11', status: LeaveRequestStatus::Accepted);

        $quote = $this->quote($contract, $balance, LeaveWindow::days('2026-11-15', '2026-11-15'));

        $this->assertSame(5.5, $quote->pendingHours);
    }

    public function test_inverted_window_is_reported_not_thrown(): void
    {
        [$contract, $balance] = $this->annual('A');

        $days = $this->quote($contract, $balance, LeaveWindow::days('2026-11-05', '2026-11-01'));
        $hours = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '12:00', '09:00'));

        $this->assertSame([LeaveQuote::INVALID_WINDOW], $days->errors);
        $this->assertSame([LeaveQuote::INVALID_WINDOW], $hours->errors);
    }

    public function test_to_array_is_plain_data_without_pay_figures(): void
    {
        [$contract, $balance] = $this->annual('A');
        DB::table('contracts')->where('id', $contract->id)->update(['salary' => null]);

        $array = $this->quote($contract, $balance, LeaveWindow::days('2026-11-01', '2026-11-05'))->toArray();

        $this->assertSame([
            'unit', 'requested_hours', 'requested_days_display', 'counted_days', 'excluded_dates',
            'available_hours', 'pending_hours', 'projected_hours', 'explanation', 'warnings', 'errors',
            'rule_version', 'hours_per_day', 'calendar_days',
        ], array_keys($array));
        $this->assertSame('hours', $array['unit']);
        $this->assertSame(LeaveQuote::RULE_VERSION, $array['rule_version']);
        $this->assertSame('leave-calc/1', $array['rule_version']);
        $this->assertJson(json_encode($array, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('salary', json_encode($array));
        $this->assertEquals($array, LeaveQuote::fromArray($array)->toArray());

        foreach ($array['explanation'] as $line) {
            $this->assertNotSame($line['key'], __($line['key']), $line['key'].' has no English text');
        }
    }

    public function test_every_code_and_explanation_key_is_translated_in_both_locales(): void
    {
        $codes = array_filter(
            (new ReflectionClass(LeaveQuote::class))->getConstants(),
            fn ($value, $name) => ! in_array($name, ['RULE_VERSION', 'UNIT'], true),
            ARRAY_FILTER_USE_BOTH,
        );

        foreach (['en', 'ar'] as $locale) {
            foreach ($codes as $code) {
                $key = LeaveQuote::messageKey($code);
                $this->assertNotSame($key, __($key, [], $locale), "$key missing in $locale");
            }
        }

        $this->assertSame(
            array_keys(trans('leave_quote.explain', [], 'en')),
            array_keys(trans('leave_quote.explain', [], 'ar')),
        );
    }

    public function test_snapshot_columns_exist_and_are_fillable(): void
    {
        $this->assertTrue(Schema::hasColumns('leave_requests', ['computed_hours', 'rule_version', 'is_exceptional']));

        [, $balance] = $this->annual('A');
        $request = LeaveRequest::create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => '2026-12-15',
            'end_at' => '2026-12-15',
            'reason' => 'Snapshot',
            'status' => LeaveRequestStatus::Pending,
            'computed_hours' => 3.33,
            'rule_version' => LeaveQuote::RULE_VERSION,
            'is_exceptional' => true,
        ])->fresh();

        $this->assertSame(3.33, $request->computed_hours);
        $this->assertSame('leave-calc/1', $request->rule_version);
        $this->assertTrue($request->is_exceptional);
        $this->assertTrue(LeaveWindow::fromRequest($request)->exceptional);
    }

    // --- Helpers ----------------------------------------------------------------

    private function quote(Contract $contract, LeaveBalance $balance, LeaveWindow $window, ?LeaveRequest $excluding = null): LeaveQuote
    {
        return $this->pricing->quote($contract, $balance, $window, $excluding);
    }

    /**
     * @return array{0: Contract, 1: LeaveBalance}
     */
    private function annual(string|int|null $center, int $weeklyHours = 40, int $deserved = 160, float $used = 0, int $scheduleMode = ScheduleMode::Fixed): array
    {
        $contract = $this->contract($center, $weeklyHours, scheduleMode: $scheduleMode);

        return [$contract, $this->balance($contract, LeaveTypeCases::Annual, $deserved, $used)];
    }

    private function contract(string|int|null $center, int $weeklyHours = 40, int $contractTypeId = 1, int $scheduleMode = ScheduleMode::Fixed): Contract
    {
        $centerId = match ($center) {
            'A' => $this->centerA,
            'B' => $this->centerB,
            default => $center,
        };

        $this->sequence++;
        $employeeId = $this->insert('employees', [
            'city_id' => $this->cityId,
            'first_name_en' => 'Test', 'first_name_ar' => 'اختبار',
            'last_name_en' => 'Volunteer', 'last_name_ar' => 'متطوع',
            'username' => 'volunteer-'.$this->sequence,
        ]);

        $contractId = $this->insert('contracts', [
            'employee_id' => $employeeId,
            'position_id' => $this->position($centerId),
            'contract_type_id' => $contractTypeId,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'weekly_hours' => $weeklyHours,
            'schedule_mode' => $scheduleMode,
        ]);

        return Contract::query()->findOrFail($contractId);
    }

    private function position(?int $centerId): int
    {
        return $this->insert('positions', [
            'program_id' => $this->programId,
            'center_id' => $centerId,
            'city_id' => $this->cityId,
            'job_level_id' => $this->jobLevelId,
            'department_id' => $this->departmentId,
            'title_en' => 'Officer',
            'title_ar' => 'موظف',
        ]);
    }

    private function balance(Contract $contract, int $typeId, int $deserved = 160, float $used = 0): LeaveBalance
    {
        $id = $this->insert('leave_balances', [
            'contract_id' => $contract->id,
            'type_id' => $typeId,
            'deserved' => $deserved,
            'used' => $used,
        ]);

        return LeaveBalance::query()->findOrFail($id);
    }

    private function leaveRequest(
        LeaveBalance $balance,
        string $start,
        string $end,
        int $status = LeaveRequestStatus::Pending,
        ?float $computedHours = null,
        string $type = 'day',
    ): LeaveRequest {
        $id = $this->insert('leave_requests', [
            'leave_balance_id' => $balance->id,
            'type' => $type,
            'start_at' => Carbon::parse($start),
            'end_at' => Carbon::parse($end),
            'reason' => 'Synthetic',
            'status' => $status,
            'computed_hours' => $computedHours,
        ]);

        return LeaveRequest::query()->findOrFail($id);
    }

    /** @param  list<array{0: int, 1: int}>  $projects  [programId, cityId] pairs; none = national */
    private function holiday(string $date, bool $recurring = false, array $projects = []): void
    {
        $id = $this->insert('holidays', [
            'name_en' => 'Holiday',
            'name_ar' => 'عطلة',
            'start_date' => $date,
            'end_date' => $date,
            'scope' => $projects === [] ? 'national' : 'projects',
            'is_recurring' => $recurring,
        ]);

        foreach ($projects as [$programId, $cityId]) {
            DB::table('holiday_project')->insert(['holiday_id' => $id, 'program_id' => $programId, 'city_id' => $cityId]);
        }
    }

    /**
     * The pre-contract accessor, run with Carbon's global weekend restored
     * afterwards (it calls Carbon::setWeekendDays()).
     */
    private function legacyAccessor(LeaveRequest $request): float
    {
        $saved = Carbon::getWeekendDays();

        try {
            return (float) $request->fresh()->duration_in_hours;
        } finally {
            Carbon::setWeekendDays($saved);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
