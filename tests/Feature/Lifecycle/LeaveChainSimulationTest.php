<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\AnnualLeaveRule;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveDecisionReminder;
use App\Services\ContractAmendmentService;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\Concerns\RunsTheNightlySchedule;
use Tests\TestCase;

/**
 * A leave request from the form to the balance, on the calendar.
 *
 * The leave tests drive one screen at a time with "today" frozen. Here the
 * request is submitted and decided through the real screens, and the nightly
 * schedule then runs around it: the reminder to the decider, the contract
 * ending or renewing, and an amendment changing the working day, with the
 * balances checked each night.
 */
class LeaveChainSimulationTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase, RunsTheNightlySchedule;

    private User $hr;

    private \App\Models\City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-23 10:00:00');
        $this->nightFromHour = 0;
        $this->nightSkips = ['policies:sync'];

        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        AnnualLeaveRule::query()->create(['days' => 14, 'day_per_month' => 1.5, 'years_of_volunteering' => 0]);
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }

        $this->city = $this->makeCity('ALP', '001');
        $this->hr = $this->makeUser($this->city, 'HR-ALP', ['leaves.view', 'leaves.create', 'leaves.update', 'leaves.decide']);
        // HR works on a running contract: without one the nightly access:sync-org-roles suspends the role.
        $this->makeContractFor($this->hr->profile, ['start_date' => '2026-01-01', 'end_date' => '2027-12-31']);
    }

    public function test_an_approved_leave_deducts_once_and_stays_with_its_contract_through_an_amendment_and_the_renewal(): void
    {
        [$user, $contract, $balance] = $this->volunteer('approved');

        $request = $this->submit($user, $balance, '2026-11-25', '2026-11-26');
        $this->assertSame(LeaveRequestStatus::Pending, $request->status);
        $this->assertSame(0.0, (float) $balance->fresh()->used, 'Pending leave deducts nothing.');
        $hours = (float) $request->computed_hours;
        $this->assertGreaterThan(0, $hours);

        // HR decides before the leave starts, so no reminder is due.
        $this->travelTo('2026-11-24 10:00:00');
        $this->decide($request, LeaveRequestStatus::Accepted);
        $this->assertEqualsWithDelta($hours, (float) $balance->fresh()->used, 0.001, 'Accepting deducts the quoted hours.');

        // After the leave, HR amends the working day: what was taken stays as deducted.
        $service = app(ContractAmendmentService::class);
        $service->approve($service->draft($contract, ['weekly_hours' => 30], '2026-12-10'));

        $this->runNights('2026-11-24', '2027-01-03', function (string $day) use ($balance, $request, $hours, $contract): void {
            $this->assertEqualsWithDelta($hours, (float) $balance->fresh()->used, 0.001, "{$day}: the deduction never changes by itself");
            $this->assertSame(LeaveRequestStatus::Accepted, $request->fresh()->status, "{$day}: still accepted");
            $this->assertSame((int) $balance->id, (int) $request->fresh()->leave_balance_id, "{$day}: stays on its own balance");
            $this->assertSame(1, LeaveBalance::query()->where('contract_id', $contract->id)->where('type_id', LeaveTypeCases::Annual)->count());
        });

        $this->assertSame(0, DatabaseNotification::query()->where('type', LeaveDecisionReminder::class)->count(), 'A decided request is never reminded.');

        // The renewal opens a new, untouched balance.
        $successor = Contract::query()->where('employee_id', $contract->employee_id)->whereKeyNot($contract->id)->sole();
        $new = LeaveBalance::query()->where('contract_id', $successor->id)->where('type_id', LeaveTypeCases::Annual)->sole();
        $this->assertSame(0.0, (float) $new->used);
        $this->assertSame(0, LeaveRequest::query()->where('leave_balance_id', $new->id)->count());
    }

    public function test_a_request_left_pending_is_reminded_once_and_not_after_the_volunteer_leaves(): void
    {
        config(['journeys.exit_grace_days' => 7]);
        [$user, $contract, $balance] = $this->volunteer('pending', ['end_date' => '2026-12-05', 'auto_renew' => false]);

        $request = $this->submit($user, $balance, '2026-11-29', '2026-11-30');

        $this->runNights('2026-11-23', '2026-12-20', function (string $day) use ($request, $balance): void {
            $this->assertSame(LeaveRequestStatus::Pending, $request->fresh()->status, "{$day}: nobody decided");
            $this->assertSame(0.0, (float) $balance->fresh()->used, "{$day}: nothing deducted");
        });

        $reminders = DatabaseNotification::query()->where('type', LeaveDecisionReminder::class)->get();
        $this->assertNotEmpty($reminders, 'The decider was reminded.');
        $this->assertCount(
            $reminders->pluck('notifiable_id')->unique()->count(),
            $reminders,
            'Once per decider, however many nights it stayed pending.',
        );

        // The volunteer left (contract ended 5 Dec, 7 days of grace): the request does not block that.
        $this->assertFalse($contract->employee->fresh()->is_active);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array{0: User, 1: Contract, 2: LeaveBalance} */
    private function volunteer(string $name, array $overrides = []): array
    {
        $city = $this->city;
        $employee = $this->makeEmployee($city, 'leave.'.$name);
        $user = $this->makeUser($city, 'employee', ['leaves.create', 'leaves.update'], $employee);

        $contract = $this->makeContractFor($employee, array_merge([
            'type' => ContractTypeEnum::FullTime, 'schedule_mode' => 1, 'work_day_from' => 0, 'work_day_to' => 4,
            'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00', 'weekly_hours' => 40,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'auto_renew' => true,
        ], $overrides))->refresh();

        app(LeaveBalanceService::class)->create($contract);

        return [$user, $contract, LeaveBalance::query()->where('contract_id', $contract->id)->where('type_id', LeaveTypeCases::Annual)->sole()];
    }

    private function submit(User $user, LeaveBalance $balance, string $start, string $end): LeaveRequest
    {
        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $balance->id)
            ->set('form.type', 'day')
            ->set('form.start_at', $start)
            ->set('form.end_at', $end)
            ->set('form.reason', 'Family visit.')
            ->call('confirmSave')->assertHasNoErrors()
            ->call('processSave')->assertHasNoErrors();

        return LeaveRequest::query()->where('leave_balance_id', $balance->id)->latest('id')->firstOrFail();
    }

    private function decide(LeaveRequest $request, int $status): void
    {
        Livewire::actingAs($this->hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $request->id)
            ->set('form.status', $status)
            ->call('submitDecision')
            ->assertHasNoErrors();
    }
}
