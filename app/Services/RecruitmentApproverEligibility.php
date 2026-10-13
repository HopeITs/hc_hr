<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Position;
use App\Models\User;

/**
 * Could a recruitment approval ever land on this user?
 *
 * The `manager` role carries the approve permission because the same role
 * both raises requests and approves them, so the permission alone puts
 * "Pending approvals" in front of every project and department manager —
 * most of whom will never be handed a step. This answers the narrower
 * question from where the approvals actually go (RecruitmentApproverResolver):
 *
 * - Manager step: the holder of the position directly above the requester.
 *   Only positions at a requester grade raise requests, so only their
 *   direct managers can receive this step.
 * - Finance step: the one person granted the finance-approver permission
 *   directly — the Senior Financial Manager. Never a role.
 * - Governorate HR / direct opening: HR roles.
 * - Anything already waiting for this user, whatever the structure says.
 *   A request escalated past a self-approving manager can reach someone the
 *   rules above do not name, and it must never become invisible.
 *
 * It is about eligibility, not the queue: an approver keeps the page while
 * their queue is empty. Deciding a step is still authorised separately.
 */
class RecruitmentApproverEligibility
{
    public function __construct(
        private readonly RecruitmentScopeResolver $scopes,
        private readonly RecruitmentPendingApprovalQuery $pending,
    ) {}

    public function mayReceiveApprovals(User $user): bool
    {
        if (! $user->hasActivePermission('recruitment-requests.approve')) {
            return false;
        }

        return $user->activeRoleIs('HR')
            || $this->isFinanceApprover($user)
            || $this->managesARequesterPosition($user)
            || $this->pending->forUser($user)->exists();
    }

    public function isFinanceApprover(User $user): bool
    {
        return in_array((int) $user->getKey(), $this->scopes->financeApproverUserIds(), true);
    }

    /**
     * Does a position that may raise requests report directly to any
     * position this user holds now? Every active contract counts, the same
     * way the resolver finds a position's holders.
     */
    public function managesARequesterPosition(User $user): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        $held = Contract::query()
            ->activeNow()
            ->where('employee_id', $user->employee_id)
            ->select('position_id');

        return Position::query()
            ->whereIn('direct_manager_id', $held)
            ->where('is_active', true)
            ->whereHas('jobLevel', fn ($levels) => $levels
                ->whereIn('grade', config('recruitment.requester_job_level_grades', [])))
            ->exists();
    }
}
