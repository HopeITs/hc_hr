<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Checklist;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The work inbox (C3; approval-source-contract.md §3): everything waiting on
 * the signed-in user, from the sources that exist (§2.1):
 *
 *   leave:{id}:decide       S1  pending request the user may decide (LeaveDecisionAuthority)
 *   leave:{id}:review       S2  decided, not yet seen by HR (LeaveRequestPolicy::review)
 *   checklist:{id}:deliver  S3  item the user is to hand over
 *   checklist:{id}:receive  S4  item the user is to take back
 *   checklist:{id}:finalize S5  returned item HR is to close
 *
 * The inbox lists and launches; each domain decides. Every row comes from
 * the source's own scoped query and, where a policy owns the decision, is
 * kept only if that policy allows the action now. The badge counts the same
 * rows, never an unscoped query. Rows carry no leave reason, pay or
 * national id (§3.2).
 */
final class WorkInbox
{
    /** At most this many rows per source are listed, and counted (c3-implementation.md §6). */
    public const PER_SOURCE = 100;

    /** @return Collection<int, array<string, mixed>> */
    public function rows(User $user): Collection
    {
        return collect()
            ->merge($this->leaveDecisions($user))
            ->merge($this->leaveReviews($user))
            ->merge($this->checklistConfirmations($user))
            ->merge($this->checklistFinalizations($user))
            ->sortBy('waiting_since')
            ->values();
    }

    public function count(User $user): int
    {
        return $this->rows($user)->count();
    }

    /** Whether the user's active role has any inbox source at all (the menu item shows then). */
    public function hasSources(User $user): bool
    {
        return collect(['leaves.decide', 'leaves.review', 'checklists.deliver', 'checklists.receive'])
            ->contains(fn (string $permission) => $user->hasActivePermission($permission))
            || ($this->isHr($user) && $user->hasActivePermission('employees.update'));
    }

    /** What the decider saw: changes when the requester edits the request or someone decides it (§4.1 `changed`). */
    public function leaveVersion(LeaveRequest $leave): string
    {
        return $leave->status.':'.$leave->updated_at?->format('Y-m-d H:i:s.u');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function leaveDecisions(User $user): Collection
    {
        if (! $user->hasActivePermission('leaves.decide')) {
            return collect();
        }

        return $this->leaveQuery($user)
            ->where('status', LeaveRequestStatus::Pending)
            ->limit(self::PER_SOURCE)
            ->get()
            ->filter(fn (LeaveRequest $leave) => Gate::forUser($user)->allows('decide', $leave))
            ->map(fn (LeaveRequest $leave) => $this->leaveRow($leave, 'decide'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function leaveReviews(User $user): Collection
    {
        if (! $this->isHr($user) || ! $user->hasActivePermission('leaves.review')) {
            return collect();
        }

        return $this->leaveQuery($user)
            ->where('status', '!=', LeaveRequestStatus::Pending)
            ->where('hr_approved', false)
            ->limit(self::PER_SOURCE)
            ->get()
            ->filter(fn (LeaveRequest $leave) => Gate::forUser($user)->allows('review', $leave))
            ->map(fn (LeaveRequest $leave) => $this->leaveRow($leave, 'review'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function checklistConfirmations(User $user): Collection
    {
        if (! $user->employee_id) {
            return collect();
        }

        $rows = collect();

        // The same permissions that open the pending-deliveries and pending-returns pages.
        if ($user->hasActivePermission('checklists.deliver')) {
            $rows = $rows->merge($this->checklistQuery()
                ->where('delivered_id', $user->employee_id)
                ->where('delivered_status', 'pending')
                ->limit(self::PER_SOURCE)
                ->get()
                ->map(fn (Checklist $item) => $this->checklistRow($item, 'deliver', $item->created_at)));
        }

        if ($user->hasActivePermission('checklists.receive')) {
            $rows = $rows->merge($this->checklistQuery()
                ->where('receiver_id', $user->employee_id)
                ->where('returned_status', 'pending')
                ->limit(self::PER_SOURCE)
                ->get()
                ->map(fn (Checklist $item) => $this->checklistRow($item, 'receive', $item->returned_at ?? $item->updated_at)));
        }

        return $rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function checklistFinalizations(User $user): Collection
    {
        if (! $this->isHr($user) || ! $user->hasActivePermission('employees.update')) {
            return collect();
        }

        $cityIds = $user->activeCityIds();

        return $this->checklistQuery()
            ->where('returned_status', 'received')
            ->whereHas('employee', fn (Builder $employee) => $employee
                ->when($cityIds !== null, fn (Builder $q) => $q->whereIn('city_id', $cityIds)))
            ->limit(self::PER_SOURCE)
            ->get()
            ->filter(fn (Checklist $item) => $item->employee && Gate::forUser($user)->allows('update', $item->employee))
            ->map(fn (Checklist $item) => $this->checklistRow($item, 'finalize', $item->returned_at ?? $item->updated_at));
    }

    private function leaveQuery(User $user): Builder
    {
        return LeaveRequest::query()
            ->manageableBy($user)
            // Former staff and drafts never appear (approval-source-contract.md §5).
            ->whereHas('leaveBalance.contract.employee', fn (Builder $employee) => $employee->where('is_active', true))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.employee.city', 'leaveBalance.contract.position.center'])
            ->orderBy('created_at');
    }

    private function checklistQuery(): Builder
    {
        return Checklist::query()
            ->whereHas('employee', fn (Builder $employee) => $employee->where('is_active', true))
            ->with(['employee.city', 'item'])
            ->orderBy('created_at');
    }

    /** @return array<string, mixed> */
    private function leaveRow(LeaveRequest $leave, string $action): array
    {
        $employee = $leave->leaveBalance->contract->employee;

        return [
            'key' => "leave:{$leave->id}:{$action}",
            'source' => 'leave',
            'action' => $action,
            'id' => $leave->id,
            'subject' => $employee,
            'leave_type' => $leave->leaveBalance->leaveType?->name,
            'type' => $leave->type,
            'start_at' => $leave->start_at,
            'end_at' => $leave->end_at,
            'duration' => $leave->duration,
            'status' => (int) $leave->status,
            'city' => $employee->city?->name,
            'waiting_since' => $action === 'review' ? ($leave->approved_at ?? $leave->updated_at) : $leave->created_at,
            // Leave starting within two days, or already started, and still waiting.
            'urgent' => $action === 'decide' && $leave->start_at !== null && $leave->start_at->lte(now()->addDays(2)->endOfDay()),
            'version' => $this->leaveVersion($leave),
            'bulk' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function checklistRow(Checklist $item, string $action, $since): array
    {
        return [
            'key' => "checklist:{$item->id}:{$action}",
            'source' => 'checklist',
            'action' => $action,
            'id' => $item->id,
            'subject' => $item->employee,
            'item' => $item->item?->name,
            'city' => $item->employee?->city?->name,
            'waiting_since' => $since,
            'urgent' => false,
            'version' => null,
            // The only bulk action (C3D-Q4): delivery and return confirmations.
            'bulk' => in_array($action, ['deliver', 'receive'], true),
        ];
    }

    private function isHr(User $user): bool
    {
        $role = (string) $user->myRole();

        return $role === 'super-admin' || str_starts_with($role, 'HR-');
    }
}
