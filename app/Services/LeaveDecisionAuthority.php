<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may accept or reject a pending leave request (C3; DEC-05 revision,
 * approval-source-contract.md §4.3, C3-Q1):
 *
 *  - manager:    the requester's direct manager, i.e. whoever holds the
 *                position their contract's position reports to
 *                (direct_manager_id) on a contract running today, whatever
 *                their system role: the position is the authority, with no
 *                escalation (DEC-05 revision 2, G-C-1);
 *  - on_behalf:  HR in scope, only while every such manager is on an
 *                accepted leave covering now; shown as "on behalf of <name>";
 *  - no_manager: HR in scope, when the requester has no direct manager (no
 *                manager position, or nobody holds it) (C3-Q1).
 *
 * Nobody decides their own request, on any basis (G-C-2), and nobody decides
 * a former employee's (G-C-11). The technical
 * manager no longer exists. HR's role must also hold leaves.decide. Seeing a
 * request (LeaveRequest::manageableBy) is wider than deciding it: HR still
 * sees and reviews its city's requests.
 */
final class LeaveDecisionAuthority
{
    public const MANAGER = 'manager';

    public const ON_BEHALF = 'on_behalf';

    public const NO_MANAGER = 'no_manager';

    /** The basis on which $user may decide $leaveRequest, or null. Status is not checked here. */
    public function basisFor(User $user, LeaveRequest $leaveRequest): ?string
    {
        if ($user->employee_id !== null && (int) $leaveRequest->leaveBalance?->contract?->employee_id === (int) $user->employee_id) {
            return null;
        }

        // A former employee's request is nobody's pending work (approval-source-contract.md §5, G-C-11 F10).
        if (! $leaveRequest->leaveBalance?->contract?->employee?->is_active) {
            return null;
        }

        $managers = $this->directManagers($leaveRequest);

        if ($user->employee_id !== null && $managers->contains('id', $user->employee_id)) {
            return self::MANAGER;
        }

        if (! $user->hasActivePermission('leaves.decide')) {
            return null;
        }

        if (! $this->isHrDecider($user) || ! LeaveRequest::query()->manageableBy($user)->whereKey($leaveRequest->getKey())->exists()) {
            return null;
        }

        if ($managers->isEmpty()) {
            return self::NO_MANAGER;
        }

        return $managers->every(fn (Employee $manager) => $this->isOnLeaveNow($manager)) ? self::ON_BEHALF : null;
    }

    /**
     * The active employees holding the requester's direct-manager position on
     * a contract running today.
     *
     * @return Collection<int, Employee>
     */
    public function directManagers(LeaveRequest $leaveRequest): Collection
    {
        $managerPositionId = $leaveRequest->leaveBalance?->contract?->position?->direct_manager_id;

        if (! $managerPositionId) {
            return collect();
        }

        return Contract::query()
            ->activeNow()
            ->where('position_id', $managerPositionId)
            ->with('employee')
            ->get()
            ->pluck('employee')
            ->filter(fn (?Employee $employee) => $employee?->is_active)
            ->unique('id')
            ->values();
    }

    /** Is $employee on an accepted leave that covers now? A day leave covers its whole days; an hourly one its hours. */
    public function isOnLeaveNow(Employee $employee): bool
    {
        $now = now();

        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Accepted)
            ->whereHas('leaveBalance.contract', fn (Builder $contract) => $contract->where('employee_id', $employee->id))
            ->where(fn (Builder $covers) => $covers
                ->where(fn (Builder $hour) => $hour->where('type', 'hour')->where('start_at', '<=', $now)->where('end_at', '>=', $now))
                ->orWhere(fn (Builder $day) => $day->where('type', '!=', 'hour')
                    ->whereDate('start_at', '<=', $now->toDateString())
                    ->whereDate('end_at', '>=', $now->toDateString())))
            ->exists();
    }

    /** Does $user hold, today, a position another position reports to? */
    public function holdsManagerPosition(User $user): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        $positionIds = Contract::query()->activeNow()->where('employee_id', $user->employee_id)->pluck('position_id');

        return $positionIds->isNotEmpty()
            && Position::query()->whereIn('direct_manager_id', $positionIds)->exists();
    }

    /** HR roles and super-admin may stand in for a manager. */
    private function isHrDecider(User $user): bool
    {
        $role = (string) $user->myRole();

        return $role === 'super-admin' || str_starts_with($role, 'HR-');
    }
}
