<?php

namespace App\Services;

use App\Models\RecruitmentApplicant;

/**
 * Flags applicants who share a phone number with an earlier applicant under
 * a different email — Phase 2 Task 1's "email مختلف لكن phone نفسه" case.
 *
 * Flag, never merge: the plan forbids automatic merging, and a shared phone
 * is weaker evidence than a shared email (families and offices share lines).
 * HR sees the hint on the applicant and decides.
 *
 * Runs from RecruitmentApplicantObserver rather than from a submission
 * service, so it covers every path that saves an applicant — Team 1's
 * PublicRecruitmentApplicationService and Team 2's test path alike.
 */
class RecruitmentDuplicateApplicantDetector
{
    /**
     * The last nine digits of the number, or null when there are fewer.
     *
     * Syrian mobile numbers are nine digits after the trunk "0" or the "+963"
     * / "00963" prefix, so the tail is what stays stable across the ways the
     * same number gets typed.
     *
     * // TODO (Business Rule): this is Syria-centric. An international number
     * // can in rare cases share its last nine digits with another. The cost
     * // of that collision is a false "possible duplicate" hint, never a
     * // merge — revisit if applicants from abroad become common.
     */
    public function phoneMatchKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', en_digits((string) $phone)) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    /**
     * Settle the phone group this applicant belongs to.
     *
     * The earliest applicant with the key is the group's anchor and carries
     * no flag; every other member points at it. Working on the whole group
     * rather than on one row is what keeps the hint acyclic — pointing each
     * row at "the earliest other row" made the anchor point at the second
     * member while the second pointed back at the anchor.
     *
     * Existing flags on non-anchor members are left alone: the first match is
     * the useful one, and re-pointing on every save would make the hint
     * drift. Query-builder updates fire no model events, so the observer that
     * called this does not run again.
     */
    public function flag(RecruitmentApplicant $applicant): void
    {
        $key = $applicant->phone_match_key;

        if ($key === null) {
            return;
        }

        // Materialized, not a subquery: MySQL rejects an UPDATE whose WHERE
        // reads the table being updated (error 1093).
        $ids = RecruitmentApplicant::query()->where('phone_match_key', $key)->orderBy('id')->pluck('id');
        $anchor = $ids->first();

        if ($anchor === null) {
            return;
        }

        // The anchor must never point into its own group.
        RecruitmentApplicant::query()->whereKey($anchor)
            ->whereIn('possible_duplicate_of_applicant_id', $ids)
            ->update(['possible_duplicate_of_applicant_id' => null]);

        RecruitmentApplicant::query()->whereIn('id', $ids)->whereKeyNot($anchor)
            ->whereNull('possible_duplicate_of_applicant_id')
            ->update(['possible_duplicate_of_applicant_id' => $anchor]);

        // Keep the in-memory model in step with what was just written.
        $applicant->setRawAttributes(array_merge($applicant->getAttributes(), [
            'possible_duplicate_of_applicant_id' => RecruitmentApplicant::query()->whereKey($applicant->getKey())
                ->value('possible_duplicate_of_applicant_id'),
        ]), true);
    }
}
