<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Employee\OffboardingJourneyPanel;
use App\Models\Center;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\OffboardingJourney;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LastContractEnded;
use App\Services\ContractAutoRenewalService;
use App\Services\OffboardingJourneys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * DEC-09 increment 2: the exit journey and the automatic deactivation of a
 * volunteer whose last contract has ended (docs/ux/journeys.md §3.3,
 * docs/ux/exit-journey-implementation.md).
 */
class OffboardingJourneyTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const COMMAND = 'employees:deactivate-departed';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
        config(['journeys.exit_grace_days' => 7]);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00', config('app.timezone')));
    }

    // ── Automatic deactivation ───────────────────────────────────────

    public function test_a_volunteer_is_deactivated_only_once_the_grace_period_after_the_last_contract_has_passed(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.grace');
        $end = today()->subDay();
        $contract = $this->contract($volunteer, $fixtures, today()->subMonths(6), $end, ['auto_renew' => false, 'is_active' => false]);

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertTrue($volunteer->fresh()->is_active, 'The day after the end: heads-up only.');

        $this->travelTo($end->copy()->addDays(6)->setTime(21, 45));
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertTrue($volunteer->fresh()->is_active, 'Six days after the end: still inside the grace period.');

        $this->travelTo($end->copy()->addDays(7)->setTime(21, 45));
        $this->artisan(self::COMMAND)->assertSuccessful();

        $volunteer->refresh();
        $this->assertFalse($volunteer->is_active);
        $this->assertFalse($volunteer->digital_id_enabled, 'EmployeeObserver disabled the card.');
        $this->assertFalse(
            $volunteer->account->roles()->wherePivot('is_active', true)->exists(),
            'AccessRevoker stood every role down.',
        );

        $revoked = Activity::query()->where('log_name', 'security')->where('description', 'access_revoked')->sole();
        $this->assertSame('employee_deactivated', $revoked->properties['reason']);

        $logged = Activity::query()->where('log_name', 'offboarding')->where('description', 'employee_deactivated')->sole();
        $this->assertSame('last_contract_ended', $logged->properties['reason']);
        $this->assertSame($contract->id, (int) $logged->properties['contract_id']);
        $this->assertSame($end->toDateString(), $logged->properties['ended_on']);
        $this->assertSame(7, (int) $logged->properties['grace_days']);
        $this->assertNull($logged->causer_id, 'The scheduler, not a person.');
    }

    public function test_the_grace_period_comes_from_config(): void
    {
        config(['journeys.exit_grace_days' => 3]);
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.config');
        $this->contract($volunteer, $fixtures, today()->subMonths(6), today()->subDays(3), ['auto_renew' => false, 'is_active' => false]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertFalse($volunteer->fresh()->is_active);
    }

    public function test_nobody_with_a_current_or_future_contract_is_deactivated(): void
    {
        $fixtures = $this->fixtures();

        $current = $this->volunteer($fixtures, 'exit.current');
        $this->contract($current, $fixtures, today()->subYear(), today()->subMonths(6), ['auto_renew' => false, 'is_active' => false]);
        $this->contract($current, $fixtures, today()->subMonths(6)->addDay(), today()->addMonth(), ['auto_renew' => false]);

        $future = $this->volunteer($fixtures, 'exit.future');
        $this->contract($future, $fixtures, today()->subYear(), today()->subMonth(), ['auto_renew' => false, 'is_active' => false]);
        $this->contract($future, $fixtures, today()->addMonth(), today()->addMonths(7), ['auto_renew' => false]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertTrue($current->fresh()->is_active);
        $this->assertTrue($future->fresh()->is_active);
        $this->assertSame(0, OffboardingJourney::query()->count());
        Notification::assertNothingSent();
    }

    public function test_an_auto_renewing_contract_is_never_a_departure_before_or_after_its_renewal(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.renewing');
        $ended = $this->contract($volunteer, $fixtures, today()->subMonths(6), today()->subDays(10), ['auto_renew' => true, 'is_active' => false]);

        // The renewal run has not made its successor yet (e.g. it failed that night).
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertTrue($volunteer->fresh()->is_active);

        app(ContractAutoRenewalService::class)->process(today());
        $this->assertTrue(Contract::query()->where('employee_id', $volunteer->id)->whereKeyNot($ended->id)->exists(), 'The successor was made.');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertTrue($volunteer->fresh()->is_active);
        $this->assertSame(0, OffboardingJourney::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_contract_saved_after_the_grace_period_but_before_the_run_wins(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.late-renewal');
        $this->contract($volunteer, $fixtures, today()->subYear(), today()->subDays(20), ['auto_renew' => false, 'is_active' => false]);
        $this->contract($volunteer, $fixtures, today(), today()->addMonths(6), ['auto_renew' => false]);

        $this->assertFalse(app(OffboardingJourneys::class)->deactivateDeparted($volunteer));
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertTrue($volunteer->fresh()->is_active);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.dry');
        $this->contract($volunteer, $fixtures, today()->subYear(), today()->subMonth(), ['auto_renew' => false, 'is_active' => false]);

        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain('DEACTIVATE #'.$volunteer->id)
            ->assertSuccessful();

        $this->assertTrue($volunteer->fresh()->is_active);
        $this->assertSame(0, OffboardingJourney::query()->count());
        Notification::assertNothingSent();
    }

    // ── The heads-up ─────────────────────────────────────────────────

    public function test_city_hr_gets_one_heads_up_when_the_last_contract_ends(): void
    {
        $fixtures = $this->fixtures();
        $otherCityHr = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update']);
        $volunteer = $this->volunteer($fixtures, 'exit.headsup');
        $end = today()->subDay();
        $this->contract($volunteer, $fixtures, today()->subMonths(6), $end, ['auto_renew' => false, 'is_active' => false]);

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->travelTo(now()->addDays(2));
        $this->artisan(self::COMMAND)->assertSuccessful();

        Notification::assertSentToTimes($fixtures['hr'], LastContractEnded::class, 1);
        Notification::assertNotSentTo($otherCityHr, LastContractEnded::class);
        Notification::assertNotSentTo($volunteer->account, LastContractEnded::class);

        Notification::assertSentTo($fixtures['hr'], LastContractEnded::class, function (LastContractEnded $notice) use ($volunteer, $end): bool {
            $payload = $notice->toArray($notice);

            return $notice->employee->is($volunteer)
                && str_contains($payload['body']['en'], $end->format('d-m-Y'))
                && str_contains($payload['body']['en'], $end->copy()->addDays(7)->format('d-m-Y'))
                && $payload['subject'] === ['type' => 'offboarding_journey', 'id' => OffboardingJourney::query()->sole()->id, 'action' => null];
        });
    }

    // ── Starting the journey ─────────────────────────────────────────

    public function test_stopping_auto_renewal_on_the_last_contract_starts_one_journey(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.e1');
        $contract = $this->contract($volunteer, $fixtures, today()->subMonths(3), today()->addMonths(3), ['auto_renew' => true]);

        $this->actingAs($fixtures['hr']);
        $contract->update(['auto_renew' => false]);

        $journey = OffboardingJourney::query()->sole();
        $this->assertSame($volunteer->id, $journey->employee_id);
        $this->assertSame($contract->id, $journey->contract_id);
        $this->assertSame(OffboardingJourney::TRIGGER_AUTO_RENEW_STOPPED, $journey->trigger);
        $this->assertSame($fixtures['hr']->id, (int) $journey->started_by);

        // Switched back on and off again, then the contract runs out: still one journey.
        $contract->update(['auto_renew' => true]);
        $contract->update(['auto_renew' => false]);
        $this->travelTo(today()->addMonths(3)->addDays(2)->setTime(21, 45));
        $this->artisan('deactivate:contract')->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(1, OffboardingJourney::query()->count());
        $this->assertSame(1, $this->logged('offboarding_started'));
        $this->assertSame($fixtures['hr']->id, (int) Activity::query()->where('log_name', 'offboarding')->where('description', 'offboarding_started')->sole()->causer_id);
    }

    public function test_terminating_the_last_contract_starts_the_journey(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.e4');
        $contract = $this->contract($volunteer, $fixtures, today()->subMonths(3), today()->addMonths(3), ['auto_renew' => true]);

        $this->actingAs($fixtures['hr']);
        $contract->update(['terminated_at' => today()->toDateString(), 'is_active' => false]);

        $journey = OffboardingJourney::query()->sole();
        $this->assertSame(OffboardingJourney::TRIGGER_TERMINATED, $journey->trigger);

        // Saving the terminated contract again changes nothing.
        $contract->refresh()->update(['reason_of_termination' => 'Moved away']);
        $this->assertSame(1, OffboardingJourney::query()->count());
    }

    public function test_a_contract_that_is_not_the_last_starts_no_journey(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.two-contracts');
        $first = $this->contract($volunteer, $fixtures, today()->subMonths(3), today()->addMonths(3), ['auto_renew' => true]);
        $this->contract($volunteer, $fixtures, today()->subMonths(1), today()->addMonths(5), ['auto_renew' => true]);

        $this->actingAs($fixtures['hr']);
        $first->update(['auto_renew' => false]);
        $first->update(['terminated_at' => today()->toDateString(), 'is_active' => false]);

        $this->assertSame(0, OffboardingJourney::query()->count());
    }

    public function test_a_contract_that_simply_runs_out_starts_the_journey_from_the_daily_run(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.ran-out');
        // A trial never auto-renews.
        $trial = $this->contract($volunteer, $fixtures, today()->subMonths(3), today()->subDay(), ['contract_type_id' => $fixtures['trialType']->id, 'is_active' => false]);

        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();

        $journey = OffboardingJourney::query()->sole();
        $this->assertSame($trial->id, $journey->contract_id);
        $this->assertSame(OffboardingJourney::TRIGGER_CONTRACT_ENDED, $journey->trigger);
        $this->assertNull($journey->started_by);
        $this->assertSame(1, $this->logged('offboarding_started'));
    }

    // ── Step states ──────────────────────────────────────────────────

    public function test_each_step_is_read_from_its_own_record(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.steps');
        $end = today()->addDays(10);
        $contract = $this->contract($volunteer, $fixtures, today()->subMonths(3), $end, ['auto_renew' => true]);
        $laptop = $this->delivered($volunteer, $fixtures);
        $phone = $this->delivered($volunteer, $fixtures, ['returned_status' => 'pending', 'receiver_id' => $fixtures['hr']->employee_id]);

        $this->actingAs($fixtures['hr']);
        $contract->update(['auto_renew' => false]);

        // Before the end: equipment waits on HR (one item has no return requested), the rest follows the contract.
        $this->panel($fixtures['hr'], $volunteer)
            ->assertSee(__('offboarding.title'))
            ->assertViewHas('steps', fn ($steps) => $this->states($steps) === [
                'equipment' => 'todo', 'contract' => 'waiting', 'deactivated' => 'waiting', 'access' => 'waiting', 'interview' => 'todo',
            ])
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['equipment']['due']->toDateString() === $end->toDateString()
                && $this->byKey($steps)['deactivated']['due']->toDateString() === $end->copy()->addDays(7)->toDateString()
                && $this->byKey($steps)['deactivated']['blocked_by'] === 'contract'
                && $this->byKey($steps)['access']['blocked_by'] === 'deactivated'
                && $this->byKey($steps)['equipment']['data']['outstanding'] === 2
                && $this->byKey($steps)['equipment']['data']['unrequested'] === 1);

        // A return requested for the laptop too: now it waits on the receiver.
        $laptop->forceFill(['returned_status' => 'pending', 'receiver_id' => $fixtures['hr']->employee_id])->save();
        $this->assertSame(['waiting', 'receiver'], $this->stepState($volunteer, 'equipment'));

        // Both received: done (a finalized cycle deletes the row, which counts the same).
        $laptop->forceFill(['returned_status' => 'received'])->save();
        $phone->delete();
        $this->assertSame('done', $this->stepState($volunteer, 'equipment')[0]);

        // Past the end, and past the grace period without the run: the contract has ended, deactivation is late.
        $this->travelTo($end->copy()->addDays(8)->setTime(9, 0));
        $this->artisan('deactivate:contract')->assertSuccessful();
        $steps = $this->byKey(app(OffboardingJourneys::class)->steps($volunteer->fresh()));
        $this->assertSame('done', $steps['contract']['state']);
        $this->assertSame('problem', $steps['deactivated']['state']);
        $this->assertTrue($steps['deactivated']['late']);
        $this->assertSame('deactivation-overdue', $steps['deactivated']['note']);

        // The run deactivates: access and card follow, and the journey is complete.
        $this->artisan(self::COMMAND)->assertSuccessful();
        $steps = $this->byKey(app(OffboardingJourneys::class)->steps($volunteer->fresh()));
        $this->assertSame('done', $steps['deactivated']['state']);
        $this->assertSame('done', $steps['access']['state']);
        $this->assertNotNull(OffboardingJourney::query()->sole()->completed_at, 'The interview is optional.');
        $this->assertSame(1, $this->logged('offboarding_completed'));

        // A role switched back on by hand shows as a problem on the access step.
        $account = $volunteer->fresh()->account;
        $account->roles()->updateExistingPivot($account->roles()->pluck('roles.id')->all(), ['is_active' => true]);
        $steps = $this->byKey(app(OffboardingJourneys::class)->steps($volunteer->fresh()));
        $this->assertSame('problem', $steps['access']['state']);
        $this->assertSame('access-login-open', $steps['access']['note']);
    }

    public function test_the_exit_interview_can_be_marked_and_undone(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->departing($fixtures, 'exit.interview');

        $panel = $this->panel($fixtures['hr'], $volunteer)
            ->assertSee('data-testid="exit-interview-held"', false)
            ->call('markExitInterviewHeld');

        $journey = OffboardingJourney::query()->sole();
        $this->assertNotNull($journey->exit_interview_held_at);
        $this->assertSame($fixtures['hr']->id, (int) $journey->exit_interview_by);
        $panel->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['interview']['state'] === 'done');

        $panel->call('confirmReopen', 'interview')
            ->assertDispatched('confirm-dialog', method: 'reopen', payload: 'interview')
            ->call('reopen', 'interview');

        $journey->refresh();
        $this->assertNull($journey->exit_interview_held_at);
        $this->assertNull($journey->exit_interview_by);

        // Only the interview can be undone.
        $panel->call('confirmReopen', 'deactivated')->assertNotFound();
    }

    public function test_a_called_off_departure_shows_so_and_takes_no_marks(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->departing($fixtures, 'exit.called-off');

        Contract::query()->where('employee_id', $volunteer->id)->sole()->update(['auto_renew' => true]);

        $this->panel($fixtures['hr'], $volunteer)
            ->assertViewHas('calledOff', true)
            ->assertSee('data-testid="offboarding-called-off"', false)
            ->assertDontSee('data-testid="exit-interview-held"', false)
            ->call('markExitInterviewHeld');

        $this->assertNull(OffboardingJourney::query()->sole()->exit_interview_held_at);
        $this->assertFalse(app(OffboardingJourneys::class)->markExitInterviewHeld($volunteer, $fixtures['hr']));
    }

    public function test_staff_without_an_exit_journey_see_nothing(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->volunteer($fixtures, 'exit.none');
        $this->contract($volunteer, $fixtures, today()->subMonth(), today()->addMonths(5), ['auto_renew' => true]);

        $this->panel($fixtures['hr'], $volunteer)
            ->assertViewHas('steps', fn ($steps) => $steps === null)
            ->assertDontSee('data-testid="offboarding-journey"', false);
    }

    // ── Authority, audit, idempotency ────────────────────────────────

    public function test_hr_of_another_city_is_refused_the_panel_and_every_action(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->departing($fixtures, 'exit.scoped');
        $outsider = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update', 'contracts.view']);

        Livewire::actingAs($outsider)
            ->test(OffboardingJourneyPanel::class, ['employee' => $volunteer])
            ->assertForbidden();

        foreach ([['markExitInterviewHeld'], ['confirmReopen', 'interview'], ['reopen', 'interview']] as $call) {
            $panel = $this->panel($fixtures['hr'], $volunteer);
            $this->actingAs($outsider);

            $panel->call(...$call)->assertForbidden();
        }

        $this->assertNull(OffboardingJourney::query()->sole()->exit_interview_held_at);
        $this->assertSame(0, $this->logged('exit_interview_held'));
    }

    public function test_the_hr_officer_who_acts_is_recorded_as_causer(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->departing($fixtures, 'exit.audit');
        $colleague = $this->makeUser($fixtures['city'], 'HR-ALP', ['employees.update']);

        $this->panel($colleague, $volunteer)
            ->call('markExitInterviewHeld')
            ->call('reopen', 'interview');

        $journey = OffboardingJourney::query()->sole();

        foreach (['exit_interview_held', 'step_reopened'] as $event) {
            $entry = Activity::query()->where('log_name', 'offboarding')->where('description', $event)->sole();

            $this->assertSame($colleague->id, (int) $entry->causer_id, $event);
            $this->assertSame(User::class, $entry->causer_type, $event);
            $this->assertSame($journey->id, (int) $entry->subject_id, $event);
            $this->assertSame(OffboardingJourney::class, $entry->subject_type, $event);
            $this->assertSame($volunteer->id, (int) $entry->properties['employee_id'], $event);
        }
    }

    public function test_repeats_change_nothing_and_log_once(): void
    {
        $fixtures = $this->fixtures();
        $volunteer = $this->departing($fixtures, 'exit.twice');
        $journeys = app(OffboardingJourneys::class);
        $contract = Contract::query()->where('employee_id', $volunteer->id)->sole();

        $this->assertSame(
            OffboardingJourney::query()->sole()->id,
            $journeys->startForDeparture($contract, OffboardingJourney::TRIGGER_CONTRACT_ENDED)?->id,
        );

        $this->assertTrue($journeys->markExitInterviewHeld($volunteer, $fixtures['hr']));
        $heldAt = OffboardingJourney::query()->sole()->exit_interview_held_at;
        $this->travel(5)->minutes();
        $this->assertFalse($journeys->markExitInterviewHeld($volunteer, $fixtures['hr']));
        $this->assertEquals($heldAt, OffboardingJourney::query()->sole()->exit_interview_held_at, 'The first mark stands.');

        $this->assertTrue($journeys->reopen($volunteer, 'interview', $fixtures['hr']));
        $this->assertFalse($journeys->reopen($volunteer, 'interview', $fixtures['hr']));

        // The contract ends and the grace period passes; the run twice deactivates once.
        $this->travelTo(today()->addMonths(4)->setTime(21, 45));
        $this->artisan('deactivate:contract')->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertFalse($journeys->deactivateDeparted($volunteer->fresh()));

        $this->assertSame(1, OffboardingJourney::query()->count());
        foreach (['offboarding_started', 'exit_interview_held', 'step_reopened', 'offboarding_completed'] as $event) {
            $this->assertSame(1, $this->logged($event), $event);
        }
        $this->assertSame(1, Activity::query()->where('log_name', 'offboarding')->where('description', 'employee_deactivated')->count());
        $this->assertSame(1, Activity::query()->where('log_name', 'security')->where('description', 'access_revoked')->count());

        // A double click in the panel is one change.
        $this->panel($fixtures['hr'], $volunteer->fresh())
            ->call('markExitInterviewHeld')
            ->call('markExitInterviewHeld');
        $this->assertSame(2, $this->logged('exit_interview_held'), 'One above, one for the panel.');
    }

    // ── fixtures ─────────────────────────────────────────────────────

    public function test_the_weekly_sweep_is_no_longer_scheduled(): void
    {
        // Product owner, 2026-10-30: the new rule is the only automatic deactivation.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('employees:deactivate-departed')
            ->doesntExpectOutputToContain('deactivate:employee ')
            ->assertSuccessful();
    }

    private function panel(User $user, Employee $employee): Testable
    {
        return Livewire::actingAs($user)->test(OffboardingJourneyPanel::class, ['employee' => $employee]);
    }

    /** @return array<string, string>|null step key => state */
    private function states(?array $steps): ?array
    {
        return $steps === null ? null : collect($steps)->pluck('state', 'key')->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function byKey(array $steps): array
    {
        return collect($steps)->keyBy('key')->all();
    }

    /** @return array{0: string, 1: string} [state, owner] */
    private function stepState(Employee $employee, string $key): array
    {
        $step = $this->byKey(app(OffboardingJourneys::class)->steps($employee->fresh()))[$key];

        return [$step['state'], $step['owner']];
    }

    private function logged(string $event): int
    {
        return Activity::query()->where('log_name', 'offboarding')->where('description', $event)->count();
    }

    /** An active volunteer with a login whose one role is active. */
    private function volunteer(array $fixtures, string $username): Employee
    {
        $volunteer = $this->makeEmployee($fixtures['city'], $username);
        $this->makeUser($fixtures['city'], 'employee', [], $volunteer);

        return $volunteer->fresh();
    }

    /** A volunteer whose running contract HR has just stopped from auto-renewing (E1). */
    private function departing(array $fixtures, string $username): Employee
    {
        $volunteer = $this->volunteer($fixtures, $username);
        $contract = $this->contract($volunteer, $fixtures, today()->subMonths(3), today()->addMonths(3), ['auto_renew' => true]);

        $this->actingAs($fixtures['hr']);
        $contract->update(['auto_renew' => false]);
        $this->assertSame(1, OffboardingJourney::query()->where('employee_id', $volunteer->id)->count());

        return $volunteer->fresh();
    }

    private function contract(Employee $employee, array $fixtures, Carbon $start, Carbon $end, array $attributes = []): Contract
    {
        return Contract::query()->forceCreate(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'weekly_hours' => 40,
            'currency' => 1,
            'is_active' => true,
        ], $attributes));
    }

    private function delivered(Employee $employee, array $fixtures, array $attributes = []): Checklist
    {
        $checklist = new Checklist;
        $checklist->forceFill(array_merge([
            'employee_id' => $employee->id,
            'delivered_id' => $fixtures['hr']->employee_id,
            'delivered_status' => 'delivered',
            'delivered_at' => now()->subMonth(),
        ], $attributes))->save();

        return $checklist;
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
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => '010', 'is_active' => true]);
        $position = Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'title_en' => 'Program Officer', 'title_ar' => 'مسؤول برنامج',
            'max_emp' => 10, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $trialType = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial Contract', 'name_ar' => 'عقد تجربة']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.view', 'contracts.update', 'employees.update']);

        return compact('city', 'center', 'position', 'program', 'annualType', 'trialType', 'hr');
    }
}
