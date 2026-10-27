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

        return $this->canManage($user, $leaveRequest);
    }

    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->owns($user, $leaveRequest)) {
            return $leaveRequest->status === LeaveRequestStatus::Pending;
        }

        return $this->canManage($user, $leaveRequest);
    }

    /**
     * Only the direct manager decides; HR only on their behalf while they are
     * on leave, or when there is no direct manager (C3, DEC-05 revision, C3-Q1).
     */
    public function decide(User $user, LeaveRequest $leaveRequest): bool
    {
        return $leaveRequest->status === LeaveRequestStatus::Pending
            && app(LeaveDecisionAuthority::class)->basisFor($user, $leaveRequest) !== null;
    }

    /**
     * HR sends a decided request back to the manager as pending, with a note
     * (C3 G3, C3D-Q5). Same audience as review: HR roles and super-admin in scope.
     */
    public function object(User $user, LeaveRequest $leaveRequest): bool
    {
        return $leaveRequest->status !== LeaveRequestStatus::Pending
            && $this->isHr($user)
            && $this->canManage($user, $leaveRequest);
    }

    public function review(User $user, LeaveRequest $leaveRequest): bool
    {
        return $leaveRequest->status !== LeaveRequestStatus::Pending
            && ! $leaveRequest->hr_approved
            && $this->isHr($user)
            && $this->canManage($user, $leaveRequest);
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
