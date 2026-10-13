<?php

namespace App\Policies;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJobOffer;
use App\Models\RecruitmentJobOfferVersion;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 2 Task 9.
 *
 * - view: view-offers, on an application in the user's recruitment scope;
 *   or the manager who requested the position (PositionRequester), read-only.
 *   Status, dates and contract terms.
 * - viewSalary: the pay figure on top of that, only for users who may see
 *   salaries at all (User::canViewSalary() — active HR role, and never
 *   super-admin, which is salary-blind by design). One exception, granted
 *   on purpose: the position's requester holding view-offer-salary sees the
 *   figure of each version once it was sent to the candidate — never a
 *   draft HR is still working on.
 * - create / manage: manage-offers, in scope, and canViewSalary(): writing an
 *   offer means entering the salary, so a user who may not see figures
 *   cannot write one either.
 */
class RecruitmentJobOfferPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    /** HR who read offers, and any manager who requested a position with offers (read-only). */
    public function viewAny(User $user): bool
    {
        return $this->readsOffers($user)
            || RecruitmentJobOffer::query()->requestedBy($user)->exists();
    }

    public function view(User $user, RecruitmentJobOffer $offer): bool
    {
        return ($this->readsOffers($user) && $this->inScope($user, $offer->application()->first()))
            || $offer->isRequestedBy($user);
    }

    /**
     * The link from the offer to the applicant page: whoever may read the
     * application (unchanged), and the position's requester. The applicant
     * page keeps its own permission; this only decides whether the link shows.
     */
    public function viewApplicant(User $user, RecruitmentJobOffer $offer): bool
    {
        $application = $offer->application()->first();

        return ($application !== null && Gate::forUser($user)->allows('view', $application))
            || $offer->isRequestedBy($user);
    }

    /** Of $version when given, otherwise of the offer's current version. */
    public function viewSalary(User $user, RecruitmentJobOffer $offer, ?RecruitmentJobOfferVersion $version = null): bool
    {
        if ($user->canViewSalary() && $this->view($user, $offer)) {
            return true;
        }

        $version ??= $offer->currentVersion;

        return $version?->sent_at !== null
            && $user->hasActivePermission('recruitment-applicants.view-offer-salary')
            && ! $user->activeRoleIs(...config('salary.blind_roles', []))
            && $offer->isRequestedBy($user);
    }

    /** Creating the offer for this application. */
    public function create(User $user, RecruitmentApplication $application): bool
    {
        return $this->canWrite($user) && $this->inScope($user, $application);
    }

    /** Edit a draft, send, revise, record the answer, cancel. */
    public function manage(User $user, RecruitmentJobOffer $offer): bool
    {
        return $this->canWrite($user) && $this->inScope($user, $offer->application()->first());
    }

    private function readsOffers(User $user): bool
    {
        return $user->hasActivePermission('recruitment-applicants.view-offers')
            && $user->hasActivePermission('recruitment-applicants.view');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasActivePermission('recruitment-applicants.manage-offers')
            && $this->readsOffers($user)
            && $user->canViewSalary();
    }

    private function inScope(User $user, ?RecruitmentApplication $application): bool
    {
        return $application !== null && $this->scopes->canAccessApplication($user, $application);
    }
}
