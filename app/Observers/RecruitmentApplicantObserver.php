<?php

namespace App\Observers;

use App\Models\RecruitmentApplicant;
use App\Services\RecruitmentDuplicateApplicantDetector;

/**
 * Keeps the phone comparison key in step with phone_normalized and raises the
 * "possible duplicate" hint, whichever code path saved the applicant.
 *
 * Uses saving/saved rather than creating/created on purpose: Team 1's public
 * submission service creates the row with insertOrIgnore() (no model events)
 * and then forceFill()->save()s it, so only the save events are guaranteed to
 * fire for every applicant.
 */
class RecruitmentApplicantObserver
{
    public function __construct(private readonly RecruitmentDuplicateApplicantDetector $detector) {}

    public function saving(RecruitmentApplicant $applicant): void
    {
        $applicant->phone_match_key = $this->detector->phoneMatchKey($applicant->phone_normalized);
    }

    public function saved(RecruitmentApplicant $applicant): void
    {
        if ($applicant->wasRecentlyCreated || $applicant->wasChanged('phone_match_key')) {
            $this->detector->flag($applicant);
        }
    }
}
