<?php

namespace App\Observers;

use App\Enums\RecruitmentApplicationStatus as ApplicationStatus;
use App\Models\RecruitmentApplication;
use App\Services\ActivityLogger;
use App\Services\RecruitmentInterviewSchedulingService;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 2 Task 2's "Create audit/submission log" step, for every path that
 * creates an application — Team 1's PublicRecruitmentApplicationService (the
 * real /careers form) included, without modifying it.
 *
 * Runs inside the submitting transaction, so a submission that rolls back
 * takes its audit row with it: the log never records an application that
 * does not exist.
 *
 * Identifiers only — no name, email or answers: the activity log is read by
 * administrators outside recruitment scope, and applicant PII stays behind
 * the applicant policies.
 *
 * D8: an audit write that fails is reported and must not roll back the
 * submission it describes, hence the savepoint and the catch.
 */
class RecruitmentApplicationObserver
{
    /** Statuses in which an application can hold a live job offer. */
    private const OFFER_STAGE = [
        ApplicationStatus::Selected, ApplicationStatus::OfferSent,
        ApplicationStatus::OfferAccepted, ApplicationStatus::OfferRejected, ApplicationStatus::Hired,
    ];

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function created(RecruitmentApplication $application): void
    {
        try {
            DB::transaction(fn () => $this->activityLogger->log('recruitment_application_submitted', $application, null, [
                'campaign_id' => $application->campaign_id,
                'public_link_id' => $application->public_link_id,
                'form_version_id' => $application->form_version_id,
                'campaign_invite_id' => $application->campaign_invite_id,
            ], 'recruitment'));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Phase 2 Task 7: an application that is rejected, withdrawn or archived
     * while it still has open interviews takes them down with it — they are
     * cancelled (never deleted), so no reminder goes out and nobody turns up
     * to interview a candidate who is no longer in the process.
     *
     * A status only changes inside RecruitmentApplicationStatusService's
     * transaction, on a locked row, so this runs there too: the decision and
     * the cancellations commit or roll back together. Unlike the audit entry
     * above, a failure here is not swallowed — a rejection that left a live
     * interview behind would be worse than one that did not save.
     */
    public function updated(RecruitmentApplication $application): void
    {
        if (! $application->wasChanged('status')) {
            return;
        }

        if (in_array($application->status, RecruitmentInterviewSchedulingService::CLOSING, true)) {
            app(RecruitmentInterviewSchedulingService::class)->closeForApplication($application);
        }

        // Phase 2 Task 9: "selected, then rejected before the offer was
        // sent" — a draft offer does not survive its application leaving the
        // offer stage (rejected, sent back to review, withdrawn, archived).
        if (! in_array($application->status, self::OFFER_STAGE, true)) {
            app(RecruitmentJobOfferService::class)->closeForApplication($application);
        }
    }
}
