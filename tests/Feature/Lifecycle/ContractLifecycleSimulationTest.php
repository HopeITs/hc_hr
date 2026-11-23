<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveTypeCases;
use App\Models\AnnualLeaveRule;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveType;
use App\Models\OffboardingJourney;
use App\Models\Position;
use App\Models\Program;
use App\Models\RenewalJourney;
use App\Notifications\ContractsAwaitingNewYearRenewal;
use App\Services\ContractAmendmentService;
use App\Services\ContractAutoRenewalService;
use App\Services\RenewalJourneys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesHrFixtures;
use Tests\Concerns\RunsTheNightlySchedule;
use Tests\TestCase;

/**
 * A contract's life played on the calendar, through the real evening run.
 *
 * Every step here is already tested on its own; what these tests add is the
 * sequence: the commands run in their scheduled order, night after night, and
 * the invariants that hold across modules (one seat per running contract, no
 * employee left without a contract, a renewal carrying the amended terms) are
 * checked after every night, not only at the end.
 */
class ContractLifecycleSimulationTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase, RunsTheNightlySchedule;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->f = $this->fixtures();
    }

    public function test_the_evening_run_is_in_the_order_the_lifecycle_depends_on(): void
    {
        $order = $this->nightlyCommands()->pluck('command')->all();

        $before = fn (string $a, string $b) => $this->assertLessThan(
            array_search($b, $order, true), array_search($a, $order, true), "{$a} must run before {$b}",
        );

        // An amendment due today lands before the contract can end tonight.
        $before('contracts:apply-amendments', 'deactivate:contract');
        // Auto-renewal only picks up contracts deactivation has just closed.
        $before('deactivate:contract', 'contracts:process-auto-renewals');
        // The journey settles on the successor the renewal just made.
        $before('contracts:process-auto-renewals', 'renewals:track-journeys');
        // Nobody is deactivated while a renewal is still to be made tonight.
        $before('contracts:process-auto-renewals', 'employees:deactivate-departed');
    }

    public function test_an_amended_auto_renewing_contract_rolls_into_the_new_year_through_the_nightly_run(): void
    {
        $contract = $this->contract('rollover');
        $employee = $contract->employee;

        // HR amends the hours from the 20th.
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($contract, ['weekly_hours' => 30], '2026-11-20'));

        $this->runNights('2026-11-15', '2026-12-04', $check = function (string $day) use ($contract, $employee): void {
            $this->assertSeatsMatchRunningContracts($day);

            $this->assertTrue($employee->fresh()->is_active, "{$day}: an employee whose contract renews is never deactivated.");

            $expected = $day >= '2026-11-20' ? 30 : 40;
            $this->assertSame($expected, (int) $contract->fresh()->weekly_hours, "{$day}: hours");

            $journeys = RenewalJourney::query()->where('contract_id', $contract->id)->count();
            $this->assertSame($day >= '2026-12-01' ? 1 : 0, $journeys, "{$day}: one renewal journey, from 30 days before the end");
        });

        // HR records "renew" on the journey the run opened. (With no decision
        // the journey stays open and late after the renewal: open point 2 of
        // docs/ux/renewal-journey-implementation.md, for the product owner.)
        $this->travelTo('2026-12-05 10:00:00');
        $this->assertTrue(app(RenewalJourneys::class)->decide(RenewalJourney::query()->sole(), RenewalJourney::DECISION_RENEW, $this->f['hr']));

        $this->runNights('2026-12-05', '2027-01-03', $check);

        $this->assertTrue(ContractAmendment::query()->sole()->isApplied());

        $original = $contract->fresh();
        $this->assertFalse($original->is_active);

        $successor = Contract::query()->where('employee_id', $employee->id)->whereKeyNot($contract->id)->sole();
        $this->assertSame('2027-01-01', $successor->start_date->toDateString());
        $this->assertSame('2027-12-31', $successor->end_date->toDateString());
        $this->assertTrue($successor->is_active);
        $this->assertSame(30, (int) $successor->weekly_hours, 'The renewal carries the amended terms.');
        $this->assertTrue($successor->leaveBalances()->exists(), 'The renewal gets its own leave balances.');

        $this->assertNotNull(RenewalJourney::query()->sole()->completed_at);
    }

    public function test_running_an_evening_twice_changes_nothing_the_second_time(): void
    {
        $contract = $this->contract('twice', ['end_date' => '2026-11-30']);

        $this->runNight('2026-12-01');
        $this->runNight('2027-01-01');
        $snapshot = $this->snapshot();
        $notifications = DatabaseNotification::query()->count();

        $this->runNight('2027-01-01');

        $this->assertSame($notifications, DatabaseNotification::query()->count(), 'No notice is sent twice.');

        $this->assertSame($snapshot, $this->snapshot(), 'A re-run (a retried cron, an overlap) is a no-op.');
        $this->assertSame(2, Contract::query()->where('employee_id', $contract->employee_id)->count());
    }

    public function test_a_contract_that_does_not_renew_frees_its_seat_and_the_volunteer_leaves_after_the_grace_period(): void
    {
        config(['journeys.exit_grace_days' => 7]);
        $contract = $this->contract('leaver', ['end_date' => '2026-11-30', 'auto_renew' => false]);
        $employee = $contract->employee;

        $this->runNights('2026-11-28', '2026-12-10', function (string $day) use ($contract, $employee): void {
            $this->assertSeatsMatchRunningContracts($day);

            $this->assertSame($day <= '2026-11-30', (bool) $contract->fresh()->is_active, "{$day}: contract running");
            $this->assertSame($day < '2026-12-07', (bool) $employee->fresh()->is_active, "{$day}: employee active until the grace period ends");
        });

        $this->assertSame(1, Contract::query()->where('employee_id', $employee->id)->count(), 'Nothing was renewed.');
    }

    public function test_a_contract_ending_in_october_to_december_waits_for_the_new_year_and_hr_is_told_once(): void
    {
        // Before: a contract ending on 15 November renewed 16 Nov → 31 Dec.
        $contract = $this->contract('autumn', ['end_date' => '2026-11-15']);
        $employee = $contract->employee;

        $this->runNights('2026-11-14', '2027-01-02', function (string $day) use ($contract, $employee): void {
            $this->assertSeatsMatchRunningContracts($day);
            $this->assertTrue($employee->fresh()->is_active, "{$day}: a volunteer waiting for the new year stays active.");
            $this->assertSame(0, OffboardingJourney::query()->where('employee_id', $employee->id)->count(), "{$day}: no exit journey");

            $renewed = Contract::query()->where('employee_id', $employee->id)->whereKeyNot($contract->id)->exists();
            $this->assertSame($day >= '2027-01-01', $renewed, "{$day}: renewed only on 1 January");
        });

        $successor = Contract::query()->where('employee_id', $employee->id)->whereKeyNot($contract->id)->sole();
        $this->assertSame('2027-01-01', $successor->start_date->toDateString());
        $this->assertSame('2027-12-31', $successor->end_date->toDateString());
        $this->assertTrue($successor->leaveBalances()->exists());

        // Nobody recorded a decision, yet the journey does not stay open and late.
        $journey = RenewalJourney::query()->sole();
        $this->assertNotNull($journey->completed_at);
        $this->assertSame('renewed_without_decision', Activity::query()->where('log_name', 'renewal')
            ->where('description', 'renewal_completed')->sole()->properties['outcome']);

        // City HR heard about it once, the night after it ended.
        $notices = DatabaseNotification::query()->where('type', ContractsAwaitingNewYearRenewal::class)->get();
        $this->assertCount(1, $notices);
        $this->assertSame($this->f['hr']->id, (int) $notices->sole()->notifiable_id);
        $this->assertSame('2026-11-16', $notices->sole()->created_at->toDateString());
        $this->assertSame('2027-01-01', $notices->sole()->data['renews_on']);
    }

    public function test_the_new_year_rule_leaves_other_months_and_the_31st_of_december_alone(): void
    {
        $september = $this->contract('september', ['end_date' => '2026-09-30']);
        $yearEnd = $this->contract('yearend', ['end_date' => '2026-12-31']);

        $this->assertSame('2026-10-01', ContractAutoRenewalService::renewalStart($september)->toDateString());
        $this->assertSame('2027-01-01', ContractAutoRenewalService::renewalStart($yearEnd)->toDateString());
        $this->assertFalse(ContractAutoRenewalService::waitsForNewYear($yearEnd), 'No "waiting" notice for a contract that renews the next day anyway.');

        $this->runNight('2026-10-01');

        $successor = Contract::query()->where('employee_id', $september->employee_id)->whereKeyNot($september->id)->sole();
        $this->assertSame('2026-10-01', $successor->start_date->toDateString());
        $this->assertSame(0, DatabaseNotification::query()->where('type', ContractsAwaitingNewYearRenewal::class)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** Position.exist_emp must equal the contracts actually holding a seat, every night. */
    private function assertSeatsMatchRunningContracts(string $day): void
    {
        foreach (Position::query()->get() as $position) {
            $running = Contract::query()->where('position_id', $position->id)->where('is_active', true)->count();

            $this->assertSame($running, (int) $position->exist_emp, "{$day}: seats on position #{$position->id}");
        }
    }

    private function snapshot(): array
    {
        return [
            'contracts' => Contract::query()->orderBy('id')->get(['id', 'is_active', 'start_date', 'end_date'])->toArray(),
            'seats' => Position::query()->orderBy('id')->pluck('exist_emp', 'id')->all(),
            'employees' => Employee::query()->orderBy('id')->pluck('is_active', 'id')->all(),
            'journeys' => RenewalJourney::query()->orderBy('id')->get(['id', 'decision', 'completed_at'])->toArray(),
        ];
    }

    private function contract(string $username, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($this->f['city'], 'life.'.$username);

        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $this->f['position']->id,
            'contract_type_id' => $this->f['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary' => 1500,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
            'auto_renew' => true,
        ], $overrides))->refresh();
    }

    private function fixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $position = Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'title_en' => 'Program Officer', 'title_ar' => 'مسؤول برنامج',
            'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.update', 'contracts.view', 'contracts.create', 'employees.update']);

        AnnualLeaveRule::query()->create(['days' => 14, 'day_per_month' => 1.5, 'years_of_volunteering' => 0]);
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }

        return compact('city', 'position', 'annualType', 'hr');
    }
}
