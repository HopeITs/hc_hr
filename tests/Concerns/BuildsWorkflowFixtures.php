<?php

namespace Tests\Concerns;

use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowStepKey;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\User;
use App\Services\RecruitmentWorkflowInstanceService;
use App\Support\AccessPermissionCatalog;

/**
 * A request part-way through its approvals, with the three approvers behind it.
 *
 * Getting a workflow to the point where somebody can decide takes an org chart,
 * a financial approver, a governorate HR, a definition and a submitted version.
 * A test about deciding should not have to restate all of that.
 */
trait BuildsWorkflowFixtures
{
    /**
     * @return array{
     *   request: RecruitmentRequest,
     *   instance: \App\Models\RecruitmentWorkflowInstance,
     *   requester: User, manager: User, finance: User, hr: User,
     *   position: Position
     * }
     */
    protected function workflow(string $citySlug = 'HMS', string $hrRole = 'HR-HMS'): array
    {
        $city = $this->makeCity($citySlug, (string) random_int(100, 899));

        $requester = $this->makeUser($city, 'manager');
        $manager = $this->makeUser($city, 'manager');
        $finance = $this->makeUser($city, 'manager');
        // CreatesHrFixtures builds roles through Spatie's class, which does not
        // run the automatic grant on App\Models\Role, so the governorate HR is
        // given its baseline here. manager comes with its own from the migration.
        $hr = $this->makeUser($city, $hrRole, AccessPermissionCatalog::roleGrantableNames());

        $department = Department::query()->forceCreate([
            'name' => 'Department '.uniqid(), 'code' => strtoupper(uniqid('D')),
        ]);
        $level = JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 3]);
        $contractType = ContractType::query()->forceCreate(['name_en' => 'Annual', 'name_ar' => 'سنوي']);
        $program = Program::query()->forceCreate([
            'name' => 'Program '.uniqid(), 'code' => strtoupper(uniqid('P')),
            'slug' => 'program-'.uniqid(), 'start_date' => now()->subYear(), 'is_active' => true,
        ]);

        $managerPosition = $this->workflowPosition($manager, $city, $program, $department, $level, $contractType, null, 'manager');
        $position = $this->workflowPosition($requester, $city, $program, $department, $level, $contractType, $managerPosition, 'requester');

        // The financial approver is a person, never a role (D29).
        $finance->givePermissionTo(Permission::findByName('recruitment-requests.approve-finance'));

        $this->workflowDefinition($requester);

        $request = RecruitmentRequest::query()->create([
            'request_number' => 'RR-'.uniqid(),
            'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::FillVacancy,
            'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Submitted,
            'scope_city_id' => $city->id,
            'target_position_id' => $position->id,
            'submitted_at' => now(),
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $requester->id,
            'submitted_at' => now(),
        ]);

        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request->fresh(), $version);

        return compact('request', 'instance', 'requester', 'manager', 'finance', 'hr', 'position')
            + ['instance' => $instance->fresh(['steps.workflowStep', 'request', 'requestVersion'])];
    }

    private function workflowPosition(
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
            'title_en' => ucfirst($label).' '.uniqid(),
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

    private function workflowDefinition(User $creator): void
    {
        $definition = RecruitmentWorkflowDefinition::query()->create([
            'name' => 'Test workflow '.uniqid(),
            'request_type' => RecruitmentRequestType::FillVacancy,
            'is_active' => true,
            'created_by_user_id' => $creator->id,
        ]);

        $version = $definition->versions()->create([
            'version_no' => 1, 'rules_json' => [], 'is_active' => true, 'published_at' => now(),
        ]);

        $normal = ['modes' => [RecruitmentRequestMode::Normal]];

        $version->steps()->createMany([
            ['step_order' => 1, 'step_key' => WorkflowStepKey::ManagerApproval, 'role_key' => 'manager', 'scope_rule' => RecruitmentScopeType::DirectReports, 'required_condition_json' => $normal],
            ['step_order' => 2, 'step_key' => WorkflowStepKey::FinanceApproval, 'role_key' => 'finance-approver', 'scope_rule' => RecruitmentScopeType::Organization, 'required_condition_json' => $normal],
            ['step_order' => 3, 'step_key' => WorkflowStepKey::GovernorateHrApproval, 'role_key' => 'governorate-hr', 'scope_rule' => RecruitmentScopeType::City, 'required_condition_json' => $normal],
        ]);
    }
}
