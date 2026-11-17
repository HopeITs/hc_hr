<?php

namespace App\Policies;

use App\Models\RecruitmentInterviewEvaluation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 2 Task 8, per evaluation.
 *
 * - view: the reviewer sees their own; HR with view-evaluations sees every
 *   evaluation of an interview in their scope. Nobody else sees the content —
 *   not other reviewers on the same interview, not the candidate.
 * - update: only the reviewer who wrote it, while still on the interview
 *   (the service also checks evaluation is still open).
 * - addNote: HR may record a separate opinion, but never edit or withdraw a
 *   participant's evaluation.
 */
class RecruitmentInterviewEvaluationPolicy
{
    public function view(User $user, RecruitmentInterviewEvaluation $evaluation): bool
    {
        return (int) $evaluation->reviewer_user_id === (int) $user->getKey()
            || Gate::forUser($user)->allows('viewEvaluations', $this->interview($evaluation));
    }

    public function update(User $user, RecruitmentInterviewEvaluation $evaluation): bool
    {
        return (int) $evaluation->reviewer_user_id === (int) $user->getKey()
            && Gate::forUser($user)->allows('evaluate', $this->interview($evaluation));
    }

    public function correct(User $user, RecruitmentInterviewEvaluation $evaluation): bool
    {
        return false;
    }

    public function withdraw(User $user, RecruitmentInterviewEvaluation $evaluation): bool
    {
        return false;
    }

    public function addNote(User $user, RecruitmentInterviewEvaluation $evaluation): bool
    {
        return $user->hasActivePermission('recruitment-applicants.add-notes')
            && $user->hasActivePermission('recruitment-applicants.view-evaluations')
            && Gate::forUser($user)->allows('viewEvaluations', $this->interview($evaluation));
    }

    private function interview(RecruitmentInterviewEvaluation $evaluation)
    {
        return $evaluation->relationLoaded('interview') ? $evaluation->interview : $evaluation->interview()->first();
    }
}
