<?php

namespace App\Services\Recruitment\Privacy;

use App\Models\RecruitmentApplicant;
use Carbon\CarbonImmutable;

/**
 * How long an applicant may be kept, and when they must not be touched.
 *
 * The seam the organization's data-protection decision plugs into. The default
 * (ConfiguredRetentionPolicy) reads periods from config/recruitment-privacy.php
 * and is a placeholder until those are confirmed; replacing it means binding
 * another implementation in AppServiceProvider, nothing else.
 *
 * Both answers are questions about the applicant as they are right now. The
 * anonymizer asks blocker() again under a row lock, so a stale retention_until
 * never anonymizes someone who has applied again since.
 */
interface RetentionPolicy
{
    /** An application is still moving through the process. */
    public const BLOCKED_ACTIVE_APPLICATION = 'active_application';

    /** The applicant was hired: their data now lives on as an employee record. */
    public const BLOCKED_HIRED = 'hired';

    /**
     * When the applicant falls due, or null while no clock is running — a live
     * application, a hire, a record already anonymized.
     */
    public function dueAt(RecruitmentApplicant $applicant): ?CarbonImmutable;

    /** Why the applicant must not be anonymized now, or null when nothing stands in the way. */
    public function blocker(RecruitmentApplicant $applicant): ?string;
}
