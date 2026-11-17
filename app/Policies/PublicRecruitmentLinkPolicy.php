<?php

namespace App\Policies;

use App\Models\HiringCampaign;
use App\Models\PublicRecruitmentLink;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;

class PublicRecruitmentLinkPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-links.view')
            || $user->hasActivePermission('recruitment-links.manage');
    }

    public function manage(User $user, PublicRecruitmentLink $link): bool
    {
        if (! $user->hasActivePermission('recruitment-links.manage')) {
            return false;
        }

        $request = $link->campaign()->with('vacancy.createdFromRequest')->first()?->vacancy?->createdFromRequest;

        return $request !== null && $this->scopes->canAccessRequest($user, $request);
    }

    public function create(User $user, HiringCampaign $campaign): bool
    {
        $request = $campaign->vacancy()->with('createdFromRequest')->first()?->createdFromRequest;

        return $request !== null
            && $user->hasActivePermission('recruitment-links.manage')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function update(User $user, PublicRecruitmentLink $link): bool
    {
        return $this->manage($user, $link);
    }

    public function delete(User $user, PublicRecruitmentLink $link): bool
    {
        return $this->manage($user, $link);
    }
}
