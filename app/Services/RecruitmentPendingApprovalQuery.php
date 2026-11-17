<?php

namespace App\Services;

use App\Enums\RecruitmentRequestStatus;
use App\Enums\WorkflowInstanceStatus;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class RecruitmentPendingApprovalQuery
{
    /**
     * Only the current, undecided step assigned to this exact user is pending.
     * Authorization is checked again by policy when Task 8 adds decisions.
     */
    public function forUser(User $user): Builder
    {
        return RecruitmentWorkflowInstanceStep::query()
            ->select('recruitment_workflow_instance_steps.*')
            ->join(
                'recruitment_workflow_instances as pending_instances',
                'pending_instances.id',
                '=',
                'recruitment_workflow_instance_steps.workflow_instance_id',
            )
            ->join(
                'recruitment_workflow_steps as pending_definitions',
                'pending_definitions.id',
                '=',
                'recruitment_workflow_instance_steps.workflow_step_id',
            )
            ->join(
                'recruitment_requests as pending_requests',
                'pending_requests.id',
                '=',
                'pending_instances.request_id',
            )
            ->where('recruitment_workflow_instance_steps.assigned_user_id', $user->getKey())
            ->whereNull('recruitment_workflow_instance_steps.decision')
            ->whereNull('recruitment_workflow_instance_steps.acted_at')
            ->where('pending_instances.status', WorkflowInstanceStatus::Running)
            ->where('pending_requests.status', RecruitmentRequestStatus::UnderReview)
            ->whereColumn('pending_definitions.step_order', 'pending_instances.current_step_order')
            ->with([
                'workflowStep',
                'workflowInstance.request.requester.profile',
                'workflowInstance.request.scopeCity',
                'workflowInstance.request.targetPosition',
            ])
            ->orderBy('pending_instances.started_at')
            ->orderBy('recruitment_workflow_instance_steps.id');
    }

    /**
     * Request ids currently waiting on this user, suitable for a subquery.
     *
     * This starts from the exact pending-step query above so list filters and
     * the Pending approvals screen cannot disagree about what needs action.
     */
    public function requestIdsForUser(User $user): Builder
    {
        return $this->forUser($user)
            ->withoutEagerLoads()
            ->reorder()
            ->select('pending_requests.id');
    }

    /**
     * When the pending step became the current one: the moment the step
     * before it was decided, or the start of the workflow for the first.
     * A subquery against forUser()'s aliases, for addSelect() or min().
     */
    public function waitingSince(): QueryBuilder
    {
        // Wrapped by the grammar so a configured table prefix reaches the
        // raw expression too, exactly as it does the rest of the query.
        $grammar = DB::connection()->getQueryGrammar();

        return DB::table('recruitment_workflow_instance_steps as previous_steps')
            ->selectRaw(sprintf(
                'COALESCE(MAX(%s), %s)',
                $grammar->wrap('previous_steps.acted_at'),
                $grammar->wrap('pending_instances.started_at'),
            ))
            ->whereColumn('previous_steps.workflow_instance_id', 'pending_instances.id')
            ->whereNotNull('previous_steps.acted_at');
    }
}
