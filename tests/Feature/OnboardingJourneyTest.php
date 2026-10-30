<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Employee\OnboardingJourneyPanel;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\OnboardingJourney;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OrientationOverdue;
use App\Services\DigitalIdManager;
use App\Services\EmployeeActivation;
use App\Services\LeaveBalanceService;
use App\Services\OnboardingJourneys;
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
 * D4-I: the onboarding journey of a new volunteer (DEC-09,
 * docs/ux/journeys.md). One section per acceptance criterion.
 */
class OnboardingJourneyTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const STEPS_FOR_A_DRAFT = [
        'record' => 'todo', 'contract' => 'waiting', 'account' => 'waiting', 'card' => 'waiting', 'orientation' => 'waiting',
    ];

    private const STEPS_FOR_AN_AWAITING_VOLUNTEER = [
        'record' => 'done', 'contract' => 'todo', 'account' => 'waiting', 'card' => 'waiting', 'orientation' => 'waiting',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
        $this->travelTo(now()->setTime(10, 0));
    }

    // ── AC-D4-I-01: the first contract starts the journey; the panel follows it ──

    public function test_the_first_real_contract_starts_one_journey_due_fourteen_days_after_its_start(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->awaiting($fixtures, 'd4.first');
        $start = now()->addMonth()->startOfMonth();

        $this->createContract($fixtures, $volunteer, ['startDate' => $start->toDateString()]);

        $journey = OnboardingJourney::query()->sole();
        $this->assertSame($volunteer->id, $journey->employee_id);
        $this->assertSame(Contract::query()->sole()->id, $journey->contract_id);
        $this->assertSame($start->copy()->addDays(14)->toDateString(), $journey->orientation_due_on->toDateString());
        $this->assertNull($journey->completed_at);

        $started = Activity::query()->where('log_name', 'onboarding')->sole();
        $this->assertSame('onboarding_started', $started->description);
        $this->assertSame($fixtures['hr']->id, (int) $started->causer_id, 'The HR officer who saved the contract started it.');
    }

    public function test_the_panel_shows_the_five_steps_for_a_draft(): void
    {
        $fixtures = $this->contractFixtures();
        $draft = $this->makeDraft($fixtures['city']);

        $this->panel($fixtures['hr'], $draft)
            ->assertSee(__('employees.journey.title'))
            ->assertViewHas('steps', fn ($steps) => $this->states($steps) === self::STEPS_FOR_A_DRAFT)
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['contract']['blocked_by'] === 'record')
            ->assertDontSee('data-testid="card-handed-over"', false)
            ->assertDontSee('data-testid="orientation-done"', false);
    }

    public function test_the_panel_shows_the_five_steps_for_a_volunteer_awaiting_a_contract(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->awaiting($fixtures, 'd4.awaiting');

        $this->panel($fixtures['hr'], $volunteer)
            ->assertSee(__('employees.journey.title'))
            ->assertViewHas('steps', fn ($steps) => $this->states($steps) === self::STEPS_FOR_AN_AWAITING_VOLUNTEER)
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['card']['blocked_by'] === 'contract')
            ->assertSee(__('employees.onboarding.create-contract'));
    }

    public function test_existing_active_staff_without_a_journey_see_nothing(): void
    {
        $fixtures = $this->contractFixtures();

        $this->panel($fixtures['hr'], $fixtures['employee'])
            ->assertViewHas('steps', fn ($steps) => $steps === null)
            ->assertDontSee('data-testid="onboarding-journey"', false)
            ->assertDontSee(__('employees.journey.title'));

        $this->assertSame(0, OnboardingJourney::query()->count());
    }

    public function test_after_the_contract_the_panel_waits_on_hr_for_the_card_and_the_orientation(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.started');

        $this->panel($fixtures['hr'], $volunteer)
            ->assertViewHas('steps', fn ($steps) => $this->states($steps) === [
                'record' => 'done', 'contract' => 'done', 'account' => 'done', 'card' => 'todo', 'orientation' => 'todo',
            ])
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['orientation']['due']->toDateString() === now()->addDays(14)->toDateString())
            ->assertSee('data-testid="card-handed-over"', false)
            ->assertDontSee('data-testid="orientation-done"', false);
    }

    public function test_card_handed_over_and_orientation_held_completes_the_journey_and_an_undo_reopens_it(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.complete');

        $panel = $this->panel($fixtures['hr'], $volunteer)
            ->call('markCardHandedOver')
            ->set('orientationDate', now()->toDateString())
            ->call('scheduleOrientation')
            ->assertHasNoErrors()
            ->assertSee('data-testid="orientation-done"', false)
            ->call('completeOrientation');

        $journey = OnboardingJourney::query()->sole();
        $this->assertNotNull($journey->card_handed_over_at);
        $this->assertSame($fixtures['hr']->id, (int) $journey->card_handed_over_by);
        $this->assertNotNull($journey->orientation_completed_at);
        $this->assertSame($fixtures['hr']->id, (int) $journey->orientation_completed_by);
        $this->assertNotNull($journey->completed_at);

        $panel->assertViewHas('steps', fn ($steps) => collect($steps)->every(fn ($step) => $step['state'] === 'done'));

        // Undo: confirmation first, then the step and the journey reopen.
        $panel->call('confirmReopen', 'card')
            ->assertDispatched('confirm-dialog', method: 'reopen', payload: 'card')
            ->call('reopen', 'card');

        $journey->refresh();
        $this->assertNull($journey->card_handed_over_at);
        $this->assertNull($journey->card_handed_over_by);
        $this->assertNull($journey->completed_at);
        $this->assertNotNull($journey->orientation_completed_at, 'Only the reopened step is undone.');
        $this->assertSame(1, $this->logged('step_reopened'));
    }

    public function test_an_orientation_scheduled_in_the_past_can_be_recorded_as_held(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.past');
        $journeys = app(OnboardingJourneys::class);

        $this->assertTrue($journeys->scheduleOrientation($volunteer, now()->subDays(2), $fixtures['hr']));
        $this->assertTrue($journeys->completeOrientation($volunteer, $fixtures['hr']));
        $this->assertTrue($journeys->markCardHandedOver($volunteer->fresh(), $fixtures['hr']));

        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);
    }

    // ── AC-D4-I-02: one journey, idempotent steps ────────────────────

    public function test_a_retried_activation_or_a_second_contract_starts_no_second_journey(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.retry');
        $first = Contract::query()->sole();

        $this->assertSame(EmployeeActivation::RESULT_NONE, app(EmployeeActivation::class)->activateForContract($first));

        $second = $this->realContract($volunteer, $fixtures, now()->addMonths(2)->startOfMonth());
        $this->assertSame(EmployeeActivation::RESULT_NONE, app(EmployeeActivation::class)->activateForContract($second));
        $this->assertNull(app(OnboardingJourneys::class)->startForContract($volunteer->fresh(), $second));

        $this->assertSame(1, OnboardingJourney::query()->count());
        $this->assertSame($first->id, OnboardingJourney::query()->sole()->contract_id);
        $this->assertSame(1, $this->logged('onboarding_started'));
    }

    public function test_repeating_a_step_changes_nothing_and_logs_it_once(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.twice');
        $journeys = app(OnboardingJourneys::class);

        $this->assertTrue($journeys->markCardHandedOver($volunteer, $fixtures['hr']));
        $handedOverAt = OnboardingJourney::query()->sole()->card_handed_over_at;
        $this->travel(5)->minutes();
        $this->assertFalse($journeys->markCardHandedOver($volunteer, $fixtures['hr']));
        $this->assertEquals($handedOverAt, OnboardingJourney::query()->sole()->card_handed_over_at, 'The first mark stands.');

        $this->assertTrue($journeys->scheduleOrientation($volunteer, now(), $fixtures['hr']));
        $this->assertFalse($journeys->scheduleOrientation($volunteer, now(), $fixtures['hr']));

        $this->assertTrue($journeys->completeOrientation($volunteer, $fixtures['hr']));
        $this->assertFalse($journeys->completeOrientation($volunteer, $fixtures['hr']));

        $this->assertTrue($journeys->reopen($volunteer, 'card', $fixtures['hr']));
        $this->assertFalse($journeys->reopen($volunteer, 'card', $fixtures['hr']));

        foreach (['card_handed_over', 'orientation_scheduled', 'orientation_completed', 'step_reopened'] as $event) {
            $this->assertSame(1, $this->logged($event), $event);
        }

        // The same through the panel: a double click is one change.
        $this->panel($fixtures['hr'], $volunteer)
            ->call('markCardHandedOver')
            ->call('markCardHandedOver');

        $this->assertSame(2, $this->logged('card_handed_over'), 'One for the first mark above, one for the panel.');
    }

    public function test_a_draft_type_reservation_starts_nothing(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->awaiting($fixtures, 'd4.reserved');
        $reservation = $this->reserve($volunteer, $fixtures);

        $this->assertSame(EmployeeActivation::RESULT_NONE, app(EmployeeActivation::class)->activateForContract($reservation));
        $this->assertNull(app(OnboardingJourneys::class)->startForContract($volunteer, $reservation));

        $this->assertSame(0, OnboardingJourney::query()->count());
        $this->assertFalse($volunteer->fresh()->is_active);
        $this->panel($fixtures['hr'], $volunteer->fresh())
            ->assertViewHas('steps', fn ($steps) => $this->states($steps) === self::STEPS_FOR_AN_AWAITING_VOLUNTEER);
    }

    public function test_upgrading_a_reservation_starts_the_journey(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->awaiting($fixtures, 'd4.upgraded');
        $reservation = $this->reserve($volunteer, $fixtures);

        $this->createContract($fixtures, $volunteer, ['draftContractId' => $reservation->id]);

        $this->assertSame($reservation->id, OnboardingJourney::query()->sole()->contract_id);
    }

    // ── AC-D4-I-03: city scope, audit, and the rules of each step ────

    public function test_hr_of_another_city_is_refused_every_panel_action(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.scoped');
        $outsider = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update', 'contracts.create', 'contracts.view']);

        // Not even the panel.
        Livewire::actingAs($outsider)
            ->test(OnboardingJourneyPanel::class, ['employee' => $volunteer])
            ->assertForbidden();

        // Nor an action on a panel that was opened before (a role or city switch since).
        $calls = [
            ['markCardHandedOver'],
            ['scheduleOrientation'],
            ['completeOrientation'],
            ['confirmReopen', 'card'],
            ['reopen', 'card'],
            ['reopen', 'orientation'],
        ];

        foreach ($calls as $call) {
            $panel = $this->panel($fixtures['hr'], $volunteer)->set('orientationDate', now()->toDateString());
            $this->actingAs($outsider);

            $panel->call(...$call)->assertForbidden();
        }

        $journey = OnboardingJourney::query()->sole();
        $this->assertNull($journey->card_handed_over_at);
        $this->assertNull($journey->orientation_scheduled_for);
        $this->assertSame(0, Activity::query()->where('log_name', 'onboarding')->where('description', '!=', 'onboarding_started')->count());
    }

    public function test_the_hr_officer_who_acts_is_recorded_as_causer(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.audit');
        $colleague = $this->makeUser($fixtures['city'], 'HR-ALP', ['employees.update']);

        $this->panel($colleague, $volunteer)
            ->call('markCardHandedOver')
            ->set('orientationDate', now()->toDateString())
            ->call('scheduleOrientation')
            ->call('completeOrientation')
            ->call('reopen', 'orientation');

        $journey = OnboardingJourney::query()->sole();

        foreach (['card_handed_over', 'orientation_scheduled', 'orientation_completed', 'step_reopened'] as $event) {
            $entry = Activity::query()->where('log_name', 'onboarding')->where('description', $event)->sole();

            $this->assertSame($colleague->id, (int) $entry->causer_id, $event);
            $this->assertSame(User::class, $entry->causer_type, $event);
            $this->assertSame($journey->id, (int) $entry->subject_id, $event);
            $this->assertSame(OnboardingJourney::class, $entry->subject_type, $event);
            $this->assertSame($volunteer->id, (int) $entry->properties['employee_id'], $event);
        }
    }

    public function test_the_orientation_cannot_be_recorded_as_held_before_its_date(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.early');
        $journeys = app(OnboardingJourneys::class);

        // Not scheduled at all.
        $this->assertFalse($journeys->completeOrientation($volunteer, $fixtures['hr']));

        $panel = $this->panel($fixtures['hr'], $volunteer)
            ->set('orientationDate', now()->addDay()->toDateString())
            ->call('scheduleOrientation')
            ->assertHasNoErrors()
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['orientation']['data']['can_complete'] === false)
            ->assertDontSee('data-testid="orientation-done"', false)
            ->call('completeOrientation');

        $this->assertNull(OnboardingJourney::query()->sole()->orientation_completed_at);
        $this->assertSame(0, $this->logged('orientation_completed'));

        $this->travelTo(now()->addDay());

        $panel->call('completeOrientation');
        $this->assertNotNull(OnboardingJourney::query()->sole()->orientation_completed_at);
    }

    public function test_the_orientation_date_must_be_a_date(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.baddate');

        $this->panel($fixtures['hr'], $volunteer)
            ->set('orientationDate', 'next week')
            ->call('scheduleOrientation')
            ->assertHasErrors('orientationDate');

        $this->assertNull(OnboardingJourney::query()->sole()->orientation_scheduled_for);
    }

    public function test_the_card_cannot_be_marked_before_the_contract_has_started(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->awaiting($fixtures, 'd4.future');
        // Starts next month: no current contract, so no card link yet.
        $this->createContract($fixtures, $volunteer, ['startDate' => now()->addMonth()->startOfMonth()->toDateString()]);
        $volunteer->refresh();

        $this->assertFalse(app(OnboardingJourneys::class)->markCardHandedOver($volunteer, $fixtures['hr']));

        $this->panel($fixtures['hr'], $volunteer)
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['card']['state'] === 'problem')
            ->assertDontSee('data-testid="card-handed-over"', false)
            ->call('markCardHandedOver');

        $this->assertNull(OnboardingJourney::query()->sole()->card_handed_over_at);
        $this->assertSame(0, $this->logged('card_handed_over'));
    }

    public function test_the_card_cannot_be_marked_while_the_digital_card_is_disabled(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.disabled');
        $this->assertTrue(app(OnboardingJourneys::class)->digitalCardReady($volunteer));

        app(DigitalIdManager::class)->disable($volunteer, $fixtures['hr']);
        $volunteer->refresh();

        $this->assertFalse(app(OnboardingJourneys::class)->markCardHandedOver($volunteer, $fixtures['hr']));

        $this->panel($fixtures['hr'], $volunteer)
            ->assertViewHas('steps', fn ($steps) => $this->byKey($steps)['card']['state'] === 'problem'
                && $this->byKey($steps)['card']['note'] === 'employees.journey.card-not-valid')
            ->assertSee(__('employees.journey.card-not-valid'))
            ->call('markCardHandedOver');

        $this->assertNull(OnboardingJourney::query()->sole()->card_handed_over_at);
    }

    // ── Reminder: one in-app reminder to city HR per overdue orientation ──

    public function test_an_overdue_orientation_reminds_city_hr_once(): void
    {
        $fixtures = $this->contractFixtures();
        $volunteer = $this->startedJourney($fixtures, 'd4.overdue');
        $otherCityHr = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update']);
        $journey = OnboardingJourney::query()->sole();

        // Due today: not late yet.
        $this->travelTo(now()->addDays(14));
        $this->artisan('onboarding:send-orientation-reminders')->assertSuccessful();
        Notification::assertSentTimes(OrientationOverdue::class, 0);

        $this->travelTo(now()->addDay());
        $this->artisan('onboarding:send-orientation-reminders')->assertSuccessful();
        $this->artisan('onboarding:send-orientation-reminders')->assertSuccessful();

        $this->travelTo(now()->addDays(3));
        $this->artisan('onboarding:send-orientation-reminders')->assertSuccessful();

        Notification::assertSentToTimes($fixtures['hr'], OrientationOverdue::class, 1);
        Notification::assertSentTo($fixtures['hr'], OrientationOverdue::class, fn (OrientationOverdue $n) => $n->journey->is($journey));
        Notification::assertNotSentTo($otherCityHr, OrientationOverdue::class);
        Notification::assertNotSentTo($volunteer->account, OrientationOverdue::class);

        $payload = (new OrientationOverdue($journey->fresh()))->toArray($fixtures['hr']);
        $this->assertSame(['type' => 'onboarding_journey', 'id' => $journey->id, 'action' => null], $payload['subject']);
        $this->assertStringContainsString($journey->orientation_due_on->format('d-m-Y'), $payload['body']['en']);
    }

    public function test_the_reminder_skips_held_and_not_yet_due_orientations(): void
    {
        $fixtures = $this->contractFixtures();
        $held = $this->startedJourney($fixtures, 'd4.held');
        $journeys = app(OnboardingJourneys::class);
        $journeys->scheduleOrientation($held, now(), $fixtures['hr']);
        $journeys->completeOrientation($held, $fixtures['hr']);

        // Its contract starts later, so its orientation is not due yet.
        $notYetDue = $this->awaiting($fixtures, 'd4.later');
        $this->createContract($fixtures, $notYetDue, ['startDate' => now()->addMonths(2)->startOfMonth()->toDateString()]);

        $this->assertSame(2, OnboardingJourney::query()->count());

        $this->travelTo(now()->addDays(20));
        $this->artisan('onboarding:send-orientation-reminders')->assertSuccessful();

        Notification::assertNotSentTo($fixtures['hr'], OrientationOverdue::class);
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function panel(User $user, Employee $employee): Testable
    {
        return Livewire::actingAs($user)->test(OnboardingJourneyPanel::class, ['employee' => $employee]);
    }

    /** @return array<string, string> step key => state */
    private function states(?array $steps): ?array
    {
        return $steps === null ? null : collect($steps)->pluck('state', 'key')->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function byKey(array $steps): array
    {
        return collect($steps)->keyBy('key')->all();
    }

    private function logged(string $event): int
    {
        return Activity::query()->where('log_name', 'onboarding')->where('description', $event)->count();
    }

    private function awaiting(array $fixtures, string $username): Employee
    {
        return $this->makeEmployee($fixtures['city'], $username, ['is_active' => false]);
    }

    /** A volunteer whose first contract started today, through the composer. */
    private function startedJourney(array $fixtures, string $username): Employee
    {
        $volunteer = $this->awaiting($fixtures, $username);
        $this->createContract($fixtures, $volunteer, ['startDate' => now()->toDateString()]);

        $volunteer->refresh();
        $this->assertTrue($volunteer->is_active);
        $this->assertNotNull($volunteer->account);
        $this->assertNotNull($volunteer->onboardingJourney);

        return $volunteer;
    }

    private function createContract(array $fixtures, Employee $employee, array $overrides = []): void
    {
        $this->ignoreLeaveBalances();
        $payload = array_merge($this->contractPayload($fixtures), ['employeeId' => $employee->id], $overrides);
        // A contract may run one year at most from its start.
        $payload['endDate'] = $overrides['endDate'] ?? Carbon::parse($payload['startDate'])->addMonths(6)->toDateString();

        Livewire::actingAs($fixtures['hr'])->test(ContractCreate::class)
            ->call('submit', $payload)
            ->assertHasNoErrors();
    }

    private function realContract(Employee $employee, array $fixtures, Carbon $start): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function makeDraft(City $city): Employee
    {
        $draft = new Employee([
            'city_id' => $city->id,
            'first_name_en' => 'Draft', 'first_name_ar' => 'مسودة',
            'last_name_en' => 'Person', 'last_name_ar' => 'شخص',
            'father_name_en' => 'F', 'father_name_ar' => 'ف',
            'mother_name_en' => 'M', 'mother_name_ar' => 'م',
            'national_number' => (string) random_int(10000000000, 99999999999),
            'username' => 'd4.d.'.uniqid(),
        ]);
        $draft->forceFill(['record_status' => Employee::RECORD_DRAFT, 'is_active' => false, 'is_board_member' => false])->save();

        return $draft;
    }

    private function reserve(Employee $employee, array $fixtures): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::Draft,
            'is_active' => true,
        ]);
    }

    private function ignoreLeaveBalances(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->andReturnNull();
    }

    private function contractPayload(array $fixtures): array
    {
        return [
            'cityId' => $fixtures['city']->id,
            'employeeId' => $fixtures['employee']->id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $fixtures['position']->id,
            'draftContractId' => null,
            'contractTypeId' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'startDate' => now()->addMonth()->startOfMonth()->toDateString(),
            'endDate' => now()->addMonth()->startOfMonth()->addMonths(6)->toDateString(),
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'weeklyHours' => 40,
            'currency' => 1,
            'salary' => 100,
            'salaryPer' => 1,
        ];
    }

    /**
     * EmployeeOnboardingTest's fixtures, with a numeric program code so the
     * digital card has a link once the contract is current.
     */
    private function contractFixtures(): array
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
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $employee = $this->makeEmployee($city, 'd4-contract-employee');
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.view', 'employees.update']);

        return compact('city', 'center', 'position', 'program', 'employee', 'annualType', 'hr');
    }
}
