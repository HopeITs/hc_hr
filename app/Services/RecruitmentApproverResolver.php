<?php

namespace App\Services;

use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowStepKey;
use App\Models\Contract;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the single person assigned to a workflow step from live HR data.
 *
 * The resolver deliberately fails closed. Zero or multiple candidates produce
 * an unassigned mandatory step, allowing HR to repair the data without the
 * system selecting somebody arbitrarily or bypassing an approval.
 */
class RecruitmentApproverResolver
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function resolve(
        RecruitmentRequest $request,
        RecruitmentWorkflowStep $step,
    ): RecruitmentApproverAssignment {
        return match ((int) $step->step_key) {
            WorkflowStepKey::ManagerApproval => $this->manager($request),
            WorkflowStepKey::FinanceApproval => $this->finance(),
            WorkflowStepKey::GovernorateHrApproval => $this->governorateHr($request),
            WorkflowStepKey::HrSyriaDirectOpenApproval => $this->hrSyria(),
            default => new RecruitmentApproverAssignment(
                (string) $step->role_key,
                RecruitmentScopeType::None,
                null,
                null,
                ['reason' => 'unknown_step'],
            ),
        };
    }

    /**
     * People the ordinary workflow would resolve for an external confirmation.
     *
     * Direct openings are documented rather than electronically approved, but
     * their recorded people still come from the same live approval resolver.
     * Multiple candidates are returned so HR can record the person who really
     * confirmed the decision; the electronic workflow itself still fails closed
     * when it cannot choose exactly one.
     *
     * @return Collection<int, User>
     */
    public function confirmationCandidates(
        User $requester,
        int $stepKey,
        ?int $scopeCityId = null,
    ): Collection {
        $ids = match ($stepKey) {
            WorkflowStepKey::ManagerApproval => $this->managerCandidateIds($requester),
            WorkflowStepKey::FinanceApproval => $this->financeCandidateIds(),
            WorkflowStepKey::GovernorateHrApproval => $scopeCityId === null
                ? []
                : $this->activeUserIdsForRole($this->scopes->governorateHrRole($scopeCityId)),
            WorkflowStepKey::HrSyriaDirectOpenApproval => $this->activeUserIdsForRole('HR-SYR'),
            default => [],
        };

        return User::query()
            ->with([
                'profile.contracts.position',
                'profile.contracts.position.city',
                'profile.contracts.position.center.city',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (User $user): string => mb_strtolower((string) ($user->profile?->name ?? $user->email)))
            ->values();
    }

    private function manager(RecruitmentRequest $request): RecruitmentApproverAssignment
    {
        $requester = $request->requester()->firstOrFail();
        $managerPosition = $this->scopes->approvingManagerPosition($requester);
        $escalated = false;
        $visited = [];

        while ($managerPosition !== null && ! isset($visited[$managerPosition->getKey()])) {
            $visited[$managerPosition->getKey()] = true;
            $candidateIds = $this->activeUserIdsForPosition($managerPosition);

            if (count($candidateIds) !== 1) {
                return new RecruitmentApproverAssignment(
                    'manager',
                    RecruitmentScopeType::DirectReports,
                    (int) $managerPosition->getKey(),
                    null,
                    ['reason' => count($candidateIds) === 0 ? 'no_active_holder' : 'multiple_active_holders'],
                );
            }

            $candidateId = $candidateIds[0];

            if ($candidateId !== (int) $requester->getKey()) {
                $candidate = User::query()->findOrFail($candidateId);

                return new RecruitmentApproverAssignment(
                    $candidate->myRole() ?? 'manager',
                    RecruitmentScopeType::DirectReports,
                    (int) $managerPosition->getKey(),
                    $candidateId,
                    ['self_approval_escalated' => $escalated],
                );
            }

            $escalated = true;
            $managerPosition = $managerPosition->directManager()->first();
        }

        return new RecruitmentApproverAssignment(
            'manager',
            RecruitmentScopeType::DirectReports,
            $managerPosition?->getKey(),
            null,
            ['reason' => $escalated ? 'no_higher_manager' : 'missing_manager'],
        );
    }

    /** @return array<int, int> */
    private function managerCandidateIds(User $requester): array
    {
        $managerPosition = $this->scopes->approvingManagerPosition($requester);
        $visited = [];

        while ($managerPosition !== null && ! isset($visited[$managerPosition->getKey()])) {
            $visited[$managerPosition->getKey()] = true;
            $candidateIds = $this->activeUserIdsForPosition($managerPosition);

            if ($candidateIds === []) {
                return [];
            }

            if (count($candidateIds) > 1 || $candidateIds[0] !== (int) $requester->getKey()) {
                return $candidateIds;
            }

            $managerPosition = $managerPosition->directManager()->first();
        }

        return [];
    }

    private function finance(): RecruitmentApproverAssignment
    {
        $ids = $this->financeCandidateIds();

        return new RecruitmentApproverAssignment(
            'finance-approver',
            RecruitmentScopeType::Organization,
            null,
            count($ids) === 1 ? $ids[0] : null,
            count($ids) === 1 ? [] : ['reason' => $ids === [] ? 'no_active_holder' : 'multiple_active_holders'],
        );
    }

    /** @return array<int, int> */
    private function financeCandidateIds(): array
    {
        return User::query()
            ->whereIn('id', $this->scopes->financeApproverUserIds())
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function governorateHr(RecruitmentRequest $request): RecruitmentApproverAssignment
    {
        $role = $this->scopes->governorateHrRole($request->scope_city_id);
        $ids = $this->activeUserIdsForRole($role);

        return new RecruitmentApproverAssignment(
            $role,
            RecruitmentScopeType::City,
            $request->scope_city_id,
            count($ids) === 1 ? $ids[0] : null,
            count($ids) === 1 ? [] : ['reason' => $ids === [] ? 'no_active_holder' : 'multiple_active_holders'],
        );
    }

    private function hrSyria(): RecruitmentApproverAssignment
    {
        $ids = $this->activeUserIdsForRole('HR-SYR');

        return new RecruitmentApproverAssignment(
            'HR-SYR',
            RecruitmentScopeType::Organization,
            null,
            count($ids) === 1 ? $ids[0] : null,
            count($ids) === 1 ? [] : ['reason' => $ids === [] ? 'no_active_holder' : 'multiple_active_holders'],
        );
    }

    /** @return array<int, int> */
    private function activeUserIdsForPosition(Position $position): array
    {
        return User::query()
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->whereIn('employee_id', Contract::query()
                ->activeNow()
                ->where('position_id', $position->getKey())
                ->select('employee_id'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array<int, int> */
    private function activeUserIdsForRole(string $role): array
    {
        return DB::table(config('permission.table_names.model_has_roles').' as assignments')
            ->join(config('permission.table_names.roles').' as roles', 'roles.id', '=', 'assignments.role_id')
            ->join('users', 'users.id', '=', 'assignments.model_id')
            ->join('employees', 'employees.id', '=', 'users.employee_id')
            ->where('assignments.model_type', User::class)
            ->where('assignments.is_active', true)
            ->where('roles.name', $role)
            ->where('employees.is_active', true)
            ->distinct()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
