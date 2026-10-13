<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowStepKey;
use App\Livewire\Layout\Sidebar;
use App\Livewire\Recruitment\Approvals\Pending;
use App\Livewire\Recruitment\Approvals\PendingApprovalTable;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\RecruitmentWorkflowDefinitionVersion;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\User;
use App\Notifications\MissingRecruitmentApprover;
use App\Policies\RecruitmentWorkflowInstanceStepPolicy;
use App\Services\RecruitmentApproverEligibility;
use App\Services\RecruitmentPendingApprovalQuery;
use App\Services\RecruitmentScopeResolver;
use App\Services\RecruitmentWorkflowInstanceService;
use Database\Seeders\RecruitmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentWorkflowInstanceTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_default_workflow_seeder_is_idempotent_and_covers_every_request_type(): void
    {
        $city = $this->makeCity('DMS', '900');
        $this->makeUser($city, 'super-admin');

        $this->seed(RecruitmentWorkflowSeeder::class);
        $this->seed(RecruitmentWorkflowSeeder::class);

        $this->assertSame(6, RecruitmentWorkflowDefinition::query()->count());
        $this->assertSame(6, RecruitmentWorkflowDefinitionVersion::query()->count());
        $this->assertDatabaseCount('recruitment_workflow_steps', 24);
    }

    public function test_normal_request_creates_the_frozen_three_step_workflow_and_only_current_step_is_pending(): void
    {
        $fixtures = $this->normalWorkflowFixtures();

        $instance = app(RecruitmentWorkflowInstanceService::class)->start(
            $fixtures['request'],
            $fixtures['version'],
        );

        $this->assertNotNull($instance);
        $this->assertSame(1, $instance->current_step_order);
        $this->assertCount(3, $instance->steps);
        $this->assertSame(
            [$fixtures['manager']->id, $fixtures['finance']->id, $fixtures['hr']->id],
            $instance->steps->sortBy(fn ($step) => $step->workflowStep->step_order)
                ->pluck('assigned_user_id')
                ->all(),
        );
        $this->assertSame(RecruitmentRequestStatus::UnderReview, (int) $fixtures['request']->fresh()->status);
        $this->assertTrue(app(RecruitmentScopeResolver::class)
            ->canAccessRequest($fixtures['finance'], $fixtures['request']));

        $managerPending = app(RecruitmentPendingApprovalQuery::class)
            ->forUser($fixtures['manager'])
            ->get();
        $financePending = app(RecruitmentPendingApprovalQuery::class)
            ->forUser($fixtures['finance'])
            ->get();

        $this->assertCount(1, $managerPending);
        $this->assertSame(WorkflowStepKey::ManagerApproval, (int) $managerPending->first()->workflowStep->step_key);
        $this->assertCount(0, $financePending, 'A future step must not appear as pending yet.');

        $financeStep = $instance->steps
            ->first(fn ($step) => (int) $step->workflowStep->step_key === WorkflowStepKey::FinanceApproval);
        $policy = app(RecruitmentWorkflowInstanceStepPolicy::class);
        $this->assertFalse($policy->approve($fixtures['finance'], $financeStep));

        $instance->update(['current_step_order' => 2]);
        $financeStep->unsetRelation('workflowInstance');
        $this->assertTrue($policy->approve($fixtures['finance'], $financeStep));
        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $fixtures['request']->id,
            'event_type' => RecruitmentEventType::WorkflowStarted,
        ]);
    }

    public function test_program_manager_request_uses_direct_manager_finance_and_governorate_hr_steps(): void
    {
        $city = $this->makeCity('ALP', '904');
        $requester = $this->makeUser($city, 'manager');
        $manager = $this->makeUser($city, 'manager');
        $finance = $this->makeUser($city, 'manager');
        $hr = $this->makeUser($city, 'HR-SYR');
        [$department, $managerLevel, $contractType, $program] = $this->positionPrerequisites();
        $programLevel = JobLevel::query()->forceCreate([
            'name_en' => 'Program Manager', 'name_ar' => 'مدير برنامج', 'grade' => 1,
        ]);

        $managerPosition = $this->assignPosition(
            $manager, $city, $program, $department, $managerLevel, $contractType, null, 'manager',
        );
        $requesterPosition = $this->assignPosition(
            $requester, $city, $program, $department, $programLevel, $contractType, $managerPosition, 'program-manager',
        );
        $finance->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));
        $this->makeDefinition($requester);
        [$request, $version] = $this->makeRequest(
            $requester->fresh(), $city, RecruitmentRequestMode::Normal, $requesterPosition,
        );

        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request, $version);

        $this->assertSame(
            [$manager->id, $finance->id, $hr->id],
            $instance->steps()->with('workflowStep')->orderBy('id')->pluck('assigned_user_id')->all(),
        );
        $this->assertSame(RecruitmentRequestStatus::UnderReview, (int) $request->fresh()->status);
    }

    public function test_start_is_idempotent_for_the_same_request_version(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $service = app(RecruitmentWorkflowInstanceService::class);

        $first = $service->start($fixtures['request'], $fixtures['version']);
        $second = $service->start($fixtures['request']->fresh(), $fixtures['version']);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, RecruitmentWorkflowInstance::query()
            ->where('request_version_id', $fixtures['version']->id)
            ->count());
        $this->assertSame(3, $first?->steps()->count());
    }

    public function test_authorized_top_level_requester_skips_only_manager_and_starts_at_finance(): void
    {
        $fixtures = $this->normalWorkflowFixtures(topLevel: true);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start(
            $fixtures['request'],
            $fixtures['version'],
        );

        $managerStep = $instance?->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_key', WorkflowStepKey::ManagerApproval))
            ->firstOrFail();

        $this->assertSame(2, $instance?->current_step_order);
        $this->assertNull($managerStep->decision);
        $this->assertNotNull($managerStep->acted_at);
        $this->assertStringStartsWith('skip:', (string) $managerStep->decision_reason);
        $this->assertCount(1, app(RecruitmentPendingApprovalQuery::class)
            ->forUser($fixtures['finance'])
            ->get());
        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $fixtures['request']->id,
            'event_type' => RecruitmentEventType::StepSkipped,
        ]);
    }

    public function test_missing_finance_approver_keeps_the_mandatory_step_unassigned_without_blocked_state(): void
    {
        Notification::fake();
        $fixtures = $this->normalWorkflowFixtures(withFinance: false);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start(
            $fixtures['request'],
            $fixtures['version'],
        );

        $financeStep = $instance?->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_key', WorkflowStepKey::FinanceApproval))
            ->firstOrFail();

        $this->assertNull($financeStep->assigned_user_id);
        $this->assertSame(RecruitmentRequestStatus::UnderReview, (int) $fixtures['request']->fresh()->status);
        $this->assertSame(\App\Enums\WorkflowInstanceStatus::Running, (int) $instance?->status);
        Notification::assertSentTo($fixtures['hr'], MissingRecruitmentApprover::class);
        Notification::assertNotSentTo($fixtures['requester'], MissingRecruitmentApprover::class);
    }

    public function test_multiple_finance_candidates_are_not_resolved_arbitrarily(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $secondFinance = $this->makeUser($fixtures['request']->scopeCity, 'manager');
        $secondFinance->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));

        $instance = app(RecruitmentWorkflowInstanceService::class)->start(
            $fixtures['request'],
            $fixtures['version'],
        );
        $financeStep = $instance?->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_key', WorkflowStepKey::FinanceApproval))
            ->firstOrFail();

        $this->assertNull($financeStep->assigned_user_id);
        $event = $fixtures['request']->events()
            ->where('event_type', RecruitmentEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame(
            'multiple_active_holders',
            $event->meta['approver_resolution'][(string) WorkflowStepKey::FinanceApproval]['reason'],
        );
    }

    public function test_self_approval_is_escalated_to_the_next_manager(): void
    {
        $city = $this->makeCity('ALP', '903');
        $requester = $this->makeUser($city, 'manager');
        $upperManager = $this->makeUser($city, 'manager');
        $finance = $this->makeUser($city, 'manager');
        $this->makeUser($city, 'HR-SYR');
        [$department, $level, $contractType, $program] = $this->positionPrerequisites();

        $upperPosition = $this->assignPosition($upperManager, $city, $program, $department, $level, $contractType, null, 'upper');
        $selfManagedPosition = $this->assignPosition($requester, $city, $program, $department, $level, $contractType, $upperPosition, 'self-manager');
        $requesterPosition = $this->assignPosition($requester, $city, $program, $department, $level, $contractType, $selfManagedPosition, 'requester');
        Contract::query()
            ->where('position_id', $requesterPosition->id)
            ->update(['created_at' => now()->addSecond()]);
        $finance->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));
        $this->makeDefinition($requester);
        [$request, $version] = $this->makeRequest($requester->fresh(), $city, RecruitmentRequestMode::Normal, $requesterPosition);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request, $version);
        $managerStep = $instance?->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_key', WorkflowStepKey::ManagerApproval))
            ->firstOrFail();

        $this->assertSame($upperManager->id, $managerStep->assigned_user_id);
        $this->assertSame($upperPosition->id, $managerStep->assigned_scope_id);
        $event = $request->events()->where('event_type', RecruitmentEventType::WorkflowStarted)->firstOrFail();
        $this->assertTrue(
            $event->meta['approver_resolution'][(string) WorkflowStepKey::ManagerApproval]['self_approval_escalated'],
        );
    }

    public function test_missing_manager_position_rejects_start_without_creating_an_instance(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $requesterPosition = $fixtures['request']->targetPosition;
        $requesterPosition->update(['direct_manager_id' => null]);

        try {
            app(RecruitmentWorkflowInstanceService::class)->start(
                $fixtures['request'],
                $fixtures['version'],
            );
            $this->fail('A requester without a manager must not start a normal workflow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }

        $this->assertDatabaseMissing('recruitment_workflow_instances', [
            'request_version_id' => $fixtures['version']->id,
        ]);
    }

    public function test_governorate_hr_direct_open_creates_only_the_hr_syria_step(): void
    {
        $city = $this->makeCity('ALP', '901');
        $requester = $this->makeUser($city, 'HR-ALP');
        $hrSyria = $this->makeUser($city, 'HR-SYR');
        $this->makeDefinition($requester);
        [$request, $version] = $this->makeRequest($requester, $city, RecruitmentRequestMode::DirectHr);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request, $version);

        $this->assertNotNull($instance);
        $this->assertSame(4, $instance->current_step_order);
        $this->assertCount(1, $instance->steps);
        $this->assertSame($hrSyria->id, $instance->steps->first()->assigned_user_id);
        $this->assertSame(WorkflowStepKey::HrSyriaDirectOpenApproval, (int) $instance->steps->first()->workflowStep->step_key);
        $this->assertTrue(app(RecruitmentWorkflowInstanceStepPolicy::class)
            ->approve($hrSyria, $instance->steps->first()));
    }

    public function test_hr_syria_direct_open_is_approved_without_a_workflow_instance(): void
    {
        $city = $this->makeCity('DMS', '902');
        $hrSyria = $this->makeUser($city, 'HR-SYR');
        [$request, $version] = $this->makeRequest($hrSyria, $city, RecruitmentRequestMode::DirectHr);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request, $version);

        $this->assertNull($instance);
        $this->assertSame(RecruitmentRequestStatus::Approved, (int) $request->fresh()->status);
        $this->assertDatabaseMissing('recruitment_workflow_instances', ['request_version_id' => $version->id]);
        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::DirectHrOpened,
        ]);

        app(RecruitmentWorkflowInstanceService::class)->start($request->fresh(), $version);
        $this->assertSame(1, $request->events()
            ->where('event_type', RecruitmentEventType::DirectHrOpened)
            ->count());
    }

    public function test_pending_approvals_component_renders_only_the_signed_in_users_current_items(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        app(RecruitmentWorkflowInstanceService::class)->start($fixtures['request'], $fixtures['version']);

        Livewire::actingAs($fixtures['manager'])
            ->test(PendingApprovalTable::class)
            ->assertOk()
            ->assertSee($fixtures['request']->request_number);

        Livewire::actingAs($fixtures['finance'])
            ->test(PendingApprovalTable::class)
            ->assertOk()
            ->assertDontSee($fixtures['request']->request_number);
    }

    public function test_pending_approvals_is_shown_only_to_people_an_approval_can_reach(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $eligibility = app(RecruitmentApproverEligibility::class);

        // The requester's manager, the Senior Financial Manager and HR can
        // all be handed a step; the requester, who manages nobody who can
        // raise a request, never can.
        $this->assertTrue($eligibility->mayReceiveApprovals($fixtures['manager']));
        $this->assertTrue($eligibility->mayReceiveApprovals($fixtures['finance']));
        $this->assertTrue($eligibility->mayReceiveApprovals($fixtures['hr']));
        $this->assertFalse($eligibility->mayReceiveApprovals($fixtures['requester']));

        Livewire::actingAs($fixtures['requester'])
            ->test(Sidebar::class)
            ->assertDontSee(__('recruitment.navigation_pending_approvals'));
        $this->actingAs($fixtures['requester'])
            ->get(route('recruitment.approvals.pending'))
            ->assertForbidden();

        // Eligibility, not queue size: visible before anything is waiting.
        Livewire::actingAs($fixtures['manager'])
            ->test(Sidebar::class)
            ->assertSee(__('recruitment.navigation_pending_approvals'));
        $this->actingAs($fixtures['manager'])
            ->get(route('recruitment.approvals.pending'))
            ->assertOk();
    }

    public function test_the_queue_page_summarises_the_wait_and_shows_a_calm_empty_state(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        app(RecruitmentWorkflowInstanceService::class)->start($fixtures['request'], $fixtures['version']);

        Livewire::actingAs($fixtures['manager'])
            ->test(Pending::class)
            ->assertOk()
            ->assertViewHas('waiting', 1)
            ->assertSee(__('recruitment-workflow.summary_waiting'))
            ->assertDontSee('data-pending-empty', false);

        // The finance approver is eligible but nothing has reached him yet.
        Livewire::actingAs($fixtures['finance'])
            ->test(Pending::class)
            ->assertOk()
            ->assertViewHas('waiting', 0)
            ->assertSee(__('recruitment-workflow.empty_title'));
    }

    public function test_waiting_is_counted_from_when_the_step_became_yours(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $instance = app(RecruitmentWorkflowInstanceService::class)->start($fixtures['request'], $fixtures['version']);
        $instance->forceFill(['started_at' => now()->subDays(10)])->save();

        Livewire::actingAs($fixtures['manager'])
            ->test(PendingApprovalTable::class)
            ->assertSee(trans_choice('recruitment-workflow.waiting_days', 10, ['count' => 10]));

        // The manager signs two days ago: finance has waited two days, not ten.
        $instance->steps()
            ->whereHas('workflowStep', fn ($steps) => $steps->where('step_order', 1))
            ->update(['decision' => 1, 'acted_at' => now()->subDays(2), 'acted_by_user_id' => $fixtures['manager']->id]);
        $instance->forceFill(['current_step_order' => 2])->save();

        Livewire::actingAs($fixtures['finance'])
            ->test(PendingApprovalTable::class)
            ->assertSee($fixtures['request']->request_number)
            ->assertSee(trans_choice('recruitment-workflow.waiting_days', 2, ['count' => 2]))
            ->assertDontSee(trans_choice('recruitment-workflow.waiting_days', 10, ['count' => 10]));
    }

    /** Production runs with a table prefix; the raw part of the query must carry it too. */
    public function test_the_waiting_time_query_respects_a_table_prefix(): void
    {
        $default = config('database.default');
        config(['database.connections.prefixed' => [...config("database.connections.{$default}"), 'prefix' => 'hchr_']]);
        DB::setDefaultConnection('prefixed');

        try {
            $pending = app(RecruitmentPendingApprovalQuery::class);
            $sql = $pending->forUser((new User)->forceFill(['id' => 1]))
                ->reorder()
                ->addSelect(['waiting_since' => $pending->waitingSince()])
                ->toSql();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('prefixed');
        }

        $this->assertStringContainsString('MAX("hchr_previous_steps"."acted_at")', $sql);
        $this->assertStringContainsString('"hchr_pending_instances"."started_at"', $sql);
        $this->assertStringNotContainsString('MAX(previous_steps', $sql);
    }

    public function test_managing_staff_who_cannot_raise_requests_does_not_make_an_approver(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        [$department, , $contractType, $program] = $this->positionPrerequisites();
        $city = $this->makeCity('HMS', (string) random_int(900, 989));
        $coordinatorLevel = JobLevel::query()->forceCreate(['name_en' => 'Coordinator', 'name_ar' => 'منسق', 'grade' => 7]);

        // A project manager with a coordinator under him: he has a team, but
        // nobody in it can raise a request for him to approve.
        $projectManager = $this->makeUser($city, 'manager');
        $coordinator = $this->makeUser($city, 'employee');
        $managerPosition = $this->assignPosition($projectManager, $city, $program, $department, JobLevel::query()->where('grade', 3)->firstOrFail(), $contractType, null, 'project manager');
        $this->assignPosition($coordinator, $city, $program, $department, $coordinatorLevel, $contractType, $managerPosition, 'coordinator');

        $this->assertFalse(app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($projectManager->fresh()));
        Livewire::actingAs($projectManager->fresh())
            ->test(Sidebar::class)
            ->assertDontSee(__('recruitment.navigation_pending_approvals'));
    }

    public function test_finance_approval_belongs_to_the_person_not_to_a_role(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $city = $this->makeCity('LAT', (string) random_int(900, 989));
        $colleague = $this->makeUser($city, 'manager');

        // Put the permission on the role the Senior Financial Manager holds:
        // it still makes nobody else an approver.
        $colleague->roles()->firstOrFail()->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));

        $this->assertFalse(app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($colleague->fresh()));
        $this->assertTrue(app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($fixtures['finance']));
    }

    public function test_anything_already_waiting_keeps_the_page_visible(): void
    {
        $fixtures = $this->normalWorkflowFixtures();
        $instance = app(RecruitmentWorkflowInstanceService::class)->start($fixtures['request'], $fixtures['version']);

        // An escalation can hand a step to someone the org-chart rules do
        // not name; that item must never become unreachable.
        $instance->steps()
            ->whereHas('workflowStep', fn ($steps) => $steps->where('step_order', 1))
            ->update(['assigned_user_id' => $fixtures['requester']->id]);

        $this->assertTrue(app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($fixtures['requester']));
        Livewire::actingAs($fixtures['requester'])
            ->test(PendingApprovalTable::class)
            ->assertOk()
            ->assertSee($fixtures['request']->request_number);
    }

    /** @return array{request: RecruitmentRequest, version: RecruitmentRequestVersion, requester: User, manager: ?User, finance: ?User, hr: User} */
    /**
     * Closing a vacancy or a position skips financial review (D56).
     *
     * Neither spends money — both save it — so neither waits on the single
     * financial approver for the whole organization. They run on two steps.
     */
    public function test_closing_a_vacancy_or_a_position_skips_financial_review(): void
    {
        $city = $this->makeCity('HMS', (string) random_int(900, 989));
        $this->makeUser($city, 'super-admin');
        $this->seed(RecruitmentWorkflowSeeder::class);

        foreach ([
            RecruitmentRequestType::ClosePosition => 2,
            RecruitmentRequestType::CloseVacancy => 2,
            RecruitmentRequestType::FillVacancy => 3,
            RecruitmentRequestType::IncreaseHeadcount => 3,
            RecruitmentRequestType::DecreaseHeadcount => 3,
            RecruitmentRequestType::CreatePosition => 3,
        ] as $requestType => $expected) {
            $version = RecruitmentWorkflowDefinition::query()
                ->where('request_type', $requestType)
                ->firstOrFail()
                ->versions()
                ->where('is_active', true)
                ->firstOrFail();

            $steps = $version->steps()
                ->get()
                ->filter(fn ($step) => in_array(
                    RecruitmentRequestMode::Normal,
                    array_map('intval', $step->required_condition_json['modes'] ?? []),
                    true,
                ))
                ->reject(fn ($step) => in_array(
                    $requestType,
                    array_map('intval', $step->required_condition_json['except_types'] ?? []),
                    true,
                ));

            $this->assertCount(
                $expected,
                $steps,
                "Request type {$requestType} should walk {$expected} steps.",
            );

            $this->assertSame(
                $expected === 3,
                $steps->contains(fn ($step) => (int) $step->step_key === WorkflowStepKey::FinanceApproval),
                "Financial review on request type {$requestType} is wrong.",
            );
        }
    }

    private function normalWorkflowFixtures(bool $topLevel = false, bool $withFinance = true): array
    {
        $city = $this->makeCity('ALP', (string) random_int(100, 899));
        $requester = $this->makeUser($city, 'manager');
        $manager = $topLevel ? null : $this->makeUser($city, 'manager');
        $finance = $withFinance ? $this->makeUser($city, 'manager') : null;
        $hr = $this->makeUser($city, 'HR-SYR');
        [$department, $level, $contractType, $program] = $this->positionPrerequisites();

        $managerPosition = $manager === null ? null : $this->assignPosition(
            $manager,
            $city,
            $program,
            $department,
            $level,
            $contractType,
            null,
            'manager',
        );
        $requesterPosition = $this->assignPosition(
            $requester,
            $city,
            $program,
            $department,
            $level,
            $contractType,
            $managerPosition,
            'requester',
        );

        if ($finance !== null) {
            $finance->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));
        }

        if ($topLevel) {
            $requester->givePermissionTo(Permission::findByName('recruitment-requests.submit-without-manager'));
        }

        $this->makeDefinition($requester);
        [$request, $version] = $this->makeRequest(
            $requester->fresh(),
            $city,
            RecruitmentRequestMode::Normal,
            $requesterPosition,
        );

        return compact('request', 'version', 'requester', 'manager', 'finance', 'hr');
    }

    private function makeDefinition(User $creator): RecruitmentWorkflowDefinitionVersion
    {
        $definition = RecruitmentWorkflowDefinition::query()->create([
            'name' => 'Test workflow '.uniqid(),
            'request_type' => RecruitmentRequestType::FillVacancy,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
        ]);
        $version = $definition->versions()->create([
            'version_no' => 1,
            'rules_json' => [],
            'is_active' => true,
            'published_at' => now(),
        ]);
        $normal = ['modes' => [RecruitmentRequestMode::Normal]];
        $direct = ['modes' => [RecruitmentRequestMode::DirectHr]];

        $version->steps()->createMany([
            ['step_order' => 1, 'step_key' => WorkflowStepKey::ManagerApproval, 'role_key' => 'manager', 'scope_rule' => RecruitmentScopeType::DirectReports, 'required_condition_json' => $normal],
            ['step_order' => 2, 'step_key' => WorkflowStepKey::FinanceApproval, 'role_key' => 'finance-approver', 'scope_rule' => RecruitmentScopeType::Organization, 'required_condition_json' => $normal],
            ['step_order' => 3, 'step_key' => WorkflowStepKey::GovernorateHrApproval, 'role_key' => 'governorate-hr', 'scope_rule' => RecruitmentScopeType::City, 'required_condition_json' => $normal],
            ['step_order' => 4, 'step_key' => WorkflowStepKey::HrSyriaDirectOpenApproval, 'role_key' => 'HR-SYR', 'scope_rule' => RecruitmentScopeType::Organization, 'required_condition_json' => $direct],
        ]);

        return $version;
    }

    /** @return array{0: RecruitmentRequest, 1: RecruitmentRequestVersion} */
    private function makeRequest(
        User $requester,
        City $city,
        int $mode,
        ?Position $position = null,
    ): array {
        $request = RecruitmentRequest::query()->create([
            'request_number' => 'RR-'.uniqid(),
            'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::FillVacancy,
            'mode' => $mode,
            'status' => RecruitmentRequestStatus::Submitted,
            'scope_city_id' => $city->id,
            'target_position_id' => $position?->id,
            'submitted_at' => now(),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $requester->id,
            'submitted_at' => now(),
        ]);

        return [$request, $version];
    }

    /** @return array{0: Department, 1: JobLevel, 2: ContractType, 3: Program} */
    private function positionPrerequisites(): array
    {
        return [
            Department::query()->forceCreate(['name' => 'Department '.uniqid(), 'code' => strtoupper(uniqid('D'))]),
            JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 3]),
            ContractType::query()->forceCreate(['name_en' => 'Annual', 'name_ar' => 'سنوي']),
            Program::query()->forceCreate([
                'name' => 'Program '.uniqid(),
                'code' => strtoupper(uniqid('P')),
                'slug' => 'program-'.uniqid(),
                'start_date' => now()->subYear(),
                'is_active' => true,
            ]),
        ];
    }

    private function assignPosition(
        User $user,
        City $city,
        Program $program,
        Department $department,
        JobLevel $level,
        ContractType $contractType,
        ?Position $manager,
        string $label,
    ): Position {
        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $level->id,
            'department_id' => $department->id,
            'direct_manager_id' => $manager?->id,
            'title_en' => ucfirst($label),
            'title_ar' => $label,
            'max_emp' => 2,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        Contract::query()->forceCreate([
            'employee_id' => $user->employee_id,
            'position_id' => $position->id,
            'contract_type_id' => $contractType->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        return $position;
    }
}
