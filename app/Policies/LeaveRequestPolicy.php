<?php

namespace App\Policies;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveDecisionAuthority;

class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest)
            || $this->canManage($user, $leaveRequest);
    }

    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->owns($user, $leaveRequest)) {
            return $leaveRequest->status === LeaveRequestStatus::Pending;
        }

        // A cancelled request is a closed record, like the deleted one it replaces.
        return (int) $leaveRequest->status !== LeaveRequestStatus::Cancelled
            && $this->canManage($user, $leaveRequest);
    }

    /**
     * Only an undecided request is deleted (HR-027): deleting a decided one
     * would lose its deducted hours for good. A decided leave that will not be
     * taken is withdrawn with cancel(), which keeps the record and gives them back.
     */
    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        if ((int) $leaveRequest->status !== LeaveRequestStatus::Pending) {
            return false;
        }

        return $this->owns($user, $leaveRequest) || $this->canManage($user, $leaveRequest);
    }

    /**
     * Withdraw an accepted leave before it starts: its employee, or HR in scope
     * (leaves.update, as editing a decided request). Never one already begun.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        if ((int) $leaveRequest->status !== LeaveRequestStatus::Accepted
            || $leaveRequest->start_at === null
            || ! $leaveRequest->start_at->isFuture()) {
            return false;
        }

        if ($this->owns($user, $leaveRequest)) {
            return true;
        }

        return $this->isHr($user)
            && $user->hasActivePermission('leaves.update')
            && $this->canManage($user, $leaveRequest);
    }

    /**
     * Only the direct manager decides; HR only on their behalf while they are
     * on leave, or when there is no direct manager (C3, DEC-05 revision, C3-Q1).
     *
     * $authority lets a caller checking many requests in one pass share a
     * LeaveDecisionAuthority::forOnePass() (WorkInbox); the rule is the same.
     */
    public function decide(User $user, LeaveRequest $leaveRequest, ?LeaveDecisionAuthority $authority = null): bool
    {
        return $leaveRequest->status === LeaveRequestStatus::Pending
            && ($authority ?? app(LeaveDecisionAuthority::class))->basisFor($user, $leaveRequest) !== null;
    }

    /**
     * HR sends a decided request back to the manager as pending, with a note
     * (C3 G3, C3D-Q5). Same audience as review: HR roles and super-admin in
     * scope, holding leaves.review like the leave list's row action (G-C-3).
     */
    public function object(User $user, LeaveRequest $leaveRequest): bool
    {
        return in_array((int) $leaveRequest->status, [LeaveRequestStatus::Accepted, LeaveRequestStatus::Rejected], true)
            && $this->isHr($user)
            && $user->hasActivePermission('leaves.review')
            && $this->canManage($user, $leaveRequest);
    }

    /**
     * "Seen by HR": the permission is checked here, not only by the menu or the route (G-C-3).
     * $authority: as for decide(), a one-pass authority answers the scope question.
     */
    public function review(User $user, LeaveRequest $leaveRequest, ?LeaveDecisionAuthority $authority = null): bool
    {
        return in_array((int) $leaveRequest->status, [LeaveRequestStatus::Accepted, LeaveRequestStatus::Rejected], true)
            && ! $leaveRequest->hr_approved
            // Nobody marks their own decision as seen (N-07).
            && ! ($user->employee_id !== null && (int) $leaveRequest->approved_by === (int) $user->employee_id)
            // Nor reviews their own request, as nobody decides it (G-C-2).
            && ! $this->owns($user, $leaveRequest)
            && $this->isHr($user)
            && $user->hasActivePermission('leaves.review')
            && ($authority ? $authority->isManageableBy($user, $leaveRequest) : $this->canManage($user, $leaveRequest));
    }

    private function isHr(User $user): bool
    {
        $role = (string) $user->myRole();

        return $role === 'super-admin' || str_starts_with($role, 'HR-');
    }

    private function owns(User $user, LeaveRequest $leaveRequest): bool
    {
        $employeeId = $leaveRequest->leaveBalance?->contract?->employee_id;

        return $user->employee_id !== null
            && $user->employee_id === $employeeId;
    }

    private function canManage(User $user, LeaveRequest $leaveRequest): bool
    {
        return LeaveRequest::query()
            ->manageableBy($user)
            ->whereKey($leaveRequest->getKey())
            ->exists();
    }
}
