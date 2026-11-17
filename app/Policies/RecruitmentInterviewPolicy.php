<?php

namespace App\Policies;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\RecruitmentInterviewSchedulingService;
use App\Services\RecruitmentScopeResolver;

/**
 * Phase 2 Task 7.
 *
 * - Scheduling, editing, rescheduling, cancelling and recording an outcome:
 *   HR holding recruitment-applicants.schedule-interviews, on an application
 *   inside their recruitment scope. Nobody else, participants included.
 * - Seeing an interview: whoever is currently on it, the manager who
 *   requested its position (RecruitmentInterview::scopeRequestedBy()), or
 *   anyone who can read applicants and has the application in scope. These
 *   add up: a requester who is also on the panel has both sets of rights.
 * - The meeting link: whoever may manage the interview, is currently on it,
 *   or requested its position. An HR reader who is none of these sees that
 *   the interview is online, not how to join it.
 * - Every evaluation and the summary: HR in scope, and the requester,
 *   read-only — by ownership, not by a permission on the manager role.
 */
class RecruitmentInterviewPolicy
{
    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    /** The interviews page itself; its query narrows to what each user may see. */
    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('recruitment-applicants.view')
            || RecruitmentInterview::hasAnyFor($user)
            || RecruitmentInterview::query()->requestedBy($user)->exists();
    }

    public function view(User $user, RecruitmentInterview $interview): bool
    {
        if ($interview->isParticipant($user) || $interview->isRequestedBy($user)) {
            return true;
        }

        return $user->hasActivePermission('recruitment-applicants.view')
            && $this->inScope($user, $interview);
    }

    /** Scheduling a new interview for this application. */
    public function schedule(User $user, RecruitmentApplication $application): bool
    {
        return $user->hasActivePermission('recruitment-applicants.schedule-interviews')
            && $user->hasActivePermission('recruitment-applicants.view')
            && $this->scopes->canAccessApplication($user, $application);
    }

    /** Edit, reschedule, cancel, record the outcome. */
    public function manage(User $user, RecruitmentInterview $interview): bool
    {
        return $user->hasActivePermission('recruitment-applicants.schedule-interviews')
            && $user->hasActivePermission('recruitment-applicants.view')
            && $this->inScope($user, $interview);
    }

    public function viewMeetingLink(User $user, RecruitmentInterview $interview): bool
    {
        return $this->manage($user, $interview)
            || $interview->isParticipant($user)
            || $interview->isRequestedBy($user);
    }

    /**
     * The link from the interview to the applicant page: HR who can read the
     * application, and the requester. The applicant page keeps its own
     * permission; this only decides whether the button is shown.
     */
    public function viewApplicant(User $user, RecruitmentInterview $interview): bool
    {
        return ($user->hasActivePermission('recruitment-applicants.view') && $this->inScope($user, $interview))
            || $interview->isRequestedBy($user);
    }

    /**
     * Task 8: may this user write their own evaluation of this interview?
     * Only as a current participant, in a reviewer role, on an active
     * account. Whether evaluation is open right now (start time, cancelled,
     * no-show, closed) is the service's call, so it can say why not.
     */
    public function evaluate(User $user, RecruitmentInterview $interview): bool
    {
        return RecruitmentInterviewSchedulingService::isActiveUser($user)
            && $interview->currentParticipants()
                ->where('user_id', $user->getKey())
                ->whereIn('role', (array) config('recruitment.evaluations.reviewer_roles', []))
                ->exists();
    }

    /**
     * Task 8: the aggregated view of every reviewer's evaluation — HR in
     * scope, and the requester of the position, read-only (correcting,
     * withdrawing and closing keep their own HR checks).
     */
    public function viewEvaluations(User $user, RecruitmentInterview $interview): bool
    {
        return ($user->hasActivePermission('recruitment-applicants.view-evaluations')
            && $user->hasActivePermission('recruitment-applicants.view')
            && $this->inScope($user, $interview))
            || $interview->isRequestedBy($user);
    }

    /** Task 8: closing evaluation after completion is part of managing the interview. */
    public function closeEvaluations(User $user, RecruitmentInterview $interview): bool
    {
        return $this->manage($user, $interview);
    }

    private function inScope(User $user, RecruitmentInterview $interview): bool
    {
        $application = $interview->relationLoaded('application')
            ? $interview->application
            : $interview->application()->first();

        return $application !== null && $this->scopes->canAccessApplication($user, $application);
    }
}
