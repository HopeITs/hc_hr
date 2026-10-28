<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Reads the CURRENT state of the record a notification points at, so a
 * notification written when a request was pending does not keep claiming the
 * request still waits once somebody has decided it.
 *
 * "Unread" is only whether the user has looked at the message. The state here
 * is separate: awaiting (this user can act on it now), handled (the request has
 * moved on), unavailable (the record is gone or this user may not see it), or
 * null (purely informational). The record is only described to a user the
 * policy lets see it, so the text cannot widen what the notification showed.
 */
class NotificationRequestState
{
    public const AWAITING = 'awaiting';

    public const HANDLED = 'handled';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly LeaveRequestNextStep $nextStep) {}

    /**
     * @param  iterable<int, \Illuminate\Notifications\DatabaseNotification>  $notifications
     * @return array<string, array{state: string, status: ?string, next_step: ?string}> keyed by notification id
     */
    public function forMany(User $user, iterable $notifications): array
    {
        $notifications = collect($notifications);
        $ids = $notifications
            ->map(fn ($n) => $this->leaveSubject($n->data))
            ->filter()
            ->pluck('id')
            ->unique()
            ->values();

        $requests = $ids->isEmpty()
            ? collect()
            : LeaveRequest::query()->with($this->relations())->whereKey($ids)->get()->keyBy('id');

        return $notifications
            ->mapWithKeys(function ($notification) use ($user, $requests): array {
                $subject = $this->leaveSubject($notification->data);

                return $subject === null
                    ? []
                    : [$notification->id => $this->describe($user, $subject, $requests->get($subject['id']))];
            })
            ->all();
    }

    /**
     * The bell's number (G-C-12 D-07): unread notifications, less the unread
     * ones this class marks "Already handled", so the bell never counts a
     * request the list itself says needs nothing more. They stay unread.
     */
    public function unreadCount(User $user): int
    {
        $unread = $user->unreadNotifications()->count();

        if ($unread === 0) {
            return 0;
        }

        // Only a notice asking for a decision or a review can become handled.
        $actionable = $user->unreadNotifications()
            ->where(fn ($query) => $query
                ->where('data', 'like', '%"action":"decide"%')
                ->orWhere('data', 'like', '%"action":"review"%'))
            ->get();

        $handled = collect($this->forMany($user, $actionable))
            ->where('state', self::HANDLED)
            ->count();

        return max(0, $unread - $handled);
    }

    /** @return array{state: string, status: ?string, next_step: ?string} */
    public function forOne(User $user, array $data): ?array
    {
        $subject = $this->leaveSubject($data);

        if ($subject === null) {
            return null;
        }

        return $this->describe(
            $user,
            $subject,
            LeaveRequest::query()->with($this->relations())->find($subject['id']),
        );
    }

    /** @return array{id: int, action: ?string}|null */
    private function leaveSubject(mixed $data): ?array
    {
        $subject = is_array($data) ? ($data['subject'] ?? null) : null;

        if (! is_array($subject) || ($subject['type'] ?? null) !== 'leave_request' || ! is_numeric($subject['id'] ?? null)) {
            return null;
        }

        return ['id' => (int) $subject['id'], 'action' => $subject['action'] ?? null];
    }

    /** @return array{state: string, status: ?string, next_step: ?string} */
    private function describe(User $user, array $subject, ?LeaveRequest $request): array
    {
        if ($request === null || ! Gate::forUser($user)->allows('view', $request)) {
            return ['state' => self::UNAVAILABLE, 'status' => null, 'next_step' => null];
        }

        $actionable = match ($subject['action']) {
            'decide' => Gate::forUser($user)->allows('decide', $request),
            'review' => Gate::forUser($user)->allows('review', $request),
            default => null,
        };

        // Handled means the request moved past the step the notice asked for.
        // One this user may not act on that is still at that step (HR told of
        // a request its manager decides) only informs (G-C-12).
        $movedOn = match ($subject['action']) {
            'decide' => (int) $request->status !== LeaveRequestStatus::Pending,
            'review' => (int) $request->status === LeaveRequestStatus::Pending || (bool) $request->hr_approved,
            default => null,
        };

        $state = match (true) {
            $actionable === true => self::AWAITING,
            $movedOn === true => self::HANDLED,
            default => null,
        };

        return [
            'state' => $state ?? 'info',
            'status' => LeaveRequestStatus::getLocalizedName((int) $request->status)['value'],
            // Only a pending request has a next actor worth naming here.
            'next_step' => (int) $request->status === LeaveRequestStatus::Pending
                ? $this->nextStep->describe($request, $user)
                : null,
        ];
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'leaveBalance.contract.employee.account',
            'leaveBalance.contract.employee.city',
            'leaveBalance.contract.position.directManager.activeContracts.employee.account',
            'leaveBalance.contract.position.techManager.activeContracts.employee.account',
        ];
    }
}
