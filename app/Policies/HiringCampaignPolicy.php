<?php

namespace App\Policies;

use App\Models\HiringCampaign;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentScopeResolver;

class HiringCampaignPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-campaigns.view');
    }

    public function view(User $user, HiringCampaign $campaign): bool
    {
        return $user->hasActivePermission('recruitment-campaigns.view') && $this->inScope($user, $campaign);
    }

    public function create(User $user, Vacancy $vacancy): bool
    {
        $request = $vacancy->createdFromRequest()->first();

        return $request !== null
            && $user->hasActivePermission('recruitment-campaigns.manage')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function manage(User $user, HiringCampaign $campaign): bool
    {
        return $user->hasActivePermission('recruitment-campaigns.manage') && $this->inScope($user, $campaign);
    }

    public function update(User $user, HiringCampaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }

    public function delete(User $user, HiringCampaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }

    public function publish(User $user, HiringCampaign $campaign): bool
    {
        return $user->hasActivePermission('recruitment-campaigns.manage') && $this->inScope($user, $campaign);
    }

    public function close(User $user, HiringCampaign $campaign): bool
    {
        return $user->hasActivePermission('recruitment-campaigns.manage') && $this->inScope($user, $campaign);
    }

    private function inScope(User $user, HiringCampaign $campaign): bool
    {
        $request = $campaign->vacancy()->with('createdFromRequest')->first()?->createdFromRequest;

        return $request !== null && $this->scopes->canAccessRequest($user, $request);
    }
}
