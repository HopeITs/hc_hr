<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Describes where a leave request stands and who acts on it next, from the
 * code that actually runs, not from an assumed approval chain:
 *
 *  - Pending: LeaveNotificationService::newRequestAudience() lists the people
 *    told about the request. A manager among them can decide only while
 *    holding the manager or general-manager role (LeaveRequest::scopeManageableBy
 *    via LeaveRequestPolicy::decide), so only those are named. City HR can
 *    always decide. Any one of them decides; there is no second approval.
 *  - Accepted / rejected: the decision is final; the only remaining step is
 *    HR marking it as seen (hr_approved, LeaveRequestPolicy::review).
 *
 * Text only: names of the employee's own managers and of the person who
 * decided. No HR account is named, and nothing about pay is read.
 */
class LeaveRequestNextStep
{
    private const DECIDING_MANAGER_ROLES = ['manager', 'general-manager'];

    public function __construct(private readonly LeaveNotificationService $notifications) {}

    public function describe(LeaveRequest $leaveRequest): string
    {
        return (int) $leaveRequest->status === LeaveRequestStatus::Pending
            ? $this->pending($leaveRequest)
            : $this->decided($leaveRequest);
    }

    private function pending(LeaveRequest $leaveRequest): string
    {
        $requester = $leaveRequest->leaveBalance?->contract?->employee;
        $audience = $this->notifications->newRequestAudience($leaveRequest, $requester?->account);

        $managers = $this->decidingManagerNames($audience['managers']);
        $city = $requester?->city?->name;
        $hrNotified = $audience['hr']->isNotEmpty();

        if ($managers->isNotEmpty() && $hrNotified) {
            return __('leaves.next-step.pending-managers-and-hr', [
                'managers' => $this->managerPhrase($managers),
                'city' => $city ?? '—',
            ]);
        }

        if ($managers->isNotEmpty()) {
            return __('leaves.next-step.pending-managers', [
                'managers' => $this->managerPhrase($managers),
            ]);
        }

        if ($hrNotified) {
            return __('leaves.next-step.pending-hr', ['city' => $city ?? '—']);
        }

        return __('leaves.next-step.pending-unassigned');
    }

    private function decided(LeaveRequest $leaveRequest): string
    {
        $decidedBy = $leaveRequest->approved_by
            ? Employee::query()->find($leaveRequest->approved_by)?->name
            : null;
        $date = $leaveRequest->approved_at?->format('d-m-Y');

        $decision = $decidedBy
            ? __('leaves.next-step.decided-by', ['name' => $this->isolate($decidedBy), 'date' => $this->isolate((string) $date)])
            : __('leaves.next-step.decided', ['date' => $this->isolate((string) ($date ?? '—'))]);

        $after = $leaveRequest->hr_approved
            ? __('leaves.next-step.hr-seen')
            : __('leaves.next-step.awaiting-hr');

        return $decision.' '.$after;
    }

    /**
     * @param  Collection<int, User>  $managers
     * @return Collection<int, string>
     */
    private function decidingManagerNames(Collection $managers): Collection
    {
        return $managers
            ->filter(fn (User $user) => $user->hasAnyRole(self::DECIDING_MANAGER_ROLES))
            ->map(fn (User $user) => $user->profile?->name)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * "your manager A" / "your managers A or B" / "… A, B or C".
     *
     * @param  Collection<int, string>  $names
     */
    private function managerPhrase(Collection $names): string
    {
        $names = $names->map(fn (string $name) => $this->isolate($name));

        $joined = $names->count() === 1
            ? $names->first()
            : __('leaves.next-step.or', [
                'first' => $names->slice(0, -1)->implode(__('leaves.next-step.list-separator')),
                'last' => $names->last(),
            ]);

        return trans_choice('leaves.next-step.managers', $names->count(), ['names' => $joined]);
    }

    /**
     * Unicode first-strong isolate, so a Latin name or an LTR date inside an
     * Arabic sentence (or the reverse) keeps its own direction.
     */
    private function isolate(string $value): string
    {
        return "\u{2068}{$value}\u{2069}";
    }
}
