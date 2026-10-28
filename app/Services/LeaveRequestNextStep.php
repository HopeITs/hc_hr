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
 *    told about the request. Every direct manager among them decides,
 *    whatever their role (DEC-05 revision 2); HR only stands in (C3-Q1).
 *    Any one of them decides; there is no second approval.
 *  - Accepted / rejected: the decision is final; the only remaining step is
 *    HR marking it as seen (hr_approved, LeaveRequestPolicy::review).
 *
 * Text only: names of the employee's own managers and of the person who
 * decided. No HR account is named, and nothing about pay is read.
 */
class LeaveRequestNextStep
{
    public function __construct(private readonly LeaveNotificationService $notifications) {}

    /**
     * Worded for the requester ("your manager …") unless $viewer is someone
     * else: a deciding manager reads "Waiting for your decision", anyone else
     * "the direct manager …" (G-C-12).
     */
    public function describe(LeaveRequest $leaveRequest, ?User $viewer = null): string
    {
        return (int) $leaveRequest->status === LeaveRequestStatus::Pending
            ? $this->pending($leaveRequest, $viewer)
            : $this->decided($leaveRequest);
    }

    /**
     * Who decided, on the basis stored with the decision (G-C-4): "The manager
     * A accepted", "HR (B) accepted on behalf of A", "HR (B) accepted (no
     * direct manager)". A decision from before C3 has no basis and names only
     * the person who made it.
     */
    public function decisionSummary(LeaveRequest $leaveRequest): string
    {
        $outcome = (int) $leaveRequest->status === LeaveRequestStatus::Accepted ? 'accepted' : 'rejected';
        $decidedBy = $this->decidedByName($leaveRequest);
        $onBehalfOf = $leaveRequest->decided_on_behalf_of
            ? Employee::query()->find($leaveRequest->decided_on_behalf_of)?->name
            : null;

        if ($decidedBy === null) {
            return __("leaves.decision.summary.{$outcome}");
        }

        $name = $this->isolate($decidedBy);

        return match (true) {
            $leaveRequest->decision_basis === LeaveDecisionAuthority::ON_BEHALF && $onBehalfOf !== null => __("leaves.decision.summary.{$outcome}-on-behalf", ['name' => $name, 'manager' => $this->isolate($onBehalfOf)]),
            $leaveRequest->decision_basis === LeaveDecisionAuthority::NO_MANAGER => __("leaves.decision.summary.{$outcome}-no-manager", ['name' => $name]),
            $leaveRequest->decision_basis === LeaveDecisionAuthority::MANAGER => __("leaves.decision.summary.{$outcome}-manager", ['name' => $name]),
            default => __("leaves.decision.summary.{$outcome}-by", ['name' => $name]),
        };
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

    private function pending(LeaveRequest $leaveRequest, ?User $viewer): string
    {
        $requester = $leaveRequest->leaveBalance?->contract?->employee;
        $audience = $this->notifications->newRequestAudience($leaveRequest, $requester?->account);

        if ($viewer !== null && $audience['managers']->contains('id', $viewer->id)) {
            return __('leaves.next-step.pending-you');
        }

        // HR reading its own notice (N-03): "you", never "the HR team".
        if ($viewer !== null && $audience['hr']->contains('id', $viewer->id)) {
            if (app(LeaveDecisionAuthority::class)->basisFor($viewer, $leaveRequest) !== null) {
                return __('leaves.next-step.pending-you');
            }

            $managers = $this->decidingManagerNames($audience['managers']);

            if ($managers->isNotEmpty()) {
                $female = $this->isOneFemale($audience['managers']);

                return __('leaves.next-step.pending-managers-hr-reader', [
                    'managers' => $this->managerPhrase($managers, false, $female),
                    'to' => $this->notifiedPronoun($managers, $female),
                ]);
            }
        }

        $forRequester = $viewer === null || ($requester !== null && (int) $viewer->employee_id === (int) $requester->id);
        $managers = $this->decidingManagerNames($audience['managers']);
        $female = $this->isOneFemale($audience['managers']);
        $city = $requester?->city?->name;
        $hrNotified = $audience['hr']->isNotEmpty();

        if ($managers->isNotEmpty() && $hrNotified) {
            return __('leaves.next-step.pending-managers-and-hr', [
                'managers' => $this->managerPhrase($managers, $forRequester, $female),
                'city' => $city ?? '—',
                'to' => $this->notifiedPronoun($managers, $female),
            ]);
        }

        if ($managers->isNotEmpty()) {
            return __('leaves.next-step.pending-managers', [
                'managers' => $this->managerPhrase($managers, $forRequester, $female),
                'to' => $this->notifiedPronoun($managers, $female),
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
            ->map(fn (User $user) => $user->profile?->name)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * "your manager A" / "your managers A or B" / "… A, B or C"; "the direct
     * manager A" when the reader is not the requester.
     *
     * @param  Collection<int, string>  $names
     */
    private function managerPhrase(Collection $names, bool $forRequester = true, bool $female = false): string
    {
        $key = ($forRequester ? 'leaves.next-step.managers' : 'leaves.next-step.managers-other').($female ? '-female' : '');

        $names = $names->map(fn (string $name) => $this->isolate($name));

        $joined = $names->count() === 1
            ? $names->first()
            : __('leaves.next-step.or', [
                'first' => $names->slice(0, -1)->implode(__('leaves.next-step.list-separator')),
                'last' => $names->last(),
            ]);

        return trans_choice($key, $names->count(), ['names' => $joined]);
    }

    /** One deciding manager, recorded as female: Arabic agrees with her (N-09). */
    private function isOneFemale(Collection $managers): bool
    {
        return $managers->count() === 1
            && (int) $managers->first()->profile?->gender === \App\Enums\Gender::Female;
    }

    /** "him / her / them" after "a notification was sent to" (Arabic only; English needs none). */
    private function notifiedPronoun(Collection $names, bool $female): string
    {
        return __('leaves.next-step.notified-'.match (true) {
            $names->count() > 1 => 'many',
            $female => 'female',
            default => 'male',
        });
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
