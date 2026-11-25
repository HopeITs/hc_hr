<?php

namespace Tests\Feature;

use App\Enums\LeaveTypeCases;
use App\Livewire\Attendance\Timesheets;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Services\Attendance\Timesheet;
use App\Services\ContractAmendmentService;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The attendance timesheet's days off after main was merged into the work-days
 * work: Mai's Timesheet::daysOff() (the "Weekly days off: … (from …)" line on
 * the calendar, the center / default rule, a Sunday-only center) now reads the
 * days in force on a date — a day range, picked days, a day off moved from a
 * date, an amendment's — and gives the same days off as leave.
 *
 * "Today" is Tuesday 10 Nov 2026. 16 Nov is a Monday, 17 a Tuesday, 19 a
 * Thursday; 1 Dec a Tuesday.
 */
class TimesheetDaysOffTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Mai's rule, kept: the center, a Sunday-only center, the default ──

    public function test_a_contract_stating_no_days_reads_its_centers_weekend(): void
    {
        $employee = $this->person(center: '5,6');

        $this->assertDaysOff($employee, 'november', [5, 6], 'center');
    }

    public function test_a_sunday_only_center_is_a_real_setting(): void
    {
        $employee = $this->person(center: '0');
        $rows = $this->rows($employee, 'november');

        $this->assertDaysOff($employee, 'november', [0], 'center');
        $this->assertSame(Timesheet::WEEKEND, $rows['2026-11-15']['kind']);        // Sunday
        $this->assertNotSame(Timesheet::WEEKEND, $rows['2026-11-14']['kind']);     // Saturday
    }

    public function test_a_center_with_no_weekend_set_falls_back_to_the_default(): void
    {
        $employee = $this->person(center: '');

        $this->assertDaysOff($employee, 'november', Contract::parseWeekdays((string) config('attendance.default_weekends')), 'default');
    }

    // ── Our rule, now inside it: the days the contract works on the date ──

    public function test_a_day_range_is_the_contracts_own_week(): void
    {
        // Monday–Tuesday, the center closed on Sunday: Wednesday to Saturday are off too.
        $employee = $this->person(center: '0', range: [1, 2], weeklyHours: 14);
        $rows = $this->rows($employee, 'november');

        $this->assertDaysOff($employee, 'november', [0, 3, 4, 5, 6], 'contract');
        $this->assertNotSame(Timesheet::WEEKEND, $rows['2026-11-16']['kind']);     // Monday
        $this->assertSame(Timesheet::WEEKEND, $rows['2026-11-18']['kind']);        // Wednesday
    }

    public function test_picked_days_win_over_the_center(): void
    {
        // Picked: Saturday, Sunday, Monday, Wednesday, Thursday; the center closed on Friday.
        $employee = $this->person(center: '5', picked: [6, 0, 1, 3, 4]);

        $this->assertDaysOff($employee, 'november', [2, 5], 'contract');
    }

    public function test_a_day_off_moved_in_the_month_changes_the_rows_from_that_day(): void
    {
        // Off on Tuesday until 15 Nov, on Thursday from the 16th.
        $employee = $this->person(center: '5', picked: [6, 0, 1, 3, 4]);
        app(ContractWorkPatterns::class)->change($this->contractOf($employee), [6, 0, 1, 2, 3], '2026-11-16');
        $rows = $this->rows($employee, 'november');

        $this->assertSame(Timesheet::WEEKEND, $rows['2026-11-10']['kind']);        // Tuesday, before
        $this->assertNotSame(Timesheet::WEEKEND, $rows['2026-11-17']['kind']);     // Tuesday, after
        $this->assertNotSame(Timesheet::WEEKEND, $rows['2026-11-12']['kind']);     // Thursday, before
        $this->assertSame(Timesheet::WEEKEND, $rows['2026-11-19']['kind']);        // Thursday, after
    }

    public function test_the_line_shows_the_days_in_force_in_the_month_shown(): void
    {
        $employee = $this->person(center: '5', picked: [6, 0, 1, 3, 4]);
        app(ContractWorkPatterns::class)->change($this->contractOf($employee), [6, 0, 1, 2, 3], '2026-11-16');

        // October: the old day off. November (as it ends): the new one.
        $this->assertDaysOff($employee, 'october', [2, 5], 'contract');
        $this->assertDaysOff($employee, 'november', [4, 5], 'contract');
    }

    public function test_an_amendment_changes_the_line_from_the_month_it_takes_effect(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldIgnoreMissing();
        // Monday–Friday until 30 Nov, Monday–Thursday from 1 Dec (approved, not yet in effect).
        $employee = $this->person(center: '0,6', range: [1, 5]);
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($this->contractOf($employee), ['work_day_to' => 4, 'weekly_hours' => 32], '2026-12-01'));

        $this->assertDaysOff($employee, 'november', [0, 6], 'contract');
        $this->assertDaysOff($employee, 'december', [0, 5, 6], 'contract');
        $this->assertSame(Timesheet::WEEKEND, $this->rows($employee, 'december')['2026-12-04']['kind']);   // Friday
    }

    // ── Both: the timesheet and leave skip the same days ─────────────────

    public function test_the_timesheet_and_leave_skip_the_same_days_on_every_date(): void
    {
        $pricing = new LeaveRequestPricing;
        $cases = [
            'center only' => $this->person(center: '5,6'),
            'Sunday-only center' => $this->person(center: '0'),
            'day range' => $this->person(center: '0', range: [1, 2], weeklyHours: 14),
            'picked days' => $this->person(center: '5', picked: [6, 0, 1, 3, 4]),
            'moved day off' => $moved = $this->person(center: '5', picked: [6, 0, 1, 3, 4]),
        ];
        app(ContractWorkPatterns::class)->change($this->contractOf($moved), [6, 0, 1, 2, 3], '2026-11-16');

        foreach ($cases as $label => $employee) {
            $contract = $this->contractOf($employee)->fresh(['position.center', 'employee']);

            foreach ($this->rows($employee, 'november') as $date => $row) {
                $offForLeave = in_array(CarbonImmutable::parse($date)->dayOfWeek, $pricing->weekendFor($contract, CarbonImmutable::parse($date))[0], true);

                $this->assertSame($offForLeave, $row['kind'] === Timesheet::WEEKEND, "{$label}, {$date}");
            }
        }
    }

    public function test_a_centers_closed_day_stays_off_even_if_written_into_the_contract(): void
    {
        // The pickers lock the center's day and saving refuses it; only a direct write
        // gets it into the contract. It stays a day off, as it does for leave.
        $employee = $this->person(center: '0');
        $contract = $this->contractOf($employee);
        $contract->update(['work_weekdays' => '0,2,4']);
        app(ContractWorkPatterns::class)->syncInitial($contract->fresh());

        $this->assertDaysOff($employee, 'november', [0, 1, 3, 5, 6], 'contract');

        DB::table('leave_types')->insertOrIgnore(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
        LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 100, 'used' => 0]);
        $quote = (new LeaveRequestPricing)->quote($contract->fresh(['position.center', 'employee']), $contract->leaveBalances()->first(), LeaveWindow::days('2026-11-15', '2026-11-15'));
        $this->assertSame(0, $quote->countedDays);
    }

    // ── The calendar line on the page ────────────────────────────────

    public function test_the_calendar_line_names_the_days_of_the_month_shown(): void
    {
        $employee = $this->person(center: '5', picked: [6, 0, 1, 3, 4]);
        app(ContractWorkPatterns::class)->change($this->contractOf($employee), [6, 0, 1, 2, 3], '2026-11-16');
        $hr = $this->makeUser($this->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
        // A punch in each month, as the attendance page lists people with a record.
        foreach (['2026-10-05 08:00', '2026-11-02 08:00'] as $at) {
            AttendancePunch::query()->create([
                'import_id' => AttendanceImport::query()->firstOrCreate(['file_name' => 'test.xls'])->id,
                'device_no' => (string) $employee->id, 'employee_id' => $employee->id, 'punched_at' => $at,
            ]);
        }

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-11')
            ->call('openMonth', $employee->id)
            ->assertSeeHtml('data-testid="days-off"')
            ->assertSee('Thursday, Friday')
            ->assertSee(__('attendance.timesheets.days-off-from.contract'));

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-10')
            ->call('openMonth', $employee->id)
            ->assertSee('Tuesday, Friday');
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Someone on a contract for 2026 at a center closed on $center, stating
     * picked days, a day range, or no days at all.
     *
     * @param  array<int, int>|null  $picked
     * @param  array{0: int, 1: int}|null  $range
     */
    private function person(string $center, ?array $picked = null, ?array $range = null, int $weeklyHours = 40): Employee
    {
        $employee = $this->makeEmployee($this->city, 'days.off.'.uniqid());
        $contract = $this->makeContractFor($employee, [
            'schedule_mode' => 1,
            'weekly_hours' => $weeklyHours,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'work_day_from' => $range[0] ?? null,
            'work_day_to' => $range[1] ?? null,
            'work_weekdays' => $picked !== null ? implode(',', Contract::parseWeekdays($picked)) : null,
        ]);
        $contract->position->center->update(['weekends' => $center === '' ? null : $center]);
        app(ContractWorkPatterns::class)->syncInitial($contract->fresh());

        return $employee->fresh();
    }

    private function contractOf(Employee $employee): Contract
    {
        return $employee->contracts()->firstOrFail();
    }

    private function month(string $name): CarbonImmutable
    {
        return match ($name) {
            'october' => CarbonImmutable::create(2026, 10, 1),
            'november' => CarbonImmutable::create(2026, 11, 1),
            'december' => CarbonImmutable::create(2026, 12, 1),
        };
    }

    /** @return array<string, array<string, mixed>> the month's rows by date */
    private function rows(Employee $employee, string $month): array
    {
        return collect(app(Timesheet::class)->forEmployee($employee->fresh(), $this->month($month))['rows'])
            ->keyBy(fn (array $row) => $row['date']->toDateString())
            ->all();
    }

    /** @param  array<int, int>  $days */
    private function assertDaysOff(Employee $employee, string $month, array $days, string $from): void
    {
        $placement = app(Timesheet::class)->forEmployee($employee->fresh(), $this->month($month))['placement'];

        $this->assertSame($days, $placement['days_off'], "{$month}: days off");
        $this->assertSame($from, $placement['days_off_from'], "{$month}: from");
    }
}
