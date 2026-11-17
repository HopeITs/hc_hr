<?php

namespace App\Policies;

use App\Models\HiringCampaign;
use App\Models\RecruitmentCampaignInvite;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;

/**
 * Invite management sits under 'recruitment-applicants' rather than
 * 'recruitment-campaigns': it is who-may-apply data about people, not
 * campaign configuration, and Phase 2's privacy call (HR-only, no manager
 * baseline) is meant to cover it the same way it covers the applicant and
 * application records themselves.
 */
class RecruitmentCampaignInvitePolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-applicants.view');
    }

    /** Adding to, or listing, a specific campaign's invite list. */
    public function manageForCampaign(User $user, HiringCampaign $campaign): bool
    {
        return $user->hasActivePermission('recruitment-applicants.manage') && $this->inScope($user, $campaign);
    }

    public function cancel(User $user, RecruitmentCampaignInvite $invite): bool
    {
        return $user->hasActivePermission('recruitment-applicants.manage')
            && $this->inScope($user, $invite->campaign);
    }

    private function inScope(User $user, HiringCampaign $campaign): bool
    {
        $request = $campaign->vacancy()->with('createdFromRequest')->first()?->createdFromRequest;

        return $request !== null && $this->scopes->canAccessRequest($user, $request);
    }
}
