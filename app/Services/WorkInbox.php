<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Checklist;
use App\Models\LeaveRequest;
use App\Models\ProfileChangeRequest;
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
 *   profile:{id}:approve    D1  a change an employee proposed to their own profile (profile-contract.md §5.1)
 *
 * The inbox lists and launches; each domain decides. Every row comes from
 * the source's own scoped query and, where a policy owns the decision, is
 * kept only if that policy allows the action now. The badge counts the same
 * rows, never an unscoped query. Rows carry no leave reason, pay or
 * national id (§3.2).
 */
final class WorkInbox
{
    /**
     * At most this many rows per source are listed (c3-implementation.md §6).
     * The counts are not capped: each source counts every row it would list
     * (G-C-7), and the page says when it shows fewer.
     */
    public const PER_SOURCE = 100;

    /** @return Collection<int, array<string, mixed>> */
    public function rows(User $user): Collection
    {
        return $this->snapshot($user)['rows'];
    }

    public function count(User $user): int
    {
        return $this->snapshot($user)['counts']['all'];
    }

    /**
     * The listed rows and the exact counts, from one pass over the sources.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, counts: array{all: int, decide: int, review: int, checklist: int, profile: int}}
     */
    public function snapshot(User $user): array
    {
        [$decide, $decideTotal] = $this->leaveDecisions($user);
        [$review, $reviewTotal] = $this->leaveReviews($user);
        [$checklist, $checklistTotal] = $this->checklistConfirmations($user);
        [$finalize, $finalizeTotal] = $this->checklistFinalizations($user);
        [$profile, $profileTotal] = $this->profileChanges($user);

        return [
            'rows' => collect()
                ->merge($decide)
                ->merge($review)
                ->merge($checklist)
                ->merge($finalize)
                ->merge($profile)
                ->sortBy('waiting_since')
                ->values(),
            'counts' => [
                'all' => $decideTotal + $reviewTotal + $checklistTotal + $finalizeTotal + $profileTotal,
                'decide' => $decideTotal,
                'review' => $reviewTotal,
                'checklist' => $checklistTotal + $finalizeTotal,
                'profile' => $profileTotal,
            ],
        ];
    }

    /** Whether the user's active role has any inbox source at all (the menu item shows then). */
    public function hasSources(User $user): bool
    {
        return collect(['leaves.decide', 'leaves.review', 'checklists.deliver', 'checklists.receive'])
            ->contains(fn (string $permission) => $user->hasActivePermission($permission))
            || ($this->isHr($user) && $user->hasActivePermission('employees.update'))
            // A direct manager decides whatever their role (DEC-05 revision 2).
            || app(LeaveDecisionAuthority::class)->holdsManagerPosition($user);
    }

    /** What the decider saw: changes when the requester edits the request or someone decides it (§4.1 `changed`). */
    public function leaveVersion(LeaveRequest $leave): string
    {
        return $leave->status.':'.$leave->updated_at?->format('Y-m-d H:i:s.u');
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    private function leaveDecisions(User $user): array
    {
        // No permission check here: the direct manager decides whatever their
        // role (DEC-05 revision 2); the decide policy below is the gate. It
        // cannot be put in SQL, so it runs on every pending request before the
        // cap, never after it (G-C-7): a decidable request is never pushed out
        // by ones the user may only see.
        $rows = collect();
        $total = 0;

        $this->leaveQuery($user)
            ->where('status', LeaveRequestStatus::Pending)
            ->lazy(self::PER_SOURCE)
            ->each(function (LeaveRequest $leave) use ($user, $rows, &$total): void {
                if (! Gate::forUser($user)->allows('decide', $leave)) {
                    return;
                }

                $total++;

                if ($rows->count() < self::PER_SOURCE) {
                    $rows->push($this->leaveRow($leave, 'decide'));
                }
            });

        return [$rows, $total];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    private function leaveReviews(User $user): array
    {
        if (! $this->isHr($user) || ! $user->hasActivePermission('leaves.review')) {
            return [collect(), 0];
        }

        // The query is the review policy (HR role, permission, scope, decided,
        // not yet seen), so its count is exact; the policy re-checks what is listed.
        $query = $this->leaveQuery($user)
            ->where('status', '!=', LeaveRequestStatus::Pending)
            ->where('hr_approved', false)
            // Not the reviewer's own decision (N-07), as the policy says.
            ->when($user->employee_id !== null, fn (Builder $q) => $q->where(fn (Builder $by) => $by
                ->whereNull('approved_by')
                ->orWhere('approved_by', '!=', $user->employee_id)));

        return [
            (clone $query)->limit(self::PER_SOURCE)->get()
                ->filter(fn (LeaveRequest $leave) => Gate::forUser($user)->allows('review', $leave))
                ->map(fn (LeaveRequest $leave) => $this->leaveRow($leave, 'review')),
            $query->count(),
        ];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    private function checklistConfirmations(User $user): array
    {
        if (! $user->employee_id) {
            return [collect(), 0];
        }

        $rows = collect();
        $total = 0;

        // The same permissions that open the pending-deliveries and pending-returns pages.
        if ($user->hasActivePermission('checklists.deliver')) {
            $query = $this->checklistQuery()
                ->where('delivered_id', $user->employee_id)
                ->where('delivered_status', 'pending');

            $rows = $rows->merge((clone $query)->limit(self::PER_SOURCE)->get()
                ->map(fn (Checklist $item) => $this->checklistRow($item, 'deliver', $item->created_at)));
            $total += $query->count();
        }

        if ($user->hasActivePermission('checklists.receive')) {
            $query = $this->checklistQuery()
                ->where('receiver_id', $user->employee_id)
                ->where('returned_status', 'pending');

            $rows = $rows->merge((clone $query)->limit(self::PER_SOURCE)->get()
                ->map(fn (Checklist $item) => $this->checklistRow($item, 'receive', $item->returned_at ?? $item->updated_at)));
            $total += $query->count();
        }

        return [$rows, $total];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    private function checklistFinalizations(User $user): array
    {
        if (! $this->isHr($user) || ! $user->hasActivePermission('employees.update')) {
            return [collect(), 0];
        }

        $cityIds = $user->activeCityIds();

        // The city filter is EmployeePolicy::update's scope, so the count is
        // exact; the policy re-checks what is listed.
        $query = $this->checklistQuery()
            ->where('returned_status', 'received')
            ->whereHas('employee', fn (Builder $employee) => $employee
                ->when($cityIds !== null, fn (Builder $q) => $q->whereIn('city_id', $cityIds)));

        return [
            (clone $query)->limit(self::PER_SOURCE)->get()
                ->filter(fn (Checklist $item) => $item->employee && Gate::forUser($user)->allows('update', $item->employee))
                ->map(fn (Checklist $item) => $this->checklistRow($item, 'finalize', $item->returned_at ?? $item->updated_at)),
            $query->count(),
        ];
    }

    /**
     * D1: HR of the employee's city approves what an employee proposed to
     * their own profile; the same rule as editing the record.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: int}
     */
    private function profileChanges(User $user): array
    {
        if (! $this->isHr($user) || ! $user->hasActivePermission('employees.update')) {
            return [collect(), 0];
        }

        $cityIds = $user->activeCityIds();

        // The city filter is EmployeePolicy::update's scope, so the count is
        // exact; the policy re-checks what is listed.
        $query = ProfileChangeRequest::query()->pending()
            ->whereHas('employee', fn (Builder $employee) => $employee->where('is_active', true)
                ->when($cityIds !== null, fn (Builder $q) => $q->whereIn('city_id', $cityIds)));

        return [(clone $query)
            ->with(['employee.city', 'media'])
            ->orderBy('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->filter(fn (ProfileChangeRequest $change) => Gate::forUser($user)->allows('update', $change->employee))
            ->map(fn (ProfileChangeRequest $change) => [
                'key' => "profile:{$change->id}:approve",
                'source' => 'profile',
                'action' => 'approve',
                'id' => $change->id,
                'subject' => $change->employee,
                'item' => __('employees.profile.change.sections.'.$change->section),
                'section' => $change->section,
                'proposed' => $change->proposed,
                'file_url' => ($media = $change->getFirstMedia('file') ?? $change->getFirstMedia('photo'))
                    ? route('employee-files.download', $media)
                    : null,
                'city' => $change->employee?->city?->name,
                'waiting_since' => $change->created_at,
                'urgent' => false,
                'version' => null,
                'bulk' => false,
            ]),
            $query->count(),
        ];
    }

    private function leaveQuery(User $user): Builder
    {
        return LeaveRequest::query()
            ->manageableBy($user)
            // Former staff and drafts never appear (approval-source-contract.md §5);
            // the decide policy refuses them too (G-C-11).
            ->whereHas('leaveBalance.contract.employee', fn (Builder $employee) => $employee->where('is_active', true))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.employee.city', 'leaveBalance.contract.position.center'])
            ->orderBy('created_at');
    }

    private function checklistQuery(): Builder
    {
        // Former staff included (decided 2026-10-27, G-C-6): taking equipment
        // back from a leaver is the main case for S4, and the Take back / Hand
        // out counters count these items too. The row says the person left.
        return Checklist::query()
            ->whereHas('employee')
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
            'former' => false,
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
            'former' => $item->employee !== null && ! $item->employee->is_active,
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
