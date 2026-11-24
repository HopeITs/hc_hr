<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveTypeCases;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\Program;
use App\Services\ContractAmendmentService;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveQuote;
use App\Support\Leave\LeaveWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Daily and hourly leave priced against the days a contract really works,
 * for every kind of contract: Study Zone with picked days, Study Zone on a
 * day range, Hope Center on a range, a part-timer with picked days; a day off
 * moved from a date; amendments in effect, approved for later, cancelled and
 * still in draft.
 *
 * "Today" is Tuesday 10 Nov 2026. 15 Nov is a Sunday, 16 Mon, 17 Tue, 18 Wed,
 * 19 Thu, 20 Fri, 21 Sat, 22 Sun, 23 Mon, 24 Tue, 25 Wed, 26 Thu, 27 Fri.
 */
class LeaveWorkDaysScenariosTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private LeaveRequestPricing $pricing;

    private City $city;

    private Department $department;

    private JobLevel $level;

    private ContractType $annualType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->pricing = new LeaveRequestPricing;
        $this->mock(LeaveBalanceService::class)->shouldIgnoreMissing();

        $this->city = $this->makeCity('HMS', '002');
        $this->department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Volunteer', 'name_ar' => 'متطوع', 'grade' => 1]);
        $this->annualType = ContractType::query()->forceCreate(['name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوع']);
        DB::table('leave_types')->insert(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Study Zone, picked days ─────────────────────────────────────────

    public function test_study_zone_daily_leave_skips_the_volunteers_day_off_and_the_centers(): void
    {
        // Centers closed on Friday; the volunteer is also off on Tuesday.
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);

        $this->assertDays($contract, '2026-11-16', '2026-11-18', 2, ['2026-11-17' => 'day_off']);
        $this->assertDays($contract, '2026-11-19', '2026-11-21', 2, ['2026-11-20' => 'weekend']);
        $this->assertDays($contract, '2026-11-15', '2026-11-21', 5, ['2026-11-17' => 'day_off', '2026-11-20' => 'weekend']);
        $this->assertSame(8.0, $this->quote($contract, LeaveWindow::days('2026-11-16', '2026-11-16'))->hoursPerDay);
    }

    public function test_study_zone_hourly_leave_on_a_day_off_is_refused_and_on_a_working_day_is_priced(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);

        // On the volunteer's own day off, and on the center's.
        $this->assertContains(LeaveQuote::NO_COUNTABLE_DAYS, $this->quote($contract, LeaveWindow::hours('2026-11-17', '10:00', '12:00'))->errors);
        $this->assertContains(LeaveQuote::NO_COUNTABLE_DAYS, $this->quote($contract, LeaveWindow::hours('2026-11-20', '10:00', '12:00'))->errors);

        // On a working day: two hours, exactly; over four, a whole 8-hour day.
        $short = $this->quote($contract, LeaveWindow::hours('2026-11-18', '10:00', '12:00'));
        $long = $this->quote($contract, LeaveWindow::hours('2026-11-18', '09:00', '14:00'));

        $this->assertSame([], $short->errors);
        $this->assertSame(2.0, $short->requestedHours);
        $this->assertSame(8.0, $long->requestedHours);
    }

    public function test_a_study_zone_contract_still_on_a_day_range_knows_only_the_centers_day_off(): void
    {
        // Before its days are picked: Sunday–Thursday as a range. Leave reads
        // only the centers' Friday, so Saturday counts and a day is 40 / 6 h.
        $contract = $this->studyZone(range: [0, 4]);

        $this->assertDays($contract, '2026-11-20', '2026-11-22', 2, ['2026-11-20' => 'weekend']);
        $this->assertEqualsWithDelta(40 / 6, $this->quote($contract, LeaveWindow::days('2026-11-16', '2026-11-16'))->hoursPerDay, 0.0001);
    }

    // ── Other programs ───────────────────────────────────────────────

    public function test_hope_center_on_a_day_range_skips_the_centers_weekend_only(): void
    {
        // Monday to Friday, the center closed on Saturday and Sunday.
        $contract = $this->atCenter(weekends: '0,6', range: [1, 5]);

        $this->assertDays($contract, '2026-11-20', '2026-11-23', 2, ['2026-11-21' => 'weekend', '2026-11-22' => 'weekend']);
        $this->assertContains(LeaveQuote::NO_COUNTABLE_DAYS, $this->quote($contract, LeaveWindow::hours('2026-11-22', '10:00', '12:00'))->errors);
        $this->assertSame(2.0, $this->quote($contract, LeaveWindow::hours('2026-11-23', '10:00', '12:00'))->requestedHours);
        $this->assertSame(8.0, $this->quote($contract, LeaveWindow::days('2026-11-16', '2026-11-16'))->hoursPerDay);
    }

    public function test_a_part_timer_with_picked_days_pays_a_full_day_for_each_day_they_work(): void
    {
        // 24 hours on Sunday, Tuesday and Thursday; the center closed Fri + Sat.
        $contract = $this->atCenter(weekends: '5,6', picked: [0, 2, 4], weeklyHours: 24);
        $week = $this->quote($contract, LeaveWindow::days('2026-11-15', '2026-11-21'));

        $this->assertSame(3, $week->countedDays);
        $this->assertSame(8.0, $week->hoursPerDay);
        $this->assertSame(24.0, $week->requestedHours);
        $this->assertContains(LeaveQuote::NO_COUNTABLE_DAYS, $this->quote($contract, LeaveWindow::hours('2026-11-16', '10:00', '11:00'))->errors);
    }

    // ── A day off moved from a date ──────────────────────────────────

    public function test_a_day_off_moved_from_a_date_prices_each_side_with_its_own_days(): void
    {
        // Off on Tuesday until 22 Nov, on Saturday from 23 Nov.
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        app(ContractWorkPatterns::class)->change($contract, [0, 1, 2, 3, 4], '2026-11-23');
        $contract = $contract->fresh();

        // Sat 21 worked, Sun 22 worked | Mon 23, Tue 24 now worked, Sat 28 off.
        $this->assertDays($contract, '2026-11-21', '2026-11-28', 6, [
            '2026-11-27' => 'weekend',
            '2026-11-28' => 'day_off',
        ]);
        $this->assertDays($contract, '2026-11-17', '2026-11-24', 6, [
            '2026-11-17' => 'day_off',
            '2026-11-20' => 'weekend',
        ]);
        $this->assertSame([], $this->quote($contract, LeaveWindow::hours('2026-11-24', '10:00', '12:00'))->errors);
        $this->assertContains(LeaveQuote::NO_COUNTABLE_DAYS, $this->quote($contract, LeaveWindow::hours('2026-11-17', '10:00', '12:00'))->errors);
    }

    public function test_a_range_contract_moved_to_its_own_days_switches_from_the_chosen_date(): void
    {
        // Sunday to Thursday as a range, at a center closed on Friday only.
        $contract = $this->atCenter(weekends: '5', range: [0, 4]);

        // A different number of days is refused: that is an amendment.
        try {
            app(ContractWorkPatterns::class)->change($contract, [0, 1, 2, 3], '2026-11-23');
            $this->fail('Four days were accepted on a five-day contract.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        // From 23 Nov: Saturday to Wednesday, off Thursday and Friday.
        app(ContractWorkPatterns::class)->change($contract, [6, 0, 1, 2, 3], '2026-11-23');
        $contract = $contract->fresh();

        // Before the date the range is read through the center alone (see the
        // Study Zone range test): only Friday is skipped.
        $this->assertDays($contract, '2026-11-19', '2026-11-21', 2, ['2026-11-20' => 'weekend']);
        // From it: Thursday is the employee's own day off.
        $this->assertDays($contract, '2026-11-25', '2026-11-28', 2, ['2026-11-26' => 'day_off', '2026-11-27' => 'weekend']);
    }

    // ── Amendments ───────────────────────────────────────────────────

    public function test_an_amendment_in_effect_moves_the_days_from_its_own_date(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['work_weekdays' => '0,1,2,3,4'], '2026-11-01');
        app(ContractAmendmentService::class)->approve($amendment);
        $contract = $contract->fresh();

        $this->assertSame([0, 1, 2, 3, 4], $contract->workWeekdays());
        // From 1 Nov: Tuesday worked, Saturday off.
        $this->assertDays($contract, '2026-11-17', '2026-11-21', 3, ['2026-11-20' => 'weekend', '2026-11-21' => 'day_off']);
    }

    public function test_an_amendment_approved_for_a_later_date_prices_leave_after_that_date_with_its_days(): void
    {
        // Off on Tuesday now; an approved amendment makes it Saturday from 23 Nov.
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        $amendment = app(ContractAmendmentService::class)->draft($contract, ['work_weekdays' => '0,1,2,3,4'], '2026-11-23');
        app(ContractAmendmentService::class)->approve($amendment);
        $contract = $contract->fresh();

        $this->assertTrue($amendment->fresh()->isApproved());
        // Before the date: still the old days. After: the amendment's.
        $this->assertDays($contract, '2026-11-17', '2026-11-17', 0, ['2026-11-17' => 'day_off']);
        // Tue 24 now worked, Sat 28 now off (under the old days it was the reverse).
        $this->assertDays($contract, '2026-11-24', '2026-11-28', 3, ['2026-11-27' => 'weekend', '2026-11-28' => 'day_off']);

        // On the day it applies nothing changes for leave already priced.
        Carbon::setTestNow('2026-11-23 21:25:00');
        app(ContractAmendmentService::class)->applyDue();
        $this->assertTrue($amendment->fresh()->isApplied());
        $this->assertDays($contract->fresh(), '2026-11-24', '2026-11-28', 3, ['2026-11-27' => 'weekend', '2026-11-28' => 'day_off']);
        $this->assertDays($contract->fresh(), '2026-11-17', '2026-11-17', 0, ['2026-11-17' => 'day_off']);
    }

    public function test_a_cancelled_amendment_leaves_the_days_as_they_were(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        $service = app(ContractAmendmentService::class);
        $amendment = $service->draft($contract, ['work_weekdays' => '0,1,2,3,4'], '2026-11-23');
        $service->approve($amendment);
        $service->discard($amendment->fresh());

        $this->assertDays($contract->fresh(), '2026-11-24', '2026-11-24', 0, ['2026-11-24' => 'day_off']);
        $this->assertSame(1, DB::table('contract_work_patterns')->where('contract_id', $contract->id)->count());
    }

    public function test_a_draft_amendment_changes_nothing(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        app(ContractAmendmentService::class)->draft($contract, ['work_weekdays' => '0,1,2,3,4'], '2026-11-23');

        $this->assertDays($contract->fresh(), '2026-11-24', '2026-11-24', 0, ['2026-11-24' => 'day_off']);
    }

    public function test_an_amendment_cancelled_because_the_contract_ended_first_takes_its_days_with_it(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        $service = app(ContractAmendmentService::class);
        $amendment = $service->draft($contract, ['work_weekdays' => '0,1,2,3,4'], '2026-11-23');
        $service->approve($amendment);

        // The contract is terminated on 20 Nov, before the amendment's date.
        $contract->update(['terminated_at' => '2026-11-20']);
        $service->cancelForEndedContract($contract->fresh());

        $this->assertNull($amendment->fresh());
        $this->assertSame(0, DB::table('contract_work_patterns')->whereNotNull('contract_amendment_id')->count());
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * A Study Zone volunteer (no center of their own) in Homs, where the
     * program's centers close on Friday. Either picked days or a day range.
     *
     * @param  array<int, int>|null  $picked
     * @param  array{0: int, 1: int}|null  $range
     */
    private function studyZone(?array $picked = null, ?array $range = null, int $weeklyHours = 40): Contract
    {
        $program = Program::query()->find(Program::STUDY_ZONE_ID)
            ?? Program::query()->forceCreate(['id' => Program::STUDY_ZONE_ID, 'name' => 'Study Zone', 'slug' => 'SZ', 'code' => 'SZ', 'is_active' => true]);

        if (! Center::query()->where('name_en', 'Study Zone Homs')->exists()) {
            Center::query()->create([
                'name_en' => 'Study Zone Homs', 'name_ar' => 'ستادي زون حمص', 'city_id' => $this->city->id,
                'is_active' => true, 'weekends' => '5', 'open_at' => '08:00:00', 'close_at' => '21:00:00',
            ])->programs()->attach($program);
        }

        return $this->contract($program, null, $picked, $range, $weeklyHours);
    }

    /**
     * Someone at a center of their own (Hope Center, Micro Project…).
     *
     * @param  array<int, int>|null  $picked
     * @param  array{0: int, 1: int}|null  $range
     */
    private function atCenter(string $weekends, ?array $picked = null, ?array $range = null, int $weeklyHours = 40): Contract
    {
        $program = Program::query()->firstOrCreate(['code' => 'HC'], ['name' => 'Hope Center', 'slug' => 'HC', 'is_active' => true]);
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $this->city->id,
            'is_active' => true, 'weekends' => $weekends, 'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);

        return $this->contract($program, $center, $picked, $range, $weeklyHours);
    }

    private function contract(Program $program, ?Center $center, ?array $picked, ?array $range, int $weeklyHours): Contract
    {
        $position = Position::query()->forceCreate([
            'center_id' => $center?->id, 'city_id' => $this->city->id, 'department_id' => $this->department->id,
            'job_level_id' => $this->level->id, 'program_id' => $program->id,
            'title_en' => 'Tutor', 'title_ar' => 'مدرس', 'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'employee_id' => $this->makeEmployee($this->city, 'leave-days-'.uniqid())->id,
            'position_id' => $position->id,
            'contract_type_id' => $this->annualType->id,
            'type' => ContractTypeEnum::FullTime,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary_per' => 1,
            'salary' => 100,
            'pay_mode' => 1,
            'weekly_hours' => $weeklyHours,
            'schedule_mode' => 1,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'work_day_from' => $range[0] ?? null,
            'work_day_to' => $range[1] ?? null,
            'work_weekdays' => $picked !== null ? implode(',', Contract::parseWeekdays($picked)) : null,
            'is_active' => true,
        ]);

        app(ContractWorkPatterns::class)->syncInitial($contract);

        LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 400, 'used' => 0]);

        return $contract->fresh();
    }

    private function quote(Contract $contract, LeaveWindow $window): LeaveQuote
    {
        $balance = LeaveBalance::query()->where('contract_id', $contract->id)->where('type_id', LeaveTypeCases::Annual)->firstOrFail();

        return $this->pricing->quote($contract->fresh(['position.center', 'employee']), $balance, $window);
    }

    /** @param  array<string, string>  $skipped  date => reason, every skipped date */
    private function assertDays(Contract $contract, string $from, string $to, int $counted, array $skipped): void
    {
        $quote = $this->quote($contract, LeaveWindow::days($from, $to));

        $this->assertSame($counted, $quote->countedDays, "{$from}..{$to}: counted days");
        $this->assertSame($skipped, collect($quote->excludedDates)->mapWithKeys(fn (array $d) => [$d['date'] => $d['reason']])->all(), "{$from}..{$to}: skipped days");
    }
}
