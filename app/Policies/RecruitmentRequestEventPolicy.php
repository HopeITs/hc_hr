<?php

namespace App\Policies;

use App\Models\RecruitmentRequestEvent;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;

class RecruitmentRequestEventPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-requests.view');
    }

    public function view(User $user, RecruitmentRequestEvent $event): bool
    {
        $request = $event->request()->first();

        return $request !== null
            && $user->hasActivePermission('recruitment-requests.view')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function export(User $user, RecruitmentRequestEvent $event): bool
    {
        $request = $event->request()->first();

        return $request !== null
            && $user->hasActivePermission('recruitment-requests.export')
            && $this->scopes->canAccessRequest($user, $request);
    }
}
