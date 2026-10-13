<?php

namespace App\Services\Recruitment\Privacy;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Strips the personal data from an applicant in place.
 *
 * Nothing is deleted except what is derived from a document or is the document
 * itself (the stored file and its CV reading). Every row that another table
 * points at stays, so application history, decisions, interviews and offers
 * keep their foreign keys and the aggregate reports keep their counts. Only
 * the columns that identify or describe the person change.
 *
 * Stripped
 *   applicant      name, email, phone, identity hash, employee link, duplicate hint
 *   application    email, IP address, user agent, submission key
 *   answers        every answer
 *   files          the stored bytes and the CV reading derived from them
 *   notes          the text, including the reason a note was deleted
 *   decisions      the free-text reason
 *   notices        the recipient address and failure text of rejection and interview emails
 *   invites        name and email, for every campaign the address was invited to
 *   interviews     cancellation and outcome notes, reschedule reasons,
 *                  evaluation opinions and their revisions
 *   offers         response, cancellation and revision notes
 *   indicators     the per-indicator detail (the counts stay)
 *
 * Kept, deliberately
 *   status, decision codes and dates, campaign / vacancy / position / city,
 *   tags, indicator counts, interview times and recommendations, offer amounts,
 *   file type and scan status, and the consent evidence (notice version and
 *   moment): none of it says who the person was, and consent evidence must
 *   outlive the person's data to show the data was lawfully held.
 *
 * Not covered: job-offer terms_json (free-form, written per offer) and
 * interview location / meeting URLs. Review both when the retention policy is
 * confirmed.
 *
 * Writes go through the query builder, not the models: notes and application
 * status refuse edits on purpose, and no model event should fire for a
 * redaction.
 */
class RecruitmentAnonymizer
{
    public const REASON_RETENTION = 'retention_expired';

    public const REASON_ERASURE = 'erasure_request';

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly RecruitmentPrivacyAuditor $audit,
    ) {}

    /**
     * @return string|null the blocker that stopped it (see RetentionPolicy), or
     *                     null when the applicant is now anonymized — or already was
     */
    public function anonymize(RecruitmentApplicant|int $applicant, string $reason, ?User $actor = null): ?string
    {
        $id = $applicant instanceof RecruitmentApplicant ? (int) $applicant->getKey() : $applicant;
        $blocker = null;

        DB::transaction(function () use ($id, $reason, $actor, &$blocker): void {
            $locked = RecruitmentApplicant::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null || $locked->anonymized_at !== null) {
                return;
            }

            // Asked under the lock: what was true when the sweep chose this
            // applicant may not be true now.
            $blocker = $this->policy->blocker($locked);
            if ($blocker !== null) {
                return;
            }

            $now = now();
            $applicationIds = DB::table('recruitment_applications')->where('applicant_id', $id)->pluck('id')->all();
            $interviewIds = $applicationIds === [] ? [] : DB::table('recruitment_interviews')
                ->whereIn('application_id', $applicationIds)->pluck('id')->all();

            $this->stripApplicant($id, $reason, $now);
            $this->stripApplications($applicationIds, $now);
            $this->stripNotices($applicationIds, $interviewIds, $now);
            $this->stripInvites($applicationIds, (string) $locked->email_normalized, $now);
            $this->stripInterviews($interviewIds, $now);
            $this->stripOffers($applicationIds, $now);
            $this->stripNotesAndDecisions($id, $applicationIds);
            $purged = $this->purgeFiles($id, $now);

            $this->audit->record(
                RecruitmentPrivacyAudit::ACTION_ANONYMIZATION,
                RecruitmentPrivacyAudit::OUTCOME_SUCCESS,
                $actor,
                recordCount: 1,
                subjectType: RecruitmentPrivacyAuditor::SUBJECT_APPLICANT,
                subjectId: $id,
                reason: $reason,
                details: ['applications' => count($applicationIds), 'files_purged' => $purged],
            );
        });

        return $blocker;
    }

    private function stripApplicant(int $id, string $reason, mixed $now): void
    {
        DB::table('recruitment_applicants')->where('id', $id)->update([
            'full_name' => config('recruitment-privacy.anonymized.name'),
            'email_normalized' => $this->email("applicant-{$id}"),
            'phone_normalized' => null,
            'phone_match_key' => null,
            'identity_key_hash' => null,
            'identity_verified_at' => null,
            'employee_id' => null,
            'possible_duplicate_of_applicant_id' => null,
            'anonymized_at' => $now,
            'anonymization_reason' => $reason,
            'updated_at' => $now,
        ]);

        // Another applicant flagged as a possible duplicate of this one would
        // otherwise keep pointing at a person who no longer has a phone to match.
        DB::table('recruitment_applicants')
            ->where('possible_duplicate_of_applicant_id', $id)
            ->update(['possible_duplicate_of_applicant_id' => null]);
    }

    /** @param array<int, int> $applicationIds */
    private function stripApplications(array $applicationIds, mixed $now): void
    {
        if ($applicationIds === []) {
            return;
        }

        // One address per application: (campaign_id, email_normalized) is unique.
        foreach ($applicationIds as $applicationId) {
            DB::table('recruitment_applications')->where('id', $applicationId)->update([
                'email_normalized' => $this->email("application-{$applicationId}"),
                'ip_address' => null,
                'user_agent' => null,
                'submission_key_hash' => null,
                'updated_at' => $now,
            ]);
        }

        DB::table('recruitment_application_answers')->whereIn('application_id', $applicationIds)->update([
            'answer_json' => json_encode(['redacted' => true]),
            'updated_at' => $now,
        ]);

        DB::table('recruitment_application_indicator_snapshots')->whereIn('application_id', $applicationIds)->update([
            'indicators_json' => json_encode([]),
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<int, int>  $applicationIds
     * @param  array<int, int>  $interviewIds
     */
    private function stripNotices(array $applicationIds, array $interviewIds, mixed $now): void
    {
        if ($applicationIds !== []) {
            DB::table('recruitment_rejection_notices')->whereIn('application_id', $applicationIds)->update([
                'recipient_email' => $this->email('notice'),
                'failure_reason' => null,
                'updated_at' => $now,
            ]);
        }

        if ($interviewIds !== []) {
            DB::table('recruitment_interview_notices')->whereIn('interview_id', $interviewIds)->update([
                'recipient_email' => $this->email('notice'),
                'failure_reason' => null,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<int, int> $applicationIds */
    private function stripInvites(array $applicationIds, string $originalEmail, mixed $now): void
    {
        $used = $applicationIds === [] ? [] : DB::table('recruitment_applications')
            ->whereIn('id', $applicationIds)->whereNotNull('campaign_invite_id')->pluck('campaign_invite_id')->all();

        $invites = DB::table('recruitment_campaign_invites')
            ->where(fn ($query) => $query->whereIn('id', $used)->orWhere('email_normalized', $originalEmail))
            ->pluck('id');

        // (campaign_id, email_normalized) is unique, so each invite gets its own address.
        foreach ($invites as $inviteId) {
            DB::table('recruitment_campaign_invites')->where('id', $inviteId)->update([
                'full_name' => config('recruitment-privacy.anonymized.name'),
                'email_normalized' => $this->email("invite-{$inviteId}"),
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<int, int> $interviewIds */
    private function stripInterviews(array $interviewIds, mixed $now): void
    {
        if ($interviewIds === []) {
            return;
        }

        DB::table('recruitment_interviews')->whereIn('id', $interviewIds)->update([
            'cancellation_note' => null,
            'outcome_note' => null,
            'updated_at' => $now,
        ]);

        DB::table('recruitment_interview_reschedules')->whereIn('interview_id', $interviewIds)->update([
            'reason' => config('recruitment-privacy.anonymized.text'),
        ]);

        $evaluationIds = DB::table('recruitment_interview_evaluations')
            ->whereIn('interview_id', $interviewIds)->pluck('id')->all();

        if ($evaluationIds === []) {
            return;
        }

        // The recommendation stays: it is the aggregate. The opinion behind it goes.
        DB::table('recruitment_interview_evaluations')->whereIn('id', $evaluationIds)->update([
            'pros' => null,
            'cons' => null,
            'notes' => null,
            'withdrawal_reason' => null,
            'updated_at' => $now,
        ]);

        DB::table('recruitment_interview_evaluation_revisions')->whereIn('evaluation_id', $evaluationIds)->update([
            'pros' => null,
            'cons' => null,
            'notes' => null,
            'reason' => null,
        ]);
    }

    /** @param array<int, int> $applicationIds */
    private function stripOffers(array $applicationIds, mixed $now): void
    {
        if ($applicationIds === []) {
            return;
        }

        $offerIds = DB::table('recruitment_job_offers')->whereIn('application_id', $applicationIds)->pluck('id')->all();

        if ($offerIds === []) {
            return;
        }

        DB::table('recruitment_job_offers')->whereIn('id', $offerIds)->update([
            'response_note' => null,
            'cancellation_note' => null,
            'updated_at' => $now,
        ]);

        DB::table('recruitment_job_offer_versions')->whereIn('job_offer_id', $offerIds)->update([
            'revision_reason' => null,
            'updated_at' => $now,
        ]);
    }

    /** @param array<int, int> $applicationIds */
    private function stripNotesAndDecisions(int $applicantId, array $applicationIds): void
    {
        // Every note, soft-deleted ones included: the query builder sees them all.
        DB::table('recruitment_applicant_notes')->where('applicant_id', $applicantId)->update([
            'body' => config('recruitment-privacy.anonymized.text'),
            'deletion_reason' => null,
        ]);

        if ($applicationIds !== []) {
            DB::table('recruitment_application_decisions')->whereIn('application_id', $applicationIds)->update([
                'reason_text' => null,
            ]);
        }
    }

    /**
     * Last, so a failure earlier rolls the transaction back before any file is
     * touched. A failure here rolls the rest back too; the files already
     * removed are simply found missing on the next attempt, which is fine.
     */
    private function purgeFiles(int $applicantId, mixed $now): int
    {
        $files = RecruitmentApplicantFile::query()
            ->where('applicant_id', $applicantId)
            ->whereNull('purged_at')
            ->get();

        if ($files->isEmpty()) {
            return 0;
        }

        // What the CV reading holds was drawn from the document, so it goes with it.
        DB::table('recruitment_cv_analyses')->whereIn('file_id', $files->modelKeys())->delete();

        foreach ($files as $file) {
            $file->clearMediaCollection('document');

            DB::table('recruitment_applicant_files')->where('id', $file->getKey())->update([
                'purged_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $files->count();
    }

    /** An address that can never be delivered to (RFC 2606's .invalid). */
    private function email(string $local): string
    {
        return "anonymized-{$local}@".config('recruitment-privacy.anonymized.email_domain');
    }
}
