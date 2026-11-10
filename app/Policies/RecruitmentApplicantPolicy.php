<?php

namespace App\Policies;

use App\Models\RecruitmentApplicant;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Auth\Access\Response;

class RecruitmentApplicantPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-applicants.view');
    }

    public function view(User $user, RecruitmentApplicant $applicant): bool
    {
        return $this->viewAny($user) && $this->scopes->canAccessApplicant($user, $applicant);
    }

    /**
     * Anonymizing an applicant on their request.
     *
     * Asked without an applicant it answers only "may they do this at all", so
     * a caller without the permission is refused before the existence of an id
     * is confirmed or denied. With one it also needs the whole of the
     * applicant in scope: erasing wipes every application, and a governorate
     * HR must not erase what another governorate's campaign still holds.
     *
     * Like export, the permission is read with hasExplicitPermission().
     */
    public function requestErasure(User $user, ?RecruitmentApplicant $applicant = null): Response
    {
        if (! $user->hasExplicitPermission('recruitment-applicants.request-erasure')
            || ! $this->viewAny($user)) {
            return Response::deny(code: 'missing_permission');
        }

        if ($applicant === null) {
            return Response::allow();
        }

        return $this->scopes->coversWholeApplicant($user, $applicant)
            ? Response::allow()
            : Response::deny(code: 'out_of_scope');
    }
}
