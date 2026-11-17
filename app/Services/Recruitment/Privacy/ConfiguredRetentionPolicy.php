<?php

namespace App\Services\Recruitment\Privacy;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Models\RecruitmentApplicant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The placeholder policy: two configurable periods and two blockers.
 *
 *   - An applicant with no application is kept `no_application_days` after
 *     they were last seen.
 *   - Otherwise, once every application is closed, they are kept
 *     `closed_application_days` after the last one closed (its last decision,
 *     or its submission if it was never decided).
 *   - Anyone with an open application, or who was hired, is never due.
 *
 * The periods are not a legal position; see config/recruitment-privacy.php.
 */
final class ConfiguredRetentionPolicy implements RetentionPolicy
{
    /** Statuses after which nothing more happens to an application. */
    private const CLOSED = [Status::Rejected, Status::OfferRejected, Status::Withdrawn, Status::Archived];

    public function blocker(RecruitmentApplicant $applicant): ?string
    {
        $applications = DB::table('recruitment_applications')->where('applicant_id', $applicant->getKey());

        if ((clone $applications)->where('status', Status::Hired)->exists()
            || DB::table('recruitment_hiring_conversions')->where('applicant_id', $applicant->getKey())->exists()) {
            return self::BLOCKED_HIRED;
        }

        if ($applications->whereNotIn('status', [...self::CLOSED, Status::Hired])->exists()) {
            return self::BLOCKED_ACTIVE_APPLICATION;
        }

        return null;
    }

    public function dueAt(RecruitmentApplicant $applicant): ?CarbonImmutable
    {
        if ($applicant->anonymized_at !== null || $this->blocker($applicant) !== null) {
            return null;
        }

        $closings = DB::table('recruitment_applications as application')
            ->leftJoin('recruitment_application_decisions as decision', 'decision.application_id', '=', 'application.id')
            ->where('application.applicant_id', $applicant->getKey())
            ->groupBy('application.id', 'application.submitted_at')
            ->selectRaw('application.submitted_at as submitted_at, MAX(decision.decided_at) as decided_at')
            ->get();

        if ($closings->isEmpty()) {
            return CarbonImmutable::instance($applicant->last_seen_at ?? $applicant->created_at ?? now())
                ->addDays((int) config('recruitment-privacy.retention.no_application_days'));
        }

        return $closings
            ->map(fn (object $row): CarbonImmutable => CarbonImmutable::parse($row->decided_at ?? $row->submitted_at))
            ->max()
            ->addDays((int) config('recruitment-privacy.retention.closed_application_days'));
    }
}
