<?php

namespace App\Policies;

use App\Models\ApplicationForm;
use App\Models\HiringCampaign;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;

class ApplicationFormPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-forms.view');
    }

    public function view(User $user, ApplicationForm $form): bool
    {
        return $user->hasActivePermission('recruitment-forms.view') && $this->inScope($user, $form);
    }

    public function create(User $user, HiringCampaign $campaign): bool
    {
        $request = $campaign->vacancy()->with('createdFromRequest')->first()?->createdFromRequest;

        return $request !== null
            && $user->hasActivePermission('recruitment-forms.manage')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function manage(User $user, ApplicationForm $form): bool
    {
        return $user->hasActivePermission('recruitment-forms.manage') && $this->inScope($user, $form);
    }

    public function update(User $user, ApplicationForm $form): bool
    {
        return $this->manage($user, $form);
    }

    public function delete(User $user, ApplicationForm $form): bool
    {
        return $this->manage($user, $form);
    }

    public function publish(User $user, ApplicationForm $form): bool
    {
        return $user->hasActivePermission('recruitment-forms.manage') && $this->inScope($user, $form);
    }

    private function inScope(User $user, ApplicationForm $form): bool
    {
        $request = $form->campaign()->with('vacancy.createdFromRequest')->first()?->vacancy?->createdFromRequest;

        return $request !== null && $this->scopes->canAccessRequest($user, $request);
    }
}
