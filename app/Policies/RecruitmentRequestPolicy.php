<?php

namespace App\Policies;

use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;

class RecruitmentRequestPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-requests.view');
    }

    public function view(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.view')
            && $this->scopes->canAccessRequest($user, $request);
    }

    /** Page entry only. Persistence also checks the target/parent (D49/D50). */
    public function create(User $user): bool
    {
        return $user->hasActivePermission('recruitment-requests.manage')
            && $this->scopes->canRaiseNormalRequest($user);
    }

    public function createForPosition(User $user, Position $position): bool
    {
        return $this->create($user)
            && $this->scopes->scopeRequestablePositions(Position::query(), $user)
                ->whereKey($position->getKey())->exists();
    }

    public function createUnderPosition(User $user, Position $parent): bool
    {
        return $this->create($user)
            && $this->scopes->scopeProposedManagers(Position::query(), $user)
                ->whereKey($parent->getKey())->exists();
    }

    public function edit(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.manage')
            && $this->scopes->canAccessRequest($user, $request)
            && ($request->mode !== RecruitmentRequestMode::Normal || $this->ownsEditableNormalRequest($user, $request));
    }

    public function update(User $user, RecruitmentRequest $request): bool
    {
        return $this->edit($user, $request);
    }

    public function submit(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.submit')
            && $this->scopes->canAccessRequest($user, $request)
            && (int) $request->mode === RecruitmentRequestMode::Normal
            && $this->ownsEditableNormalRequest($user, $request);
    }

    public function review(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.approve')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function approve(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.approve')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function reject(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.reject')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function requestChanges(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.request-changes')
            && $this->scopes->canAccessRequest($user, $request);
    }

    /**
     * Carrying out an approved request before the date it was given.
     *
     * Not an approval — the decision is already made — but an administrative
     * act on it, so it sits with the permission that runs the module rather
     * than with the one that decides. Bringing a closure forward is HR's call,
     * never the approver's.
     */
    public function execute(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.manage')
            && $this->scopes->canAccessRequest($user, $request);
    }

    /**
     * Withdrawing is the requester's own act, and only before the final
     * approval (T12 · T14 · T15) — an approved request gets applied, not
     * withdrawn, and a rejected one is already closed.
     */
    public function cancel(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.cancel')
            && $this->scopes->canAccessRequest($user, $request)
            && (int) $request->requester_user_id === (int) $user->getKey()
            && in_array((int) $request->status, [
                RecruitmentRequestStatus::Draft,
                RecruitmentRequestStatus::ChangesRequested,
                RecruitmentRequestStatus::UnderReview,
            ], true);
    }

    /**
     * Opening a vacancy directly, for cases arranged outside the system (D15).
     */
    public function directOpen(User $user): bool
    {
        return $user->activeRoleIs('HR')
            && $user->hasActivePermission('recruitment-requests.direct-open');
    }

    /**
     * Exporting respects the user's scope exactly and never widens it (D37):
     * each role exports what it is allowed to see, nothing more.
     */
    public function export(User $user, RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-requests.export')
            && $this->scopes->canAccessRequest($user, $request);
    }

    private function ownsEditableNormalRequest(User $user, RecruitmentRequest $request): bool
    {
        return $this->scopes->canRaiseNormalRequest($user)
            && (int) $request->requester_user_id === (int) $user->getKey()
            && in_array($request->status, [RecruitmentRequestStatus::Draft, RecruitmentRequestStatus::ChangesRequested], true);
    }
}
