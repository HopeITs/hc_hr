<?php

namespace App\Services;

use App\Models\City;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ActionLeaveRequest;
use App\Notifications\HRSawLeaveRequest;
use App\Notifications\NewLeaveRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class LeaveNotificationService
{
    public function sendNewRequest(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $audience = $this->newRequestAudience($leaveRequest, $actor);

        $recipients = $audience['managers']
            ->merge($audience['hr'])
            ->pipe(fn (Collection $users) => $this->eligibleUniqueUsers($users));

        Notification::send($recipients, new NewLeaveRequest($this->loadContext($leaveRequest)));
    }

    /**
     * Who is told about a new request, split by group. sendNewRequest() sends
     * to exactly these accounts, and LeaveRequestNextStep reads the same lists,
     * so the "who acts next" text on screen can never drift from the people
     * actually notified.
     *
     * @return array{managers: Collection<int, User>, hr: Collection<int, User>}
     */
    public function newRequestAudience(LeaveRequest $leaveRequest, ?User $actor = null): array
    {
        $leaveRequest = $this->loadContext($leaveRequest);

        return [
            'managers' => $this->eligibleUniqueUsers($this->managerRecipients($leaveRequest), [$actor?->id]),
            'hr' => $this->eligibleUniqueUsers($this->hrRecipients($leaveRequest), [$actor?->id]),
        ];
    }

    public function sendDecision(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $leaveRequest = $this->loadContext($leaveRequest);
        $employee = $leaveRequest->leaveBalance->contract->employee->account;
        $excludedIds = [$actor?->id];

        $employeeRecipients = $this->eligibleUniqueUsers(
            collect([$employee]),
            $excludedIds,
        );

        Notification::send(
            $employeeRecipients,
            new ActionLeaveRequest($leaveRequest),
        );

        $hrRecipients = $this->eligibleUniqueUsers(
            $this->hrRecipients($leaveRequest),
            [...$excludedIds, ...$employeeRecipients->pluck('id')->all()],
        );

        Notification::send(
            $hrRecipients,
            new ActionLeaveRequest($leaveRequest, 'hr'),
        );
    }

    public function sendHrReview(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $leaveRequest = $this->loadContext($leaveRequest);
        $employee = $leaveRequest->leaveBalance->contract->employee->account;
        $excludedIds = [$actor?->id];

        $employeeRecipients = $this->eligibleUniqueUsers(
            collect([$employee]),
            $excludedIds,
        );

        Notification::send(
            $employeeRecipients,
            new HRSawLeaveRequest($leaveRequest, 'employee'),
        );

        $managerRecipients = $this->eligibleUniqueUsers(
            $this->managerRecipients($leaveRequest),
            [...$excludedIds, ...$employeeRecipients->pluck('id')->all()],
        );

        Notification::send(
            $managerRecipients,
            new HRSawLeaveRequest($leaveRequest, 'manager'),
        );
    }

    private function loadContext(LeaveRequest $leaveRequest): LeaveRequest
    {
        return $leaveRequest->loadMissing([
            'leaveBalance.leaveType',
            'leaveBalance.contract.employee.account',
            'leaveBalance.contract.employee.city',
            'leaveBalance.contract.position.directManager.activeContracts.employee.account',
            'leaveBalance.contract.position.techManager.activeContracts.employee.account',
        ]);
    }

    /**
     * Every active employee holding either manager position is a recipient.
     * A manager position may legitimately have multiple active contracts.
     *
     * @return Collection<int, User>
     */
    private function managerRecipients(LeaveRequest $leaveRequest): Collection
    {
        $position = $leaveRequest->leaveBalance->contract->position;

        return collect([$position->directManager, $position->techManager])
            ->filter()
            ->flatMap(fn ($managerPosition) => $managerPosition->activeContracts)
            ->map(fn ($contract) => $contract->employee)
            ->filter(fn ($employee) => $employee?->is_active && $employee->account)
            ->map(fn ($employee) => $employee->account)
            ->values();
    }

    /**
     * Notify every active HR account in the employee's city, not an arbitrary
     * first match. Role activity controls the current UI context, not whether an
     * assigned HR account should receive an actionable notification.
     *
     * @return Collection<int, User>
     */
    private function hrRecipients(LeaveRequest $leaveRequest): Collection
    {
        $citySlug = $leaveRequest->leaveBalance->contract->employee->city?->slug;

        if (! $citySlug) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', City::hrRolesForSlug($citySlug)))
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->get();
    }

    /**
     * @param  Collection<int, User|null>  $users
     * @param  array<int, int|null>  $excludedIds
     * @return Collection<int, User>
     */
    private function eligibleUniqueUsers(Collection $users, array $excludedIds = []): Collection
    {
        $excludedIds = array_filter($excludedIds, fn ($id) => $id !== null);

        return $users
            ->filter(fn ($user) => $user instanceof User)
            ->reject(fn (User $user) => in_array($user->id, $excludedIds, true))
            ->unique('id')
            ->values();
    }
}
