<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentScopeResolver;

class VacancyPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-vacancies.view');
    }

    public function view(User $user, Vacancy $vacancy): bool
    {
        return $user->hasActivePermission('recruitment-vacancies.view') && $this->inScope($user, $vacancy);
    }

    public function create(User $user, \App\Models\RecruitmentRequest $request): bool
    {
        return $user->hasActivePermission('recruitment-vacancies.manage')
            && $this->scopes->canAccessRequest($user, $request);
    }

    public function manage(User $user, Vacancy $vacancy): bool
    {
        return $user->hasActivePermission('recruitment-vacancies.manage') && $this->inScope($user, $vacancy);
    }

    public function update(User $user, Vacancy $vacancy): bool
    {
        return $this->manage($user, $vacancy);
    }

    public function delete(User $user, Vacancy $vacancy): bool
    {
        return $this->manage($user, $vacancy);
    }

    public function close(User $user, Vacancy $vacancy): bool
    {
        return $user->hasActivePermission('recruitment-vacancies.manage') && $this->inScope($user, $vacancy);
    }

    private function inScope(User $user, Vacancy $vacancy): bool
    {
        $request = $vacancy->createdFromRequest()->first();

        return $request !== null && $this->scopes->canAccessRequest($user, $request);
    }
}
