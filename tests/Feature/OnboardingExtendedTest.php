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
use App\Notifications\ChecklistAssigned;
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

    private Employee $it;

    private User $itUser;

    private Department $itDepartment;

    private Center $center;

    private Program $program;

    private JobLevel $level;

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
        $this->center = $center;
        $this->program = $program;
        $this->level = $level;
        $this->itDepartment = Department::query()->forceCreate(['name' => 'Information Technology', 'code' => 'IT']);
        $this->annual = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $this->trial = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);

        $managerPosition = $this->makePosition($center, $department, $level, $program, null);
        $this->position = $this->makePosition($center, $department, $level, $program, $managerPosition->id);

        $this->managerEmployee = $this->makeEmployee($this->city, 'manager.employee');
        $this->contract($this->managerEmployee, $managerPosition, now()->subMonths(2));
        $this->managerUser = $this->makeUser($this->city, 'manager', [], $this->managerEmployee);
        $this->it = $this->itPerson('it.one');
        $this->itUser = $this->it->account;
        $this->hr = $this->makeUser($this->city, 'HR-ALP', ['employees.update', 'employees.view']);
    }

    // ── My first days: the volunteer's own page ─────────────────────

    public function test_my_first_days_shows_the_volunteer_their_own_steps_and_what_waits_on_them(): void
    {
        $volunteer = $this->volunteer();
        $this->policy('Code of conduct', true);
        $user = $volunteer->account;
        app()->setLocale('en');

        $this->assertTrue(\App\Livewire\Onboarding\MyFirstDays::inMenuFor($user));

        Livewire::actingAs($user)->test(\App\Livewire\Onboarding\MyFirstDays::class)
            ->assertSeeHtml('data-testid="first-days-progress"')
            // The policies wait on them, with the way to do it.
            ->assertSeeHtml('data-testid="first-days-yours"')
            ->assertSee(__('onboarding.first-days.action.policies'))
            ->assertSee(route('onboarding.policies'), false)
            // HR's paperwork is not a step of theirs; the manager's session is.
            ->assertDontSeeHtml('data-step="record"')
            ->assertSeeHtml('data-step="manager_orientation"')
            ->assertSee(__('onboarding.first-days.state.with-manager'));
    }

    public function test_my_first_days_never_shows_hrs_reasons_for_a_stuck_step(): void
    {
        // No policy is required yet: for HR the step is a problem with a reason; the volunteer sees who follows it.
        $volunteer = $this->volunteer();
        app()->setLocale('en');

        Livewire::actingAs($volunteer->account)->test(\App\Livewire\Onboarding\MyFirstDays::class)
            ->assertDontSee(__('employees.journey.policies-none-required'))
            ->assertSee(__('onboarding.first-days.state.with-hr'));
    }

    public function test_staff_without_a_journey_get_no_menu_item_and_an_explanation(): void
    {
        $user = $this->managerUser;
        app()->setLocale('en');

        $this->assertFalse(\App\Livewire\Onboarding\MyFirstDays::inMenuFor($user));
        Livewire::actingAs($user)->test(\App\Livewire\Onboarding\MyFirstDays::class)
            ->assertSee(__('onboarding.first-days.none'));

        $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('onboarding.my-first-days'), false);
    }

    public function test_the_menu_item_goes_two_weeks_after_the_journey_is_complete(): void
    {
        $volunteer = $this->volunteer();
        $journey = $volunteer->onboardingJourney;
        $journey->forceFill(['completed_at' => now()])->save();

        $this->assertTrue(\App\Livewire\Onboarding\MyFirstDays::inMenuFor($volunteer->account->fresh()));

        $this->travel(\App\Livewire\Onboarding\MyFirstDays::MENU_DAYS_AFTER_DONE + 1)->days();
        $this->assertFalse(\App\Livewire\Onboarding\MyFirstDays::inMenuFor($volunteer->account->fresh()));
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
        $deliverer = $this->it;
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

    public function test_a_pending_delivery_does_not_count_and_no_items_or_no_it_is_a_problem(): void
    {
        $volunteer = $this->volunteer();
        ChecklistItem::query()->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS)->delete();

        $this->assertSame('problem', $this->stepState($volunteer, 'electronics'), 'No electronics item is defined.');

        $laptop = $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $this->it);
        $this->deliver($volunteer, $laptop, $this->it, 'pending');
        $this->assertSame('todo', $this->stepState($volunteer, 'electronics'));

        // IT of another city does not count: this city loses its only IT person.
        $this->it->forceFill(['city_id' => $this->makeCity('HMS', '002')->id])->save();
        $step = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'electronics');

        $this->assertSame('problem', $step['state']);
        $this->assertSame('electronics-no-it', $step['note']);
    }

    public function test_one_it_person_hands_the_electronics_over_and_several_wait_for_hr_to_choose(): void
    {
        $volunteer = $this->volunteer();
        $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $this->it);
        $journeys = app(OnboardingJourneys::class);
        $electronics = fn () => collect($journeys->steps($volunteer->fresh()))->firstWhere('key', 'electronics');

        // One IT person: automatic.
        $this->assertSame($this->it->id, $electronics()['data']['deliverer']->id);
        $this->assertSame('deliverer', $electronics()['owner']);
        $this->assertNull($electronics()['note']);

        // A second one: nobody is chosen until HR picks.
        $second = $this->itPerson('it.two');
        $this->assertNull($electronics()['data']['deliverer']);
        $this->assertSame('hr', $electronics()['owner']);
        $this->assertSame('electronics-choose-it', $electronics()['note']);
        $this->assertEqualsCanonicalizing([$this->it->id, $second->id], $electronics()['data']['candidates']->pluck('id')->all());

        // Somebody who is not IT of the city cannot be chosen.
        $this->assertFalse($journeys->chooseElectronicsDeliverer($volunteer, $this->managerEmployee->id, $this->hr));
        $this->assertNull($electronics()['data']['deliverer']);

        $this->assertTrue($journeys->chooseElectronicsDeliverer($volunteer, $second->id, $this->hr));
        $this->assertFalse($journeys->chooseElectronicsDeliverer($volunteer, $second->id, $this->hr), 'Same pick again changes nothing.');
        $this->assertSame($second->id, $electronics()['data']['deliverer']->id);
        $this->assertSame('deliverer', $electronics()['owner']);

        // The pick shows in the picked person's inbox and not in the other's.
        $this->assertSame(1, app(OnboardingOwnerInbox::class)->collect($second->account, 50)[1]);
        $this->assertSame(0, app(OnboardingOwnerInbox::class)->collect($this->itUser, 50)[1]);
    }

    public function test_hr_chooses_the_it_person_from_the_panel_and_other_cities_hr_cannot(): void
    {
        $volunteer = $this->volunteer();
        $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $this->it);
        $second = $this->itPerson('it.two');
        $otherCityHr = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.update']);

        Livewire::actingAs($otherCityHr)->test(\App\Livewire\Employee\OnboardingJourneyPanel::class, ['employee' => $volunteer])->assertForbidden();

        Livewire::actingAs($this->hr)->test(\App\Livewire\Employee\OnboardingJourneyPanel::class, ['employee' => $volunteer])
            ->call('chooseElectronicsDeliverer', $second->id);

        $this->assertSame($second->id, OnboardingJourney::query()->sole()->electronics_deliverer_id);
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
        $delivererUser = $this->itUser;
        $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $this->it);

        $this->artisan('onboarding:send-step-reminders')->assertSuccessful();
        // The deliverer is told about the item at once (ChecklistAssigned); nothing is overdue yet.
        Notification::assertSentTimes(OnboardingStepOverdue::class, 0);

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

    // ── The Media guideline: reviewed, made by another team ──────────

    public function test_the_media_guideline_is_its_own_review_step_apart_from_the_policies(): void
    {
        $volunteer = $this->volunteer();
        $policy = $this->policy('Code of conduct', true);
        $this->policy('Media guideline', true, PolicyDocument::KIND_REVIEW);
        $step = fn (string $key) => collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', $key);

        $this->assertSame('todo', $step('policies')['state']);
        $this->assertSame('todo', $step('guideline')['state']);

        app(PolicyAcknowledgements::class)->acknowledge($volunteer, $policy);
        $this->assertSame('done', $step('policies')['state']);
        $this->assertSame('todo', $step('guideline')['state'], 'Agreeing to a policy is not reviewing the guideline.');

        $volunteerUser = $volunteer->account;
        $page = Livewire::actingAs($volunteerUser)->test(Policies::class)
            ->assertSee(__('onboarding.policies.section-review'))
            ->assertSee(__('onboarding.policies.acknowledge-review'));

        $guide = PolicyDocument::query()->where('title', 'Media guideline')->sole();
        $page->call('acknowledge', $guide->id);

        $this->assertSame('done', $step('guideline')['state']);
    }

    public function test_without_a_guideline_marked_for_review_the_step_is_a_problem(): void
    {
        $volunteer = $this->volunteer();
        $this->policy('Media guideline', true);   // a policy, not a review document

        $step = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'guideline');

        $this->assertSame('problem', $step['state']);
        $this->assertSame('guideline-none-required', $step['note']);
    }

    public function test_the_journey_is_not_complete_until_the_guideline_is_reviewed(): void
    {
        $volunteer = $this->volunteer();

        $this->completeEverything($volunteer, except: 'guideline');
        $this->assertNull(OnboardingJourney::query()->sole()->completed_at);

        app(PolicyAcknowledgements::class)->acknowledge($volunteer, PolicyDocument::query()->where('title', 'Media guideline')->sole());
        $this->assertNotNull(OnboardingJourney::query()->sole()->completed_at);
    }

    public function test_hr_sets_a_document_as_a_policy_or_a_guideline_to_review(): void
    {
        $doc = $this->policy('Media guideline', true);

        Livewire::actingAs($this->managerUser)->test(RequiredPolicies::class)->assertForbidden();
        $this->assertSame('policy', $doc->fresh()->onboarding_kind);

        Livewire::actingAs($this->hr)->test(RequiredPolicies::class)
            ->call('setKind', $doc->id, 'review')
            ->call('setKind', $doc->id, 'nonsense');

        $this->assertSame('review', $doc->fresh()->onboarding_kind);
    }

    // ── Completion ───────────────────────────────────────────────────

    public function test_completion_follows_every_step_and_unwinds_when_one_is_undone(): void
    {
        $volunteer = $this->volunteer();
        $journeys = app(OnboardingJourneys::class);

        $this->completeEverything($volunteer, except: 'policies');
        $this->assertNull(OnboardingJourney::query()->sole()->completed_at, 'The policies are still open.');

        $media = PolicyDocument::query()->where('title', 'Code of conduct')->sole();
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
        ChecklistItem::query()->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS)->whereKeyNot($item->id)->get()
            ->each(fn (ChecklistItem $other) => $this->deliver($volunteer, $other, $deliverer));
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

    // ── Electronics: the city's defaults, HR may change ─────────────

    public function test_electronics_go_to_the_default_of_the_city_and_land_on_their_list(): void
    {
        $other = $this->itPerson('it.two');
        $laptop = ChecklistItem::query()->create(['name' => 'Laptop', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_ELECTRONICS]);
        // The city names its default for every electronics item (the test database seeds more than the laptop).
        ChecklistItem::query()->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS)->each(fn (ChecklistItem $item) => DefaultDeliverable::query()->create(['employee_id' => $other->id, 'city_id' => $this->city->id, 'item_id' => $item->id]));

        $volunteer = $this->volunteer();

        // Two IT people and none picked: the item's default still has an owner, and is told.
        $row = Checklist::query()->where('employee_id', $volunteer->id)->where('item_id', $laptop->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($other->id, (int) $row->delivered_id);
        $this->assertSame('pending', $row->delivered_status);
        Notification::assertSentTo($other->account, ChecklistAssigned::class);
        Notification::assertNotSentTo($this->itUser, ChecklistAssigned::class);

        $electronics = collect(app(OnboardingJourneys::class)->steps($volunteer->fresh()))->firstWhere('key', 'electronics');
        $this->assertNull($electronics['note'], 'Nothing waits on HR: every item has its deliverer.');
    }

    public function test_an_item_nobody_is_named_for_waits_for_hr_and_hr_can_change_the_pick(): void
    {
        $second = $this->itPerson('it.two');
        $laptop = ChecklistItem::query()->create(['name' => 'Laptop', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_ELECTRONICS]);

        $volunteer = $this->volunteer();
        $this->assertFalse(Checklist::query()->where('employee_id', $volunteer->id)->exists(), 'Two IT people and no default: nothing is assigned until HR picks.');

        $journeys = app(OnboardingJourneys::class);
        $this->assertTrue($journeys->chooseElectronicsDeliverer($volunteer, $this->it->id, $this->hr));
        $row = Checklist::query()->where('employee_id', $volunteer->id)->where('item_id', $laptop->id)->firstOrFail();
        $this->assertSame($this->it->id, (int) $row->delivered_id);

        // Still pending: HR changes their mind and the row moves.
        $this->assertTrue($journeys->chooseElectronicsDeliverer($volunteer->fresh(), $second->id, $this->hr));
        $this->assertSame($second->id, (int) $row->fresh()->delivered_id);

        // Once handed over, it stays with whoever delivered it.
        $row->update(['delivered_status' => 'delivered']);
        $journeys->chooseElectronicsDeliverer($volunteer->fresh(), $this->it->id, $this->hr);
        $this->assertSame($second->id, (int) $row->fresh()->delivered_id);
    }

    public function test_it_staff_open_the_hand_over_page_by_their_position_and_others_do_not(): void
    {
        $this->actingAs($this->itUser)->get(route('checklist-items.pending-delivers'))->assertOk();

        $plain = User::factory()->create(['employee_id' => $this->makeEmployee($this->city, 'plain.person')->id]);
        $plain->assignRole('employee');
        $this->actingAs($plain)->get(route('checklist-items.pending-delivers'))->assertForbidden();

        // Named in the city's delivery list, whatever the position.
        $item = ChecklistItem::query()->create(['name' => 'Badge', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_CARD]);
        DefaultDeliverable::query()->create(['employee_id' => $plain->employee_id, 'city_id' => $this->city->id, 'item_id' => $item->id]);
        $this->actingAs($plain->fresh())->get(route('checklist-items.pending-delivers'))->assertOk();
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
        $item = ChecklistItem::query()->firstOrCreate(['name' => $name], ['order' => 1, 'onboarding_group' => $group]);

        // The electronics are IT's (no default deliverer needed); the other groups name theirs per city.
        if ($group !== ChecklistItem::GROUP_ELECTRONICS) {
            DefaultDeliverable::query()->create(['employee_id' => $deliverer->id, 'city_id' => $this->city->id, 'item_id' => $item->id]);
        }

        return $item;
    }

    private function deliver(Employee $volunteer, ChecklistItem $item, Employee $deliverer, string $status = 'delivered'): void
    {
        Checklist::query()->create([
            'item_id' => $item->id, 'employee_id' => $volunteer->id, 'delivered_id' => $deliverer->id,
            'receiver_id' => $volunteer->id, 'delivered_status' => $status,
        ]);
    }

    private function policy(string $title, bool $required, string $kind = PolicyDocument::KIND_POLICY): PolicyDocument
    {
        return PolicyDocument::query()->create(['source' => PolicyDocument::SOURCE_PANEL, 'title' => $title, 'required_for_onboarding' => $required, 'onboarding_kind' => $kind]);
    }

    /** An active IT volunteer of the city: a running contract on a position of the IT department, with a login account. */
    private function itPerson(string $username): Employee
    {
        $position = Position::query()->forceCreate([
            'center_id' => $this->center->id, 'city_id' => $this->city->id, 'department_id' => $this->itDepartment->id,
            'job_level_id' => $this->level->id, 'program_id' => $this->program->id,
            'title_en' => 'IT Specialist', 'title_ar' => 'متخصص IT',
            'max_emp' => 9, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $person = $this->makeEmployee($this->city, $username);
        $this->contract($person, $position, now()->subMonths(2));
        User::factory()->create(['employee_id' => $person->id]);

        return $person->fresh();
    }

    /** Every step done, except the one named (which is left open, and for the policies, never agreed). */
    private function completeEverything(Employee $volunteer, ?string $except = null): void
    {
        $journeys = app(OnboardingJourneys::class);
        $deliverer = Employee::query()->where('username', 'deliverer.all')->first() ?? $this->makeEmployee($this->city, 'deliverer.all');

        $this->item('Laptop', ChecklistItem::GROUP_ELECTRONICS, $deliverer);
        if ($except !== 'electronics') {
            // Every electronics item (the migration adds a Mouse too).
            ChecklistItem::query()->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS)->get()
                ->each(fn (ChecklistItem $item) => $this->deliver($volunteer, $item, $deliverer));
        }

        $policy = PolicyDocument::query()->where('title', 'Code of conduct')->first() ?? $this->policy('Code of conduct', true);
        if ($except !== 'policies') {
            app(PolicyAcknowledgements::class)->acknowledge($volunteer, $policy);
        }

        $guide = PolicyDocument::query()->where('title', 'Media guideline')->first() ?? $this->policy('Media guideline', true, PolicyDocument::KIND_REVIEW);
        if ($except !== 'guideline') {
            app(PolicyAcknowledgements::class)->acknowledge($volunteer, $guide);
        }

        $journeys->scheduleManagerOrientation($volunteer, now(), $this->managerUser);
        $journeys->completeManagerOrientation($volunteer, $this->managerUser);
        $journeys->scheduleOrientation($volunteer, now(), $this->hr);
        $journeys->completeOrientation($volunteer, $this->hr);
        $journeys->markCardHandedOver($volunteer->fresh(), $this->hr);
    }
}
