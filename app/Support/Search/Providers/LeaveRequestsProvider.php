<?php

namespace App\Support\Search\Providers;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Search\NameMatch;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Leave requests by the employee's name, from the same set the All leaves page lists
 * (LeaveRequest::manageableBy: a manager's reports, a city HR's city, head office everyone),
 * for roles that hold leaves.view-all. A pending one that the viewer may decide opens its
 * decision window; any other opens the details.
 */
final class LeaveRequestsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('leaves.view-all');
    }

    public function search(User $user, string $term, int $limit): array
    {
        return LeaveRequest::query()
            ->manageableBy($user)
            ->exceptFormerStaffPending()
            ->whereHas('leaveBalance.contract.employee', fn (Builder $employee) => NameMatch::employees($employee, $term))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.employee'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (LeaveRequest $leave) use ($user): array {
                $status = (int) $leave->status;
                $decide = $status === LeaveRequestStatus::Pending && Gate::forUser($user)->allows('decide', $leave);

                return SearchResult::make(
                    'leave',
                    $leave->id,
                    (string) ($leave->leaveBalance?->contract?->employee?->name ?? '—'),
                    SearchResult::line(
                        $leave->leaveBalance?->leaveType?->name,
                        $leave->start_at && $leave->end_at ? $leave->start_at->format('d/m/Y').($leave->start_at->isSameDay($leave->end_at) ? '' : ' → '.$leave->end_at->format('d/m/Y')) : null,
                        LeaveRequestStatus::getLocalizedName($status)['value'] ?? null,
                    ),
                    route('leaves.all', ['leave' => $leave->id, 'mode' => $decide ? 'decision' : 'details']),
                );
            })
            ->all();
    }
}
