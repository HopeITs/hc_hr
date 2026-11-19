<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Onboarding\ManagerSession;
use App\Livewire\Onboarding\Policies;
use App\Livewire\Onboarding\RequiredPolicies;
use App\Models\Center;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\DefaultDeliverable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\OnboardingJourney;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyDocument;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ManagerOrientationOverdue;
use App\Notifications\OnboardingStepOverdue;
use App\Services\Inbox\OnboardingOwnerInbox;
use App\Services\OnboardingJourneys;
use App\Services\PolicyAcknowledgements;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Track 7 (meeting 19/11): the extended onboarding journey. The manager's
 * session, the delivery groups read from the checklist, the two-day and
 * fourteen-day deadlines, the Mug outside the journey, the policies the
 * volunteer agrees to, and completion over all of them. Synthetic data only.
 */
class OnboardingExtendedTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private User $hr;

    private User $managerUser;

    private Employee $managerEmployee;

    private Position $position;

    private ContractType $annual;

    private ContractType $trial;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
        $this->travelTo(now()->setTime(10, 0));

        $this->city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $this->city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $level = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => '010', 'is_active' => true]);
        $this->annual = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $this->trial = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);

        $managerPosition = $this->makePosition($center, $department, $level, $program, null);
        $this->position = $this->makePosition($center, $department, $level, $program, $managerPosition->id);

        $this->managerEmployee = $this->makeEmployee($this->city, 'manager.employee');
        $this->contract($this->managerEmployee, $managerPosition, now()->subMonths(2));
        $this->managerUser = $this->makeUser($this->city, 'manager', [], $this->managerEmployee);
        $this->hr = $this->makeUser($this->city, 'HR-ALP', ['employees.update', 'employees.view']);
    }

    // ── The manager's session ────────────────────────────────────────

    public function test_the_manager_schedules_then_records_the_session_and_it_is_due_in_fourteen_days(): void
    {
        $volunteer = $this->volunteer();
        $journeys = app(OnboardingJourneys::class);
        $journey = OnboardingJourney::query()->sole();

        $this->assertSame(now()->addDays(14)->toDateString(), $journey->orientation_due_on->toDateString());

        // Not scheduled yet, and not before its date.
        $this->assertFalse($journeys->completeManagerOrientation($volunteer, $this->managerUser));
        $this->assertTrue($journeys->scheduleManagerOrientation($volunteer, now()->addDay(), $this->managerUser));
        $this->assertFalse($journeys->scheduleManagerOrientation($volunteer, now()->addDay(), $this->managerUser), 'Same date again changes nothing.');
        $this->assertFalse($journeys->completeManagerOrientation($volunteer, $this->managerUser));

        $this->travelTo(now()->addDay());

        $this->assertTrue($journeys->completeManagerOrientation($volunteer, $this->managerUser));
        $this->assertFalse($journeys->completeManagerOrientation($volunteer, $this->managerUser), 'Recording twice changes nothing.');

        $journey->refresh();
        $this->assertSame($this->managerUser->id, (int) $journey->manager_orientation_completed_by);
        $this->assertSame(1, \Spatie\Activitylog\Models\Activity::query()->where('description', 'manager_orientation_completed')->count());
    }

    public function test_reopen_undoes_the_managers_session_and_reopens_a_complete_journey(): void
    {
        $volunteer = $this->volunteer();
        $this->completeEverything($volunteer);

        $journeys = app(OnboardingJourneys::class);
        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);

        $this->assertTrue($journeys->reopen($volunteer, 'manager_orientation', $this->hr));

        $journey = OnboardingJourney::query()->sole();
        $this->assertNull($journey->manager_orientation_completed_at);
        $this->assertNull($journey->completed_at);
    }

    public function test_only_the_direct_manager_or_city_hr_may_run_the_session(): void
    {
        $volunteer = $this->volunteer();
        $journeys = app(OnboardingJourneys::class);

        $colleague = $this->makeUser($this->city, 'manager', [], $this->makeEmployee($this->city, 'colleague'));
        $otherCityHr = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update']);

        $this->assertFalse($journeys->scheduleManagerOrientation($volunteer, now(), $colleague));
        $this->assertFalse($journeys->scheduleManagerOrientation($volunteer, now(), $otherCityHr));
        $this->assertNull(OnboardingJourney::query()->sole()->manager_orientation_scheduled_for);

        $this->assertTrue($journeys->scheduleManagerOrientation($volunteer, now(), $this->hr), 'City HR is the fallback.');
    }

    public function test_the_managers_page_is_for_the_manager_and_city_hr_only(): void
    {
        $volunteer = $this->volunteer();
        $colleague = $this->makeUser($this->city, 'manager', [], $this->makeEmployee($this->city, 'colleague.page'));

        Livewire::actingAs($colleague)->test(ManagerSession::class, ['employee' => $volunteer])->assertForbidden();

        Livewire::actingAs($this->managerUser)->test(ManagerSession::class, ['employee' => $volunteer])
            ->set('date', now()->toDateString())
            ->call('schedule')
            ->assertHasNoErrors()
            ->call('complete');

        $this->assertNotNull(OnboardingJourney::query()->sole()->manager_orientation_completed_at);

        // A page opened by a manager who then lost the position refuses every action.
        Livewire::actingAs($this->managerUser)->test(ManagerSession::class, ['employee' => $volunteer])
            ->set('date', 'next week')->call('schedule')->assertHasErrors('date');
    }

    public function test_the_managers_session_waits_in_the_managers_inbox_with_the_late_flag(): void
    {
        $volunteer = $this->volunteer();

        [$rows, $total] = app(OnboardingOwnerInbox::class)->collect($this->managerUser, 50);

        $this->assertSame(1, $total);
        $this->assertSame("onboarding-manager-schedule:{$volunteer->onboardingJourney->id}:journey", $rows->first()['key']);
        $this->assertSame(route('onboarding.manager-session', $volunteer), $rows->first()['url']);
        $this->assertFalse($rows->first()['late']);

        $this->travelTo(now()->addDays(15));
        $this->assertTrue(app(OnboardingOwnerInbox::class)->collect($this->managerUser, 50)[0]->first()['late']);

        // It shows in the page of the inbox the manager actually opens.
        $this->assertTrue(app(WorkInbox::class)->rows($this->managerUser)->contains(fn ($row) => str_starts_with($row['key'], 'onboarding-manager-')));

        // Not in the colleague's inbox.
        $colleague = $this->makeUser($this->city, 'manager', [], $this->makeEmployee($this->city, 'colleague.inbox'));
        $this->assertSame(0, app(OnboardingOwnerInbox::class)->collect($colleague, 50)[1]);
    }

    // ── Deliveries ───────────────────────────────────────────────────

    public function test_electronics_finish_only_with_every_item_the_city_names_delivered(): void
    {
        $volunteer = $this->volunteer();
        $deliverer = $this->makeEmployee($this->city, 'deliverer.one');
        $laptop = $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $deliverer);
        $mouse = $this->item('Mouse', ChecklistItem::GROUP_ELECTRONICS, $deliverer);

        $this->assertSame('todo', $this->stepState($volunteer, 'electronics'));

        $this->deliver($volunteer, $laptop, $deliverer);
        $this->assertSame('todo', $this->stepState($volunteer, 'electronics'));
        $missing = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'electronics')['data']['missing'];
        $this->assertSame(['Mouse'], $missing->pluck('name')->all());
        $this->assertSame($deliverer->id, $missing->first()['deliverer']->id);

        $this->deliver($volunteer, $mouse, $deliverer);
        $this->assertSame('done', $this->stepState($volunteer, 'electronics'));
    }

    public function test_a_pending_delivery_does_not_count_and_a_city_without_a_deliverer_is_a_problem(): void
    {
        $volunteer = $this->volunteer();

        $this->assertSame('problem', $this->stepState($volunteer, 'electronics'), 'Nobody is named for the electronics.');

        $deliverer = $this->makeEmployee($this->city, 'deliverer.two');
        $laptop = $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $deliverer);
        $this->deliver($volunteer, $laptop, $deliverer, 'pending');

        $this->assertSame('todo', $this->stepState($volunteer, 'electronics'));

        // Another city's deliverer for another item changes nothing here.
        $elsewhere = $this->makeCity('HMS', '002');
        $item = ChecklistItem::query()->create(['name' => 'Mobile', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_ELECTRONICS]);
        DefaultDeliverable::query()->create(['employee_id' => $deliverer->id, 'city_id' => $elsewhere->id, 'item_id' => $item->id]);

        $missing = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'electronics')['data']['missing'];
        $this->assertSame(['Laptop'], $missing->pluck('name')->all());
    }

    public function test_the_card_step_needs_the_printed_card_and_its_items(): void
    {
        $volunteer = $this->volunteer();
        $deliverer = $this->makeEmployee($this->city, 'deliverer.card');
        $cartoon = $this->item('ID Cartoon', ChecklistItem::GROUP_CARD, $deliverer);
        $journeys = app(OnboardingJourneys::class);

        $this->assertTrue($journeys->markCardHandedOver($volunteer, $this->hr));
        $this->assertSame('todo', $this->stepState($volunteer, 'card'), 'Handed over, but the card item is not delivered.');

        $this->deliver($volunteer, $cartoon, $deliverer);
        $this->assertSame('done', $this->stepState($volunteer, 'card'));
    }

    // ── Deadlines ────────────────────────────────────────────────────

    public function test_account_electronics_and_card_are_late_after_two_days_and_the_sessions_after_fourteen(): void
    {
        $volunteer = $this->volunteer(accountless: true);
        $late = fn (string $key) => collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', $key)['late'];

        $this->travelTo(now()->addDays(2));
        foreach (['account', 'electronics', 'card', 'orientation', 'manager_orientation'] as $key) {
            $this->assertFalse($late($key), "{$key} is due today, not late.");
        }

        $this->travelTo(now()->addDay());
        foreach (['account', 'electronics', 'card'] as $key) {
            $this->assertTrue($late($key), "{$key} is late on day three.");
        }
        foreach (['orientation', 'manager_orientation'] as $key) {
            $this->assertFalse($late($key), "{$key} has fourteen days.");
        }

        $this->travelTo(now()->addDays(11));
        $this->assertFalse($late('orientation'), 'Day fourteen is the last day.');
        $this->travelTo(now()->addDay());
        $this->assertTrue($late('orientation'));
        $this->assertTrue($late('manager_orientation'));
    }

    public function test_the_overdue_steps_remind_their_owner_every_week_until_done(): void
    {
        $volunteer = $this->volunteer(accountless: true);
        $deliverer = $this->makeEmployee($this->city, 'deliverer.late');
        $delivererUser = $this->makeUser($this->city, 'employee', [], $deliverer);
        $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $deliverer);

        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(now()->addDays(3));
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();

        // Account and card: HR. Electronics: the deliverer. Not the volunteer, not the manager yet.
        Notification::assertSentToTimes($this->hr, OnboardingStepOverdue::class, 2);
        Notification::assertSentToTimes($delivererUser, OnboardingStepOverdue::class, 1);
        Notification::assertNotSentTo($this->managerUser, OnboardingStepOverdue::class);
        Notification::assertNothingSentTo($this->managerUser);

        // Same week: nothing more. A week later: one more each.
        $this->travelTo(now()->addDays(4));
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        Notification::assertSentToTimes($delivererUser, OnboardingStepOverdue::class, 1);

        $this->travelTo(now()->addDays(3));
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        Notification::assertSentToTimes($delivererUser, OnboardingStepOverdue::class, 2);
        Notification::assertSentToTimes($this->hr, OnboardingStepOverdue::class, 4);

        // The manager hears about their own session once it is late (fourteen days).
        $this->travelTo(now()->addDays(8));
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->managerUser, ManagerOrientationOverdue::class, 1);
        Notification::assertNotSentTo($this->hr, ManagerOrientationOverdue::class);

        // Done steps stop the reminders.
        $journeys = app(OnboardingJourneys::class);
        $journeys->scheduleManagerOrientation($volunteer, now(), $this->managerUser);
        $journeys->completeManagerOrientation($volunteer, $this->managerUser);
        $this->travelTo(now()->addDays(7));
        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->managerUser, ManagerOrientationOverdue::class, 1);
    }

    // ── The Mug ──────────────────────────────────────────────────────

    public function test_the_mug_is_not_due_during_a_trial_and_never_holds_the_journey_back(): void
    {
        $deliverer = $this->makeEmployee($this->city, 'deliverer.mug');
        $delivererUser = $this->makeUser($this->city, 'employee', [], $deliverer);
        $mug = $this->item('Mug', ChecklistItem::GROUP_AFTER_PROBATION, $deliverer);
        $journeys = app(OnboardingJourneys::class);

        $volunteer = $this->volunteer(type: $this->trial);

        $this->assertFalse($journeys->mug($volunteer)['due'], 'A trial contract does not earn the mug.');
        $this->assertSame(0, app(OnboardingOwnerInbox::class)->collect($delivererUser, 50)[1]);
        $this->assertNotContains('mug', array_column($journeys->steps($volunteer->fresh()), 'key'));

        // The trial ends and a real contract starts: due now, in the deliverer's inbox and on the page.
        $this->contract($volunteer, $this->position, now()->addDay(), $this->annual, now()->addMonths(6));
        $this->travelTo(now()->addDays(2));

        $this->assertTrue($journeys->mug($volunteer->fresh())['due']);
        $rows = app(OnboardingOwnerInbox::class)->collect($delivererUser, 50)[0];
        $this->assertSame(['mug'], $rows->pluck('steps')->flatten(1)->pluck('key')->all());

        // The journey completes without it.
        $this->completeEverything($volunteer->fresh());
        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);

        // Delivered: gone from the inbox.
        $this->deliver($volunteer, $mug, $deliverer);
        $this->assertSame(0, app(OnboardingOwnerInbox::class)->collect($delivererUser, 50)[1]);
        $this->assertTrue($journeys->mug($volunteer->fresh())['delivered']);
    }

    public function test_a_volunteer_with_no_trial_gets_the_mug_with_the_first_contract(): void
    {
        $volunteer = $this->volunteer();

        $this->assertTrue(app(OnboardingJourneys::class)->mug($volunteer)['due']);
    }

    // ── Policies ─────────────────────────────────────────────────────

    public function test_without_a_required_policy_the_step_is_a_problem_not_done(): void
    {
        $volunteer = $this->volunteer();
        PolicyDocument::query()->create(['source' => PolicyDocument::SOURCE_PANEL, 'title' => 'Optional']);

        $step = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'policies');

        $this->assertSame('problem', $step['state']);
        $this->assertSame('policies-none-required', $step['note']);
    }

    public function test_the_volunteer_agrees_to_each_required_document_for_themselves_only(): void
    {
        $volunteer = $this->volunteer();
        $other = $this->volunteer('another.volunteer');
        $media = $this->policy('Media guideline', true);
        $code = $this->policy('Code of conduct', true);
        $optional = $this->policy('Optional', false);
        $volunteerUser = $volunteer->account;

        // The pending policies wait in the volunteer's own inbox, and in nobody else's.
        $rows = app(OnboardingOwnerInbox::class)->collect($volunteerUser, 50)[0];
        $this->assertSame(['policies'], $rows->pluck('steps')->flatten(1)->pluck('key')->all());
        $this->assertSame(route('onboarding.policies'), $rows->first()['url']);
        $this->assertSame(1, app(OnboardingOwnerInbox::class)->collect($other->account, 50)[1]);

        Livewire::actingAs($volunteerUser)->test(Policies::class)
            ->assertSee('Media guideline')->assertSee('Code of conduct')->assertDontSee('Optional')
            ->call('acknowledge', $media->id);

        $this->assertSame('todo', $this->stepState($volunteer, 'policies'));

        Livewire::actingAs($volunteerUser)->test(Policies::class)
            ->call('acknowledge', $media->id)      // twice: nothing new
            ->call('acknowledge', $optional->id)   // not required: nothing
            ->call('acknowledge', $code->id);

        $this->assertSame(2, PolicyAcknowledgement::query()->where('employee_id', $volunteer->id)->count());
        $this->assertSame(0, app(OnboardingOwnerInbox::class)->collect($volunteerUser, 50)[1], 'Nothing left to agree to.');
        $this->assertSame(0, PolicyAcknowledgement::query()->where('employee_id', $other->id)->count());
        $this->assertSame('done', $this->stepState($volunteer, 'policies'));
        $this->assertSame('todo', $this->stepState($other, 'policies'));
    }

    public function test_hr_marks_the_required_documents_and_nobody_else_may(): void
    {
        $doc = $this->policy('Media guideline', false);

        Livewire::actingAs($this->managerUser)->test(RequiredPolicies::class)->assertForbidden();

        Livewire::actingAs($this->hr)->test(RequiredPolicies::class)
            ->call('setRequired', $doc->id, true);

        $this->assertTrue($doc->fresh()->required_for_onboarding);
    }

    // ── Completion ───────────────────────────────────────────────────

    public function test_completion_follows_every_step_and_unwinds_when_one_is_undone(): void
    {
        $volunteer = $this->volunteer();
        $journeys = app(OnboardingJourneys::class);

        $this->completeEverything($volunteer, except: 'policies');
        $this->assertNull(OnboardingJourney::query()->sole()->completed_at, 'The policies are still open.');

        $media = PolicyDocument::query()->where('required_for_onboarding', true)->sole();
        app(PolicyAcknowledgements::class)->acknowledge($volunteer, $media);
        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at, 'The acknowledgement settles the journey.');
    }

    public function test_a_delivery_settles_the_journey_through_the_journey_service(): void
    {
        $volunteer = $this->volunteer();
        $this->completeEverything($volunteer, except: 'electronics');
        $this->assertNull(OnboardingJourney::query()->sole()->completed_at);

        $deliverer = Employee::query()->where('username', 'like', 'deliverer.%')->firstOrFail();
        $item = ChecklistItem::query()->where('name', 'Laptop')->firstOrFail();
        $checklist = Checklist::query()->create(['item_id' => $item->id, 'employee_id' => $volunteer->id, 'delivered_id' => $deliverer->id, 'receiver_id' => $volunteer->id, 'delivered_status' => 'pending']);

        app(\App\Services\ChecklistWorkflow::class)->markDelivered($checklist->id, $deliverer->id, null);

        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);
    }

    public function test_a_journey_completed_before_the_new_steps_stays_completed(): void
    {
        $volunteer = $this->volunteer();
        OnboardingJourney::query()->sole()->forceFill([
            'orientation_completed_at' => now()->subDay(),
            'card_handed_over_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ])->save();

        // A policy becomes required later: the old journey is not reopened by it.
        $this->policy('New policy', true);
        app(OnboardingJourneys::class)->settle($volunteer);

        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);
    }

    // ── fixtures ─────────────────────────────────────────────────────

    /** @return array{0: string} */
    private function stepState(Employee $employee, string $key): string
    {
        return collect(app(OnboardingJourneys::class)->steps($employee->fresh()))->firstWhere('key', $key)['state'];
    }

    private function makePosition(Center $center, Department $department, JobLevel $level, Program $program, ?int $managerId): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $this->city->id, 'department_id' => $department->id,
            'job_level_id' => $level->id, 'program_id' => $program->id,
            'title_en' => 'Officer', 'title_ar' => 'مسؤول',
            'max_emp' => 9, 'exist_emp' => 0, 'is_active' => true, 'direct_manager_id' => $managerId,
        ]);
    }

    private function contract(Employee $employee, Position $position, $start, ?ContractType $type = null, $end = null): Contract
    {
        $type ??= $this->annual;

        return Contract::query()->forceCreate([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $type->id,
            'type' => ContractTypeEnum::FullTime,
            'start_date' => $start->toDateString(),
            'end_date' => ($end ?? $start->copy()->addMonths(6))->toDateString(),
            'is_active' => true,
        ]);
    }

    /** A volunteer whose first contract started today, with the journey it starts (written the way EmployeeActivation does). */
    private function volunteer(string $username = 'new.volunteer', ?ContractType $type = null, bool $accountless = false): Employee
    {
        $volunteer = $this->makeEmployee($this->city, $username);
        $contract = $this->contract($volunteer, $this->position, now(), $type);

        if (! $accountless) {
            User::factory()->create(['employee_id' => $volunteer->id]);
        }

        app(OnboardingJourneys::class)->startForContract($volunteer, $contract);

        return $volunteer->fresh();
    }

    private function item(string $name, string $group, Employee $deliverer): ChecklistItem
    {
        $item = ChecklistItem::query()->create(['name' => $name, 'order' => 1, 'onboarding_group' => $group]);
        DefaultDeliverable::query()->create(['employee_id' => $deliverer->id, 'city_id' => $this->city->id, 'item_id' => $item->id]);

        return $item;
    }

    private function deliver(Employee $volunteer, ChecklistItem $item, Employee $deliverer, string $status = 'delivered'): void
    {
        Checklist::query()->create([
            'item_id' => $item->id, 'employee_id' => $volunteer->id, 'delivered_id' => $deliverer->id,
            'receiver_id' => $volunteer->id, 'delivered_status' => $status,
        ]);
    }

    private function policy(string $title, bool $required): PolicyDocument
    {
        return PolicyDocument::query()->create(['source' => PolicyDocument::SOURCE_PANEL, 'title' => $title, 'required_for_onboarding' => $required]);
    }

    /** Every step done, except the one named (which is left open, and for the policies, never agreed). */
    private function completeEverything(Employee $volunteer, ?string $except = null): void
    {
        $journeys = app(OnboardingJourneys::class);
        $deliverer = Employee::query()->where('username', 'deliverer.all')->first() ?? $this->makeEmployee($this->city, 'deliverer.all');

        $laptop = ChecklistItem::query()->where('name', 'Laptop')->first() ?? $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $deliverer);
        if ($except !== 'electronics') {
            $this->deliver($volunteer, $laptop, $deliverer);
        }

        $policy = PolicyDocument::query()->where('title', 'Media guideline')->first() ?? $this->policy('Media guideline', true);
        if ($except !== 'policies') {
            app(PolicyAcknowledgements::class)->acknowledge($volunteer, $policy);
        }

        $journeys->scheduleManagerOrientation($volunteer, now(), $this->managerUser);
        $journeys->completeManagerOrientation($volunteer, $this->managerUser);
        $journeys->scheduleOrientation($volunteer, now(), $this->hr);
        $journeys->completeOrientation($volunteer, $this->hr);
        $journeys->markCardHandedOver($volunteer->fresh(), $this->hr);
    }
}
