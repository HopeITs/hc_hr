<?php

namespace Database\Seeders;

use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestType;
use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowStepKey;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RecruitmentWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()->orderBy('id')->firstOrFail();

        foreach ($this->requestTypes() as $requestType) {
            if (RecruitmentWorkflowDefinition::query()
                ->where('request_type', $requestType)
                ->where('is_active', true)
                ->exists()) {
                continue;
            }

            DB::transaction(function () use ($creator, $requestType): void {
                $definition = RecruitmentWorkflowDefinition::query()->create([
                    'name' => "Default recruitment workflow {$requestType}",
                    'request_type' => $requestType,
                    'is_active' => true,
                    'created_by_user_id' => $creator->getKey(),
                ]);

                $version = $definition->versions()->create([
                    'version_no' => 1,
                    'rules_json' => [
                        'normal_path' => ['manager', 'finance', 'governorate_hr'],
                        'direct_hr_path' => ['hr_syria'],
                    ],
                    'is_active' => true,
                    'published_at' => now(),
                ]);

                $normal = ['modes' => [RecruitmentRequestMode::Normal]];
                $direct = ['modes' => [RecruitmentRequestMode::DirectHr]];

                // D56: closing a vacancy or a position spends nothing — it
                // saves — so neither waits on the organization's single
                // financial approver. Those two run on two steps, not three.
                $financial = $normal + ['except_types' => [
                    RecruitmentRequestType::ClosePosition,
                    RecruitmentRequestType::CloseVacancy,
                ]];

                $version->steps()->createMany([
                    [
                        'step_order' => 1,
                        'step_key' => WorkflowStepKey::ManagerApproval,
                        'role_key' => 'manager',
                        'scope_rule' => RecruitmentScopeType::DirectReports,
                        'required_condition_json' => $normal,
                    ],
                    [
                        'step_order' => 2,
                        'step_key' => WorkflowStepKey::FinanceApproval,
                        'role_key' => 'finance-approver',
                        'scope_rule' => RecruitmentScopeType::Organization,
                        'required_condition_json' => $financial,
                    ],
                    [
                        'step_order' => 3,
                        'step_key' => WorkflowStepKey::GovernorateHrApproval,
                        'role_key' => 'governorate-hr',
                        'scope_rule' => RecruitmentScopeType::City,
                        'required_condition_json' => $normal,
                    ],
                    [
                        'step_order' => 4,
                        'step_key' => WorkflowStepKey::HrSyriaDirectOpenApproval,
                        'role_key' => 'HR-SYR',
                        'scope_rule' => RecruitmentScopeType::Organization,
                        'required_condition_json' => $direct,
                    ],
                ]);
            });
        }
    }

    /** @return array<int, int> */
    private function requestTypes(): array
    {
        return [
            RecruitmentRequestType::FillVacancy,
            RecruitmentRequestType::IncreaseHeadcount,
            RecruitmentRequestType::DecreaseHeadcount,
            RecruitmentRequestType::CreatePosition,
            RecruitmentRequestType::ClosePosition,
            RecruitmentRequestType::CloseVacancy,
        ];
    }
}
