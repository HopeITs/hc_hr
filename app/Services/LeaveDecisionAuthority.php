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

    /**
     * Answers kept for one pass over many requests (forOnePass()), or null:
     * an instance from the container asks the database on every call.
     *
     * scope holds, per user and active role, request id => in manageableBy;
     * pendingRead marks the users whose pending requests were read at once.
     *
     * @var array{managers: array<int, Collection<int, Employee>>, onLeave: array<int, bool>, scope: array<string, array<int, bool>>, pendingRead: array<string, true>}|null
     */
    private ?array $memo = null;

    /**
     * An authority for one pass over many requests (the work inbox snapshot):
     * it remembers, for its own lifetime only, who holds each manager
     * position, who of them is on leave now, and which pending requests each
     * user's active role may manage. Every answer is the one the plain
     * authority gives; it is only asked once. Never keep one beyond the pass.
     */
    public static function forOnePass(): self
    {
        $authority = new self;
        $authority->memo = ['managers' => [], 'onLeave' => [], 'scope' => [], 'pendingRead' => []];

        return $authority;
    }

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

        if (! $this->isHrDecider($user) || ! $this->isManageableBy($user, $leaveRequest)) {
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

        if ($this->memo !== null) {
            return $this->memo['managers'][(int) $managerPositionId] ??= $this->holdersOf((int) $managerPositionId);
        }

        return $this->holdersOf((int) $managerPositionId);
    }

    /** Is $employee on an accepted leave that covers now? A day leave covers its whole days; an hourly one its hours. */
    public function isOnLeaveNow(Employee $employee): bool
    {
        if ($this->memo !== null) {
            return $this->memo['onLeave'][(int) $employee->id] ??= $this->askIsOnLeaveNow($employee);
        }

        return $this->askIsOnLeaveNow($employee);
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

    /**
     * Is $leaveRequest within LeaveRequest::manageableBy($user)? The same
     * question LeaveRequestPolicy asks. In one pass each answer is read once
     * per user and active role: the user's pending requests in one query the
     * first time a pending one is asked about, the ids given to primeScope()
     * in one query, and any other request on its own, exactly as before.
     */
    public function isManageableBy(User $user, LeaveRequest $leaveRequest): bool
    {
        $id = (int) $leaveRequest->getKey();

        if ($this->memo === null) {
            return $this->askIsManageableBy($user, $id);
        }

        $key = $this->scopeKey($user);

        if (! isset($this->memo['scope'][$key][$id])
            && $leaveRequest->status === LeaveRequestStatus::Pending
            && ! isset($this->memo['pendingRead'][$key])) {
            $this->memo['pendingRead'][$key] = true;

            foreach (LeaveRequest::query()->manageableBy($user)->where('status', LeaveRequestStatus::Pending)->pluck('id') as $inScope) {
                $this->memo['scope'][$key][(int) $inScope] = true;
            }
        }

        return $this->memo['scope'][$key][$id] ??= $this->askIsManageableBy($user, $id);
    }

    /**
     * In one pass, answer isManageableBy() for these request ids with one
     * query (the rows a caller is about to check). Does nothing otherwise.
     *
     * @param  iterable<int, int|string>  $ids
     */
    public function primeScope(User $user, iterable $ids): void
    {
        if ($this->memo === null) {
            return;
        }

        $key = $this->scopeKey($user);
        $ids = collect($ids)->map(fn ($id) => (int) $id)->reject(fn (int $id) => isset($this->memo['scope'][$key][$id]))->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $inScope = LeaveRequest::query()->manageableBy($user)->whereKey($ids->all())->pluck('id')->map(fn ($id) => (int) $id)->flip();

        foreach ($ids as $id) {
            $this->memo['scope'][$key][$id] = $inScope->has($id);
        }
    }

    /** @return Collection<int, Employee> */
    private function holdersOf(int $managerPositionId): Collection
    {
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

    private function askIsOnLeaveNow(Employee $employee): bool
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

    private function askIsManageableBy(User $user, int $id): bool
    {
        return LeaveRequest::query()->manageableBy($user)->whereKey($id)->exists();
    }

    /** The scope depends on the user and the role they act in. */
    private function scopeKey(User $user): string
    {
        return $user->getKey().':'.$user->myRole();
    }

    /** HR roles and super-admin may stand in for a manager. */
    private function isHrDecider(User $user): bool
    {
        $role = (string) $user->myRole();

        return $role === 'super-admin' || str_starts_with($role, 'HR-');
    }
}
