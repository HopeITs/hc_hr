<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowStepKey;
use App\Models\ApplicationForm;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPost;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Program;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestEvent;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentScopeResolver;
use App\Support\AccessPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentAuthorizationTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Route::middleware(['web', 'auth', 'can:view,recruitmentRequest'])
            ->get('/_test/recruitment/requests/{recruitmentRequest}', fn (RecruitmentRequest $recruitmentRequest): string => (string) $recruitmentRequest->getKey());
    }

    /**
     * The catalog exists and every role holds exactly its agreed baseline
     * (D33) — with the two person-level permissions attached to no role at
     * all, which is the whole point of them (D27).
     */
    public function test_the_recruitment_permission_catalog_is_seeded_with_role_baselines(): void
    {
        $recruitment = collect(AccessPermissionCatalog::names())
            ->filter(fn (string $name): bool => str_starts_with($name, 'recruitment-'));

        $this->assertCount(42, $recruitment);
        $this->assertSame(
            $recruitment->sort()->values()->all(),
            Permission::query()
                ->where('name', 'like', 'recruitment-%')
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        );

        $counts = [
            'super-admin' => 37,
            'HR-SYR' => 37,
            'general-manager' => 13,
            'manager' => 8,
            'employee' => 0,
        ];

        foreach ($counts as $roleName => $expected) {
            $this->assertSame(
                $expected,
                Role::findByName($roleName)->permissions()->where('name', 'like', 'recruitment-%')->count(),
                "Role {$roleName} should hold {$expected} recruitment permissions.",
            );
        }
    }

    /**
     * approve-finance and submit-without-manager identify a person, so a role
     * must never carry either: every holder of that role would become the
     * organization's financial approver, or skip manager approval (D27).
     */
    public function test_person_level_permissions_are_attached_to_no_role(): void
    {
        foreach (['recruitment-requests.approve-finance', 'recruitment-requests.submit-without-manager'] as $name) {
            $this->assertSame(
                0,
                Permission::findByName($name)->roles()->count(),
                "{$name} must not be granted through any role.",
            );
        }
    }

    /**
     * A role created after the catalog gained the recruitment permissions must
     * not pick the person-level two up on the way in.
     *
     * App\Models\Role hands every catalog permission to a new administrator or
     * HR role automatically. Reading names() there instead of
     * roleGrantableNames() silently made every governorate HR the
     * organization's financial approver — and only on a *fresh* database,
     * which is to say on deployment day and nowhere else.
     */
    public function test_a_newly_created_hr_role_does_not_pick_up_person_level_permissions(): void
    {
        $role = Role::findOrCreate('HR-TAR', 'web');

        $this->assertSame(37, $role->permissions()->where('name', 'like', 'recruitment-%')->count());
        $this->assertFalse($role->hasPermissionTo('recruitment-requests.approve-finance'));
        $this->assertFalse($role->hasPermissionTo('recruitment-requests.submit-without-manager'));

        $administrator = Role::findOrCreate('super-admin-clone', 'web');
        $this->assertFalse($administrator->hasPermissionTo('recruitment-requests.approve-finance'));
    }

    /**
     * ...and they are read from the direct grant, never from a role that
     * happens to carry one. This is the failure the previous assertion guards
     * against, verified from the other side.
     */
    public function test_person_level_permissions_are_read_from_direct_grants_only(): void
    {
        $city = $this->makeCity('lat-'.uniqid(), (string) random_int(100, 999));
        $resolver = app(RecruitmentScopeResolver::class);

        $throughRole = $this->makeUser($city, 'finance-lookalike-'.uniqid(), [
            'recruitment-requests.approve-finance',
        ]);
        $this->assertNotContains($throughRole->id, $resolver->financeApproverUserIds());

        $directly = $this->makeUser($city, 'manager');
        $directly->givePermissionTo('recruitment-requests.approve-finance');
        $this->assertContains($directly->id, $resolver->financeApproverUserIds());
    }

    /** The active Senior Financial Manager remains the finance confirmer. */
    public function test_the_finance_approver_follows_the_active_senior_financial_position(): void
    {
        $city = $this->makeCity('SYR-'.uniqid(), (string) random_int(100, 999));
        [$department, $jobLevel, $contractType] = $this->positionPrerequisites();
        $program = $this->makeProgram('finance');
        $seniorFinancial = $this->makeUser($city, 'manager');
        $staleGrant = $this->makeUser($city, 'manager');
        $staleGrant->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));

        $position = $this->makePosition($city, $program, $department, $jobLevel, 'senior-financial');
        $position->update(['title_en' => 'Senior Financial Manager']);
        Contract::query()->forceCreate([
            'employee_id' => $seniorFinancial->employee_id,
            'position_id' => $position->id,
            'contract_type_id' => $contractType->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        $this->assertSame(
            [$seniorFinancial->id],
            app(RecruitmentScopeResolver::class)->financeApproverUserIds(),
        );
    }

    /**
     * Eligibility is the grade of the position held, never a role (D26). A
     * promotion qualifies someone the same day; moving off a management
     * position withdraws it.
     */
    public function test_eligibility_comes_from_the_job_level_not_the_role(): void
    {
        $city = $this->makeCity('elig-'.uniqid(), (string) random_int(100, 999));
        [$department, , $contractType] = $this->positionPrerequisites();
        $program = $this->makeProgram('elig');
        $resolver = app(RecruitmentScopeResolver::class);

        foreach ([1 => true, 3 => true, 5 => true, 2 => false, 4 => false, 8 => false] as $grade => $expected) {
            $user = $this->makeUser($city, 'manager');
            $level = JobLevel::query()->forceCreate([
                'name_en' => 'Grade '.$grade,
                'name_ar' => 'درجة '.$grade,
                'grade' => $grade,
            ]);
            $this->assignPosition($user, $city, $program, $department, $level, $contractType);

            $this->assertSame(
                $expected,
                $resolver->isEligibleRequester($user->fresh()),
                "Grade {$grade} eligibility should be ".var_export($expected, true),
            );
        }
    }

    /**
     * A technical account holds no position, so it has no grade and no manager
     * to approve it. It sees everything and raises nothing (D41).
     */
    public function test_super_admin_holds_every_permission_but_cannot_raise_a_request(): void
    {
        $city = $this->makeCity('sa-'.uniqid(), (string) random_int(100, 999));
        $admin = $this->makeUser($city, 'super-admin');

        $this->assertTrue($admin->hasActivePermission('recruitment-requests.manage'));
        $this->assertFalse(app(RecruitmentScopeResolver::class)->isEligibleRequester($admin));
        $this->assertFalse(Gate::forUser($admin)->allows('create', RecruitmentRequest::class));
    }

    /**
     * A governorate HR is confined to its own governorate (D47) — no "reporting
     * visibility" wider than the scope.
     */
    public function test_governorate_hr_is_confined_to_its_own_city(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');

        $hr = $this->recruiter($homs, 'HR-HMS');
        $local = $this->makeRequest($this->makeUser($homs, 'employee'), $homs);
        $remote = $this->makeRequest($this->makeUser($aleppo, 'employee'), $aleppo);

        $this->assertTrue(Gate::forUser($hr)->allows('view', $local));
        $this->assertFalse(Gate::forUser($hr)->allows('view', $remote));
        $this->assertFalse(RecruitmentRequest::query()->visibleTo($hr)->whereKey($remote)->exists());
    }

    /**
     * ...except for the governorates assigned to it. Tartous is reviewed by HR
     * Latakia, so Tartous is inside its scope and not merely named on its step
     * (D21 and D42). Reading the map in one direction only was a real bug.
     */
    public function test_governorate_hr_also_reaches_the_cities_assigned_to_it(): void
    {
        $latakia = $this->makeCity('LAT', '005');
        $tartous = $this->makeCity('TAR', '006');
        $homs = $this->makeCity('HMS', '002');

        config()->set('recruitment.governorate_hr.map', ['TAR' => 'HR-LAT']);

        $hr = $this->recruiter($latakia, 'HR-LAT');
        $assigned = $this->makeRequest($this->makeUser($tartous, 'employee'), $tartous);
        $unrelated = $this->makeRequest($this->makeUser($homs, 'employee'), $homs);

        $this->assertContains($tartous->id, app(RecruitmentScopeResolver::class)->governorateCityIds($hr));
        $this->assertTrue(Gate::forUser($hr)->allows('view', $assigned));
        $this->assertFalse(Gate::forUser($hr)->allows('view', $unrelated));
    }

    /**
     * Every city resolves to a reviewer. A request is never stranded because
     * its governorate has no HR of its own (D21).
     */
    public function test_every_city_resolves_to_a_reviewing_hr_role(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $resolver = app(RecruitmentScopeResolver::class);

        config()->set('recruitment.governorate_hr.map', ['ALP' => 'HR-SYR']);
        $this->makeUser($homs, 'HR-HMS');

        $this->assertSame('HR-SYR', $resolver->governorateHrRole($aleppo->id));
        $this->assertSame('HR-HMS', $resolver->governorateHrRole($homs->id));
        $this->assertSame('HR-SYR', $resolver->governorateHrRole(null));
    }

    /**
     * HR Syria and the general management watch the whole organization without
     * being a step in any workflow (D13).
     */
    public function test_headquarters_scope_remains_organization_wide(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');

        $hq = $this->recruiter($aleppo, 'HR-SYR');
        $remote = $this->makeRequest($this->makeUser($homs, 'employee'), $homs);

        $this->assertTrue(Gate::forUser($hq)->allows('view', $remote));
        $this->assertTrue(RecruitmentRequest::query()->visibleTo($hq)->whereKey($remote)->exists());
    }

    /**
     * A manager sees his own requests and those of the people reporting to
     * him — one level, no deeper (D30). He is answerable for exactly that.
     */
    public function test_a_manager_sees_own_requests_and_direct_reports_only(): void
    {
        $city = $this->makeCity('mgr-'.uniqid(), (string) random_int(100, 999));
        [$department, $jobLevel, $contractType] = $this->positionPrerequisites();
        $program = $this->makeProgram('mgr');

        $manager = $this->recruiter($city, 'manager');
        $managerPosition = $this->assignPosition($manager, $city, $program, $department, $jobLevel, $contractType);

        $report = $this->makeUser($city, 'manager');
        $reportPosition = $this->assignPosition($report, $city, $program, $department, $jobLevel, $contractType);
        $reportPosition->forceFill(['direct_manager_id' => $managerPosition->id])->save();

        $stranger = $this->makeUser($city, 'manager');
        $this->assignPosition($stranger, $city, $program, $department, $jobLevel, $contractType);

        $own = $this->makeRequest($manager, $city);
        $reports = $this->makeRequest($report, $city);
        $foreign = $this->makeRequest($stranger, $city);

        $manager = $manager->fresh();

        $this->assertTrue(Gate::forUser($manager)->allows('view', $own));
        $this->assertTrue(Gate::forUser($manager)->allows('view', $reports));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $foreign));
    }

    /**
     * The resolver fails closed. A user it cannot place gets None, and None is
     * an empty result — not an unscoped one (D31).
     */
    public function test_a_user_without_a_recognized_scope_sees_nothing(): void
    {
        $city = $this->makeCity('none-'.uniqid(), (string) random_int(100, 999));

        // A role carrying the permission, but no position and so no scope.
        $orphan = User::factory()->create(['employee_id' => null]);
        $role = Role::findOrCreate('manager', 'web');
        $orphan->assignRole($role);
        $orphan->roles()->updateExistingPivot($role->id, ['is_active' => true]);
        $orphan = $orphan->fresh();

        $foreign = $this->makeRequest($this->makeUser($city, 'employee'), $city);

        $this->assertSame(RecruitmentScopeType::None, app(RecruitmentScopeResolver::class)->scopeType($orphan));
        $this->assertFalse(Gate::forUser($orphan)->allows('view', $foreign));
        $this->assertSame(0, RecruitmentRequest::query()->visibleTo($orphan)->count());
    }

    /**
     * Holding the permission is the first of four questions, and the only one
     * an administrator role bypasses (D10). HR Syria holds every permission in
     * the system and still cannot decide a step that is somebody else's.
     */
    public function test_deciding_a_step_requires_being_the_assignee_and_holding_the_scope(): void
    {
        $homs = $this->makeCity('HMS', '002');
        $aleppo = $this->makeCity('ALP', '001');
        config()->set('recruitment.governorate_hr.map', []);

        $assignee = $this->recruiter($homs, 'HR-HMS');
        $other = $this->recruiter($homs, 'HR-HMS');
        $headquarters = $this->recruiter($homs, 'HR-SYR');

        $request = $this->makeRequest($this->makeUser($homs, 'employee'), $homs);
        $step = $this->makeWorkflowStep(
            $request,
            $assignee,
            'HR-HMS',
            RecruitmentScopeType::City,
            $homs->id,
        );

        $resolver = app(RecruitmentScopeResolver::class);

        // The assignee, in scope, on an open step.
        $this->assertTrue($resolver->canActOnStep($assignee, $step));

        // Same role, same scope, but not the assignee.
        $this->assertFalse($resolver->canActOnStep($other, $step));

        // Holds every permission in the system; still not the assignee.
        $this->assertFalse($resolver->canActOnStep($headquarters, $step));

        // The assignee, but the step is already decided. Each scenario needs a
        // request of its own: a workflow instance is unique per request version.
        $decided = $this->makeWorkflowStep(
            $this->makeRequest($this->makeUser($homs, 'employee'), $homs),
            $assignee,
            'HR-HMS',
            RecruitmentScopeType::City,
            $homs->id,
        );
        $decided->forceFill(['decision' => 'approved'])->save();
        $this->assertFalse($resolver->canActOnStep($assignee, $decided));

        // The assignee, open step, but a scope they do not hold.
        $outOfScope = $this->makeWorkflowStep(
            $this->makeRequest($this->makeUser($aleppo, 'employee'), $aleppo),
            $assignee,
            'HR-HMS',
            RecruitmentScopeType::City,
            $aleppo->id,
        );
        $this->assertFalse($resolver->canActOnStep($assignee, $outOfScope));

        // An unrecognized scope is a no. Absence is not a complete scope.
        $unknown = $this->makeWorkflowStep(
            $this->makeRequest($this->makeUser($homs, 'employee'), $homs),
            $assignee,
            'HR-HMS',
            null,
            null,
        );
        $this->assertFalse($resolver->canActOnStep($assignee, $unknown));
    }

    /**
     * Requests are the root of visibility and everything downstream inherits
     * it, so the two answers cannot drift apart.
     */
    public function test_publishing_entities_inherit_the_request_scope(): void
    {
        $homs = $this->makeCity('HMS', '002');
        $aleppo = $this->makeCity('ALP', '001');
        config()->set('recruitment.governorate_hr.map', []);

        [$department, $jobLevel, $contractType] = $this->positionPrerequisites();
        $program = $this->makeProgram('pub');
        $localPosition = $this->makePosition($homs, $program, $department, $jobLevel, 'local');
        $remotePosition = $this->makePosition($aleppo, $program, $department, $jobLevel, 'remote');

        $hr = $this->recruiter($homs, 'HR-HMS');
        $local = $this->publishingChain($this->makeRequest($this->makeUser($homs, 'employee'), $homs), $localPosition, $hr);
        $remote = $this->publishingChain($this->makeRequest($this->makeUser($aleppo, 'employee'), $aleppo), $remotePosition, $hr);

        $this->assertTrue(Gate::forUser($hr)->allows('view', $local['vacancy']));
        $this->assertTrue(Gate::forUser($hr)->allows('manage', $local['campaign']));
        $this->assertTrue(Gate::forUser($hr)->allows('manage', $local['jobPost']));
        $this->assertTrue(Gate::forUser($hr)->allows('manage', $local['form']));
        $this->assertTrue(Gate::forUser($hr)->allows('manage', $local['link']));

        $this->assertFalse(Gate::forUser($hr)->allows('view', $remote['vacancy']));
        $this->assertFalse(Gate::forUser($hr)->allows('manage', $remote['campaign']));
        $this->assertFalse(Gate::forUser($hr)->allows('manage', $remote['jobPost']));
        $this->assertFalse(Gate::forUser($hr)->allows('manage', $remote['form']));
        $this->assertFalse(Gate::forUser($hr)->allows('manage', $remote['link']));
    }

    /**
     * The detail screen is the classic place scope gets forgotten, so the
     * route is exercised as a route rather than through the Gate alone.
     */
    public function test_the_detail_route_applies_the_same_boundary_as_the_list(): void
    {
        $homs = $this->makeCity('HMS', '002');
        $aleppo = $this->makeCity('ALP', '001');
        config()->set('recruitment.governorate_hr.map', []);

        $hr = $this->recruiter($homs, 'HR-HMS');
        $local = $this->makeRequest($this->makeUser($homs, 'employee'), $homs);
        $remote = $this->makeRequest($this->makeUser($aleppo, 'employee'), $aleppo);

        $this->actingAs($hr)->get('/_test/recruitment/requests/'.$local->getKey())->assertOk();
        $this->actingAs($hr)->get('/_test/recruitment/requests/'.$remote->getKey())->assertForbidden();
    }

    /**
     * A user whose active role holds the recruitment permissions a role may
     * legitimately hold.
     *
     * CreatesHrFixtures builds roles through Spatie's Role class, which does
     * not run App\Models\Role's automatic grant, so the baseline is applied
     * here explicitly. That separation is deliberate: these tests are about
     * scope, and the granting path has its own test above.
     */
    private function recruiter(City $city, string $roleName): User
    {
        return $this->makeUser($city, $roleName, AccessPermissionCatalog::roleGrantableNames());
    }

    private function makeRequest(
        User $requester,
        City $city,
        ?Program $program = null,
        ?Department $department = null,
    ): RecruitmentRequest {
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()),
            'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition,
            'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Submitted,
            'scope_city_id' => $city->id,
            'submitted_at' => now(),
        ]);

        if ($program !== null || $department !== null) {
            $version = RecruitmentRequestVersion::query()->forceCreate([
                'request_id' => $request->id,
                'version_no' => 1,
                'snapshot_json' => [],
                'created_by_user_id' => $requester->id,
                'submitted_at' => now(),
            ]);

            RecruitmentRequestPositionDetail::query()->forceCreate([
                'request_version_id' => $version->id,
                'program_id' => $program?->id,
                'department_id' => $department?->id,
                'role_description_ready' => false,
                'org_chart_updated' => false,
            ]);
        }

        return $request;
    }

    /** @return array{0: Department, 1: JobLevel, 2: ContractType} */
    private function positionPrerequisites(): array
    {
        return [
            Department::query()->forceCreate(['name' => 'Department '.uniqid(), 'code' => strtoupper(uniqid('D'))]),
            JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 2]),
            ContractType::query()->forceCreate(['name_en' => 'Annual', 'name_ar' => 'سنوي']),
        ];
    }

    private function makeProgram(string $prefix): Program
    {
        return Program::query()->forceCreate([
            'name' => ucfirst($prefix),
            'code' => strtoupper(substr($prefix, 0, 4)).uniqid(),
            'slug' => $prefix.'-'.uniqid(),
            'start_date' => now()->subYear(),
            'is_active' => true,
        ]);
    }

    private function assignPosition(
        User $user,
        City $city,
        Program $program,
        Department $department,
        JobLevel $jobLevel,
        ContractType $contractType,
    ): Position {
        $position = $this->makePosition($city, $program, $department, $jobLevel, 'assigned');

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

    private function makePosition(
        City $city,
        Program $program,
        Department $department,
        JobLevel $jobLevel,
        string $prefix,
    ): Position {
        return Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $jobLevel->id,
            'department_id' => $department->id,
            'title_en' => ucfirst($prefix).' position',
            'title_ar' => 'منصب '.uniqid(),
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }

    private function makeWorkflowStep(
        RecruitmentRequest $request,
        User $assignee,
        string $role,
        ?int $scopeType,
        ?int $scopeId,
    ): RecruitmentWorkflowInstanceStep {
        $requestVersion = $request->versions()->first() ?? RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $request->requester_user_id,
            'submitted_at' => now(),
        ]);
        $now = now();
        $definitionId = DB::table('recruitment_workflow_definitions')->insertGetId([
            'name' => 'Workflow '.uniqid(),
            'request_type' => RecruitmentRequestType::CreatePosition,
            'is_active' => true,
            'created_by_user_id' => $request->requester_user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $definitionVersionId = DB::table('recruitment_workflow_definition_versions')->insertGetId([
            'definition_id' => $definitionId,
            'version_no' => 1,
            'rules_json' => json_encode([]),
            'is_active' => true,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $workflowStepId = DB::table('recruitment_workflow_steps')->insertGetId([
            'definition_version_id' => $definitionVersionId,
            'step_order' => 1,
            'step_key' => WorkflowStepKey::ManagerApproval,
            'role_key' => $role,
            'scope_rule' => $scopeType ?? RecruitmentScopeType::None,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $instanceId = DB::table('recruitment_workflow_instances')->insertGetId([
            'request_id' => $request->id,
            'request_version_id' => $requestVersion->id,
            'definition_version_id' => $definitionVersionId,
            'status' => WorkflowInstanceStatus::Running,
            'current_step_order' => 1,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return RecruitmentWorkflowInstanceStep::query()->forceCreate([
            'workflow_instance_id' => $instanceId,
            'workflow_step_id' => $workflowStepId,
            'assigned_role' => $role,
            'assigned_scope_type' => $scopeType,
            'assigned_scope_id' => $scopeId,
            'assigned_user_id' => $assignee->id,
        ]);
    }

    /** @return array{vacancy: Vacancy, campaign: HiringCampaign, jobPost: JobPost, form: ApplicationForm, link: PublicRecruitmentLink, event: RecruitmentRequestEvent} */
    private function publishingChain(RecruitmentRequest $request, Position $position, User $actor): array
    {
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => 'open',
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now(),
        ]);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id,
            'status' => HiringCampaignStatus::Draft,
            'link_mode' => 'public_open',
            'created_by_user_id' => $actor->id,
        ]);
        $jobPost = JobPost::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'status' => 'draft',
            'created_by_user_id' => $actor->id,
        ]);
        $jobPostVersionId = DB::table('job_post_versions')->insertGetId([
            'job_post_id' => $jobPost->id,
            'version_no' => 1,
            'title_en' => 'Vacancy',
            'title_ar' => 'شاغر',
            'public_payload_json' => json_encode([]),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $form = ApplicationForm::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'status' => 'draft',
            'created_by_user_id' => $actor->id,
        ]);
        $formVersionId = DB::table('application_form_versions')->insertGetId([
            'application_form_id' => $form->id,
            'version_no' => 1,
            'fields_json' => json_encode([]),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $link = PublicRecruitmentLink::query()->forceCreate([
            'campaign_id' => $campaign->id,
            'job_post_version_id' => $jobPostVersionId,
            'form_version_id' => $formVersionId,
            'token_hash' => hash('sha256', uniqid('', true)),
            'status' => 'active',
            'expires_at' => now()->addWeek(),
            'created_by_user_id' => $actor->id,
        ]);
        $event = RecruitmentRequestEvent::query()->forceCreate([
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::RequestSubmitted,
            'actor_user_id' => $request->requester_user_id,
            'actor_role' => 'employee',
        ]);

        return compact('vacancy', 'campaign', 'jobPost', 'form', 'link', 'event');
    }
}
