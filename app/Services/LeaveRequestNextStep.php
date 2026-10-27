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

    /**
     * For a decision that arrived too late (G-B-1): "This request was already
     * rejected by Nour Halabi on 23-10-2026, 14:05. Your decision was not
     * saved." When $viewer made it themselves (a repeated submit), it says so.
     */
    public function alreadyDecided(LeaveRequest $leaveRequest, ?User $viewer): string
    {
        $outcome = (int) $leaveRequest->status === LeaveRequestStatus::Accepted ? 'accepted' : 'rejected';
        $date = $this->isolate($leaveRequest->approved_at?->format('d-m-Y, H:i') ?? '—');

        if ($leaveRequest->approved_by !== null && (int) $leaveRequest->approved_by === (int) $viewer?->employee_id) {
            return __("leaves.decision.already.{$outcome}-by-you", ['date' => $date]);
        }

        $decidedBy = $this->decidedByName($leaveRequest);

        return $decidedBy
            ? __("leaves.decision.already.{$outcome}-by", ['name' => $this->isolate($decidedBy), 'date' => $date])
            : __("leaves.decision.already.{$outcome}", ['date' => $date]);
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
        $decidedBy = $this->decidedByName($leaveRequest);
        $date = $leaveRequest->approved_at?->format('d-m-Y');

        // C3: a decision HR made in the manager's place says so.
        $onBehalfOf = $leaveRequest->decided_on_behalf_of
            ? Employee::query()->find($leaveRequest->decided_on_behalf_of)?->name
            : null;

        $decision = match (true) {
            $decidedBy && $leaveRequest->decision_basis === LeaveDecisionAuthority::ON_BEHALF && $onBehalfOf => __('leaves.next-step.decided-on-behalf', [
                'name' => $this->isolate($decidedBy), 'manager' => $this->isolate($onBehalfOf), 'date' => $this->isolate((string) $date),
            ]),
            $decidedBy && $leaveRequest->decision_basis === LeaveDecisionAuthority::NO_MANAGER => __('leaves.next-step.decided-no-manager', [
                'name' => $this->isolate($decidedBy), 'date' => $this->isolate((string) $date),
            ]),
            (bool) $decidedBy => __('leaves.next-step.decided-by', ['name' => $this->isolate($decidedBy), 'date' => $this->isolate((string) $date)]),
            default => __('leaves.next-step.decided', ['date' => $this->isolate((string) ($date ?? '—'))]),
        };

        $after = $leaveRequest->hr_approved
            ? __('leaves.next-step.hr-seen')
            : __('leaves.next-step.awaiting-hr');

        return $decision.' '.$after;
    }

    private function decidedByName(LeaveRequest $leaveRequest): ?string
    {
        return $leaveRequest->approved_by
            ? Employee::query()->find($leaveRequest->approved_by)?->name
            : null;
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
