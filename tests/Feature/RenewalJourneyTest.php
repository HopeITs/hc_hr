<?php

namespace Tests\Feature;

use App\Console\Commands\SendContractExpirationWarnings;
use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\RenewalJourneyPanel;
use App\Models\AnnualLeaveRule;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\RenewalJourney;
use App\Models\User;
use App\Services\ContractAutoRenewalService;
use App\Services\NotificationDeliveryLedger;
use App\Services\RenewalJourneys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Renewal journey (DEC-09 increment 2, docs/ux/journeys.md §3.2,
 * docs/ux/renewal-journey-implementation.md).
 */
class RenewalJourneyTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    /** A figure that must never reach the panel's HTML. */
    private const SALARY = 987654;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        $this->travelTo('2026-10-01 10:00:00');
        $this->f = $this->fixtures();
    }

    // ── Start: once, 30 days before the end, only for contracts that can renew ──

    public function test_the_journey_starts_once_thirty_days_before_the_end_and_not_for_trial_terminated_or_draft(): void
    {
        $due = $this->contract('due', ['end_date' => '2026-10-25']);
        $later = $this->contract('later', ['end_date' => '2026-11-01']); // 31 days out today
        $trial = $this->contract('trial', ['end_date' => '2026-10-20', 'contract_type_id' => $this->f['trialType']->id]);
        $terminated = $this->contract('terminated', ['end_date' => '2026-10-20', 'terminated_at' => '2026-09-30']);
        $draft = $this->contract('draft', ['end_date' => '2026-10-20', 'type' => ContractTypeEnum::Draft]);
        // Legacy rows that still carry the flag must not slip in either.
        Contract::query()->whereKey([$trial->id, $terminated->id])->update(['auto_renew' => true]);

        $this->artisan('renewals:track-journeys')->assertSuccessful();

        $this->assertSame([$due->id], RenewalJourney::query()->pluck('contract_id')->all());
        $this->assertSame($due->employee_id, RenewalJourney::query()->sole()->employee_id);

        $this->travelTo('2026-10-02 10:00:00');
        $this->artisan('renewals:track-journeys')->assertSuccessful();
        $this->artisan('renewals:track-journeys')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$due->id, $later->id], RenewalJourney::query()->pluck('contract_id')->all());
        $this->assertSame(0, RenewalJourney::query()->whereIn('contract_id', [$trial->id, $terminated->id, $draft->id])->count());
        $this->assertNull(app(RenewalJourneys::class)->startForContract($trial->fresh()));
        $this->assertNull(app(RenewalJourneys::class)->startForContract($due->fresh()), 'Unique per contract.');

        $started = Activity::query()->where('log_name', 'renewal')->where('description', 'renewal_started')->get();
        $this->assertCount(2, $started);
        $this->assertTrue($started->every(fn (Activity $a) => $a->causer_id === null), 'The system starts it.');
    }

    public function test_a_contract_already_renewed_by_hand_gets_no_journey(): void
    {
        $source = $this->contract('early', ['end_date' => '2026-10-25']);
        $this->successor($source);

        $this->artisan('renewals:track-journeys')->assertSuccessful();

        $this->assertSame(0, RenewalJourney::query()->count());
    }

    public function test_the_panel_shows_the_four_steps_with_the_decision_due_on_the_end_date(): void
    {
        $journey = $this->journey($this->contract('panel', ['end_date' => '2026-10-25']));

        $this->panel($this->f['hr'], $journey->employee)
            ->assertSee(__('renewal.step.decision'))
            ->assertViewHas('rows', fn ($rows) => $this->states($rows[0]['steps']) === [
                // This position has no direct manager, so R1 can send no warning.
                'warning' => 'problem', 'decision' => 'todo', 'successor' => 'waiting', 'leave' => 'waiting',
            ])
            ->assertSee(__('renewal.note.no-manager'))
            ->assertViewHas('rows', fn ($rows) => $this->byKey($rows[0]['steps'])['decision']['due']->toDateString() === '2026-10-25'
                && $this->byKey($rows[0]['steps'])['decision']['late'] === false
                && $this->byKey($rows[0]['steps'])['successor']['blocked_by'] === 'decision')
            ->assertSee('data-testid="renewal-renew"', false)
            ->assertSee('data-testid="renewal-stop"', false);
    }

    public function test_the_warning_step_reads_the_notification_ledger(): void
    {
        $contract = $this->contract('warned', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);
        $ledger = app(NotificationDeliveryLedger::class);
        $ledger->claim(SendContractExpirationWarnings::LEDGER_KIND, $this->f['hr']->id, [$contract->id]);
        $ledger->markDelivered(SendContractExpirationWarnings::LEDGER_KIND, $this->f['hr']->id, [$contract->id]);

        $warning = $this->byKey(app(RenewalJourneys::class)->steps($journey->fresh()))['warning'];

        $this->assertSame('done', $warning['state']);
        $this->assertSame('2026-10-01', $warning['data']['last_sent_on']);
    }

    public function test_the_decision_is_late_within_seven_days_of_the_end(): void
    {
        $journey = $this->journey($this->contract('late', ['end_date' => '2026-10-20']));

        $this->travelTo('2026-10-12 10:00:00');
        $this->assertFalse($journey->fresh()->decisionIsLate());

        $this->travelTo('2026-10-13 10:00:00');
        $this->assertTrue($journey->fresh()->decisionIsLate());
        $this->panel($this->f['hr'], $journey->employee)
            ->assertViewHas('rows', fn ($rows) => $this->byKey($rows[0]['steps'])['decision']['late'] === true)
            ->assertSee(__('renewal.late'));

        app(RenewalJourneys::class)->decide($journey, RenewalJourney::DECISION_RENEW, $this->f['hr']);
        $this->assertFalse($journey->fresh()->decisionIsLate());
    }

    // ── Stop: auto-renew off through the shared path ─────────────────

    public function test_the_stop_decision_turns_auto_renew_off_through_the_shared_path(): void
    {
        $contract = $this->contract('stop', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);
        $this->assertTrue($contract->fresh()->auto_renew);

        $this->partialMock(ContractAutoRenewalService::class, function ($mock): void {
            $mock->shouldReceive('setAutoRenew')->once()->passthru();
        });

        $this->panel($this->f['hr'], $journey->employee)
            ->call('confirmStop', $journey->id)
            ->assertDispatched('confirm-dialog', method: 'stop', payload: $journey->id)
            ->call('stop', $journey->id);

        $this->assertFalse($contract->fresh()->auto_renew);

        $journey->refresh();
        $this->assertSame(RenewalJourney::DECISION_STOP, $journey->decision);
        $this->assertSame($this->f['hr']->id, (int) $journey->decided_by);
        $this->assertTrue($journey->decision_switched_auto_renew);
        $this->assertNotNull($journey->completed_at, 'A stopped renewal is finished; the exit journey takes over.');
    }

    public function test_the_stopped_contract_is_not_auto_renewed(): void
    {
        $contract = $this->contract('stopped', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);
        app(RenewalJourneys::class)->decide($journey, RenewalJourney::DECISION_STOP, $this->f['hr']);

        $this->travelTo('2026-10-26 22:00:00');
        Contract::query()->whereKey($contract)->update(['is_active' => false]);
        $this->artisan('contracts:process-auto-renewals')->assertSuccessful();

        $this->assertSame(1, Contract::query()->where('employee_id', $contract->employee_id)->count());
    }

    // ── Renew, then the auto-renewal completes the journey ───────────

    public function test_renew_followed_by_the_auto_renewal_completes_the_journey(): void
    {
        $this->seedLeaveConfiguration();
        $contract = $this->contract('renew', ['end_date' => '2026-10-25']);
        $this->artisan('renewals:track-journeys')->assertSuccessful();
        $journey = RenewalJourney::query()->sole();

        $this->panel($this->f['hr'], $journey->employee)->call('decideRenew', $journey->id);

        $journey->refresh();
        $this->assertSame(RenewalJourney::DECISION_RENEW, $journey->decision);
        $this->assertTrue($contract->fresh()->auto_renew, '"Renew" leaves auto-renew on.');
        $this->assertNull($journey->completed_at);

        // The evening after the end date: deactivate, renew, then the journey command.
        $this->travelTo('2026-10-26 21:30:00');
        Contract::query()->whereKey($contract)->update(['is_active' => false]);
        $this->artisan('contracts:process-auto-renewals')->assertSuccessful();
        $this->artisan('renewals:track-journeys')->assertSuccessful();

        $successor = Contract::query()->where('employee_id', $contract->employee_id)->whereKeyNot($contract->id)->sole();
        $this->assertSame('2026-10-26', $successor->start_date->toDateString());
        $this->assertTrue($successor->leaveBalances()->exists());

        $journey->refresh();
        $this->assertNotNull($journey->completed_at);
        $this->assertSame(['decision' => 'done', 'successor' => 'done', 'leave' => 'done'], array_intersect_key(
            $this->states(app(RenewalJourneys::class)->steps($journey)),
            array_flip(['decision', 'successor', 'leave']),
        ));

        $completed = Activity::query()->where('log_name', 'renewal')->where('description', 'renewal_completed')->sole();
        $this->assertSame('renewed', $completed->properties['outcome']);
    }

    public function test_renew_with_auto_renew_off_points_hr_to_the_manual_renewal(): void
    {
        $contract = $this->contract('manual', ['end_date' => '2026-10-25', 'auto_renew' => false]);
        $journey = $this->journey($contract);

        $this->panel($this->f['hr'], $journey->employee)
            ->call('decideRenew', $journey->id)
            ->assertViewHas('rows', fn ($rows) => $this->byKey($rows[0]['steps'])['successor']['state'] === 'todo'
                && $this->byKey($rows[0]['steps'])['successor']['note'] === 'renew-by-hand')
            ->assertSee(route('contracts.renew', $contract->id), false);

        $this->assertFalse($contract->fresh()->auto_renew, 'The decision itself changes no contract.');
    }

    // ── The leave-balance gap (journeys.md §3.2, open point 3) ───────

    public function test_an_auto_renewed_contract_gets_its_leave_balances(): void
    {
        $this->seedLeaveConfiguration();
        $contract = $this->contract('balances', [
            'start_date' => '2026-01-01', 'end_date' => '2026-09-30', 'is_active' => false,
        ]);

        $this->artisan('contracts:process-auto-renewals')->assertSuccessful();

        $renewal = Contract::query()->where('employee_id', $contract->employee_id)->whereKeyNot($contract->id)->sole();
        $types = LeaveBalance::query()->where('contract_id', $renewal->id)->pluck('type_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains(LeaveTypeCases::Annual, $types);
        $this->assertContains(LeaveTypeCases::FullSick, $types);
        $this->assertSame(0, LeaveBalance::query()->where('contract_id', $contract->id)->count(), 'The source is untouched.');

        // A rerun renews nothing and adds no second set.
        $count = LeaveBalance::query()->count();
        $this->artisan('contracts:process-auto-renewals')->assertSuccessful();
        $this->assertSame($count, LeaveBalance::query()->count());
    }

    public function test_an_auto_renewal_whose_leave_cannot_be_priced_still_renews(): void
    {
        // No annual-leave rule on file: LeaveBalanceService cannot price it.
        $contract = $this->contract('unpriced', [
            'start_date' => '2026-01-01', 'end_date' => '2026-09-30', 'is_active' => false,
        ]);

        $this->artisan('contracts:process-auto-renewals')->assertSuccessful();

        $this->assertSame(2, Contract::query()->where('employee_id', $contract->employee_id)->count());
    }

    // ── Undo ─────────────────────────────────────────────────────────

    public function test_undoing_a_stop_switches_auto_renew_back_on_and_reopens_the_journey(): void
    {
        $contract = $this->contract('undo', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);

        $panel = $this->panel($this->f['hr'], $journey->employee)
            ->call('stop', $journey->id)
            ->assertSee('data-testid="renewal-undo"', false)
            ->call('confirmUndo', $journey->id)
            ->assertDispatched('confirm-dialog', method: 'undo', payload: $journey->id)
            ->call('undo', $journey->id);

        $journey->refresh();
        $this->assertNull($journey->decision);
        $this->assertNull($journey->decided_by);
        $this->assertNull($journey->completed_at);
        $this->assertTrue($contract->fresh()->auto_renew);

        $undone = Activity::query()->where('log_name', 'renewal')->where('description', 'decision_undone')->sole();
        $this->assertSame('stop', $undone->properties['from']);
        $this->assertTrue($undone->properties['auto_renew_restored']);
        $this->assertSame(1, $this->logged('renewal_reopened'));

        $panel->assertSee('data-testid="renewal-renew"', false);
    }

    public function test_undoing_a_stop_leaves_auto_renew_off_when_it_was_already_off(): void
    {
        $contract = $this->contract('already-off', ['end_date' => '2026-10-25', 'auto_renew' => false]);
        $journey = $this->journey($contract);
        $journeys = app(RenewalJourneys::class);

        $this->assertTrue($journeys->decide($journey, RenewalJourney::DECISION_STOP, $this->f['hr']));
        $this->assertFalse($journey->fresh()->decision_switched_auto_renew);
        $this->assertTrue($journeys->undo($journey, $this->f['hr']));

        $this->assertFalse($contract->fresh()->auto_renew);
    }

    public function test_a_decision_cannot_be_undone_once_the_new_contract_exists(): void
    {
        $contract = $this->contract('too-late', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);
        $journeys = app(RenewalJourneys::class);
        $journeys->decide($journey, RenewalJourney::DECISION_RENEW, $this->f['hr']);
        $this->successor($contract);

        $this->assertFalse($journeys->undo($journey, $this->f['hr']));
        $this->assertSame(RenewalJourney::DECISION_RENEW, $journey->fresh()->decision);
    }

    // ── Authority ────────────────────────────────────────────────────

    public function test_hr_of_another_city_gets_403(): void
    {
        $journey = $this->journey($this->contract('scoped', ['end_date' => '2026-10-25']));
        $outsider = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['contracts.update', 'contracts.view', 'employees.update']);

        Livewire::actingAs($outsider)
            ->test(RenewalJourneyPanel::class, ['employee' => $journey->employee])
            ->assertForbidden();

        foreach ([['decideRenew'], ['confirmStop'], ['stop'], ['confirmUndo'], ['undo']] as [$method]) {
            $panel = $this->panel($this->f['hr'], $journey->employee);
            $this->actingAs($outsider);

            $panel->call($method, $journey->id)->assertForbidden();
        }

        $this->assertNull($journey->fresh()->decision);
        $this->assertTrue($journey->contract->fresh()->auto_renew);
        $this->assertSame(0, Activity::query()->where('log_name', 'renewal')->where('description', '!=', 'renewal_started')->count());
    }

    public function test_a_role_without_contracts_update_is_refused(): void
    {
        $journey = $this->journey($this->contract('no-update', ['end_date' => '2026-10-25']));

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('contracts.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hr = $this->f['hr']->fresh();

        $this->panel($hr, $journey->employee)
            ->assertDontSee('data-testid="renewal-journey"', false)
            ->call('decideRenew', $journey->id)->assertForbidden();

        $this->panel($hr, $journey->employee)->call('stop', $journey->id)->assertForbidden();

        $this->assertNull($journey->fresh()->decision);
        $this->assertTrue($journey->contract->fresh()->auto_renew);
    }

    public function test_a_role_that_is_not_hr_is_refused_even_with_contracts_update(): void
    {
        $journey = $this->journey($this->contract('not-hr', ['end_date' => '2026-10-25']));
        $generalManager = $this->makeUser($this->f['city'], 'general-manager', ['contracts.update', 'contracts.view', 'employees.update']);

        $this->panel($generalManager, $journey->employee)
            ->assertDontSee('data-testid="renewal-journey"', false)
            ->call('stop', $journey->id)
            ->assertForbidden();

        $this->assertTrue($journey->contract->fresh()->auto_renew);
    }

    public function test_a_journey_of_another_employee_cannot_be_reached_through_this_panel(): void
    {
        $mine = $this->journey($this->contract('mine', ['end_date' => '2026-10-25']));
        $theirs = $this->journey($this->contract('theirs', ['end_date' => '2026-10-25']));

        $this->panel($this->f['hr'], $mine->employee)
            ->call('decideRenew', $theirs->id)
            ->assertNotFound();

        $this->assertNull($theirs->fresh()->decision);
    }

    // ── Audit and idempotency ────────────────────────────────────────

    public function test_the_hr_officer_who_acts_is_recorded_as_causer(): void
    {
        $journey = $this->journey($this->contract('audit', ['end_date' => '2026-10-25']));
        $colleague = $this->makeUser($this->f['city'], 'HR-ALP', ['contracts.update', 'employees.update']);

        $this->panel($colleague, $journey->employee)
            ->call('stop', $journey->id)
            ->call('undo', $journey->id)
            ->call('decideRenew', $journey->id);

        foreach (['decision_recorded', 'decision_undone'] as $event) {
            foreach (Activity::query()->where('log_name', 'renewal')->where('description', $event)->get() as $entry) {
                $this->assertSame($colleague->id, (int) $entry->causer_id, $event);
                $this->assertSame(User::class, $entry->causer_type, $event);
                $this->assertSame(RenewalJourney::class, $entry->subject_type, $event);
                $this->assertSame($journey->id, (int) $entry->subject_id, $event);
                $this->assertSame($journey->contract_id, (int) $entry->properties['contract_id'], $event);
            }
        }

        $this->assertSame(2, $this->logged('decision_recorded'));
        $this->assertSame(1, $this->logged('decision_undone'));
    }

    public function test_repeating_a_decision_changes_nothing_and_logs_it_once(): void
    {
        $journey = $this->journey($this->contract('twice', ['end_date' => '2026-10-25']));
        $journeys = app(RenewalJourneys::class);

        $this->assertTrue($journeys->decide($journey, RenewalJourney::DECISION_RENEW, $this->f['hr']));
        $this->assertFalse($journeys->decide($journey, RenewalJourney::DECISION_RENEW, $this->f['hr']));
        $this->assertFalse($journeys->decide($journey, RenewalJourney::DECISION_STOP, $this->f['hr']), 'A second decision needs an undo first.');
        $this->assertFalse($journeys->decide($journey, 'maybe', $this->f['hr']));
        $this->assertTrue($journey->contract->fresh()->auto_renew);

        $this->assertTrue($journeys->undo($journey, $this->f['hr']));
        $this->assertFalse($journeys->undo($journey, $this->f['hr']));

        // Through the panel: a double click is one change.
        $this->panel($this->f['hr'], $journey->employee)
            ->call('stop', $journey->id)
            ->call('stop', $journey->id);

        $this->assertSame(2, $this->logged('decision_recorded'));
        $this->assertSame(1, $this->logged('decision_undone'));
        $this->assertSame(1, RenewalJourney::query()->count());
    }

    // ── Pay ──────────────────────────────────────────────────────────

    public function test_no_salary_reaches_the_panel_html(): void
    {
        $this->seedLeaveConfiguration();
        $contract = $this->contract('salary', ['end_date' => '2026-10-25']);
        $journey = $this->journey($contract);
        app(RenewalJourneys::class)->decide($journey, RenewalJourney::DECISION_RENEW, $this->f['hr']);
        $this->successor($contract);

        $panel = $this->panel($this->f['hr'], $journey->employee)
            ->assertSee('data-testid="renewal-journey"', false);

        $html = $panel->html();
        $this->assertStringNotContainsString((string) self::SALARY, $html);
        $this->assertStringNotContainsString(number_format(self::SALARY), $html);
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function panel(User $user, Employee $employee): Testable
    {
        return Livewire::actingAs($user)->test(RenewalJourneyPanel::class, ['employee' => $employee]);
    }

    private function journey(Contract $contract): RenewalJourney
    {
        $journey = app(RenewalJourneys::class)->startForContract($contract->fresh());
        $this->assertNotNull($journey);

        return $journey->load('employee', 'contract');
    }

    /** @return array<string, string> step key => state */
    private function states(array $steps): array
    {
        return collect($steps)->pluck('state', 'key')->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function byKey(array $steps): array
    {
        return collect($steps)->keyBy('key')->all();
    }

    private function logged(string $event): int
    {
        return Activity::query()->where('log_name', 'renewal')->where('description', $event)->count();
    }

    private function contract(string $username, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($this->f['city'], 'renewal.'.$username);

        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $this->f['position']->id,
            'contract_type_id' => $this->f['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary' => self::SALARY,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides));
    }

    /** A renewal entered by hand: same employee and position, starting the day after. */
    private function successor(Contract $source): Contract
    {
        $renewal = $source->replicate(['id', 'created_at', 'updated_at']);
        $renewal->start_date = $source->end_date->copy()->addDay()->toDateString();
        $renewal->end_date = $source->end_date->copy()->addMonths(6)->toDateString();
        $renewal->save();

        return $renewal;
    }

    private function seedLeaveConfiguration(): void
    {
        AnnualLeaveRule::query()->create(['days' => 14, 'day_per_month' => 1.5, 'years_of_volunteering' => 0]);

        // base_days = 12 keeps the gender/marital-specific branch empty.
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }
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
        $trialType = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.update', 'contracts.view', 'contracts.create', 'employees.update']);

        return compact('city', 'position', 'annualType', 'trialType', 'hr');
    }
}
