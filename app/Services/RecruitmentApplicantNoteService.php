<?php

namespace App\Services;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way internal notes are written or removed.
 *
 * Authorization is the caller's job (RecruitmentApplicantNotePolicy); this
 * service keeps the record honest whoever calls it: the author and date come
 * from here, never from input; a note cannot be attached to someone else's
 * application; and a removal that cannot be audited does not happen.
 */
class RecruitmentApplicantNoteService
{
    public const AUDIT_LOG = 'recruitment-notes';

    public const MAX_BODY = 5000;

    public const MIN_REASON = 10;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * A general note. It names an application whenever the applicant has one,
     * so that it is scoped by that application; a note on the applicant alone
     * is only for records with no application to hang it on.
     */
    public function add(
        User $actor,
        RecruitmentApplicant $applicant,
        ?RecruitmentApplication $application,
        string $body,
        bool $sensitive = false,
    ): RecruitmentApplicantNote {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('recruitment-applicant.notes.body_required')]);
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => __('recruitment-applicant.notes.body_too_long', ['max' => self::MAX_BODY])]);
        }

        if ($application === null && $applicant->applications()->exists()) {
            throw ValidationException::withMessages(['applicationId' => __('recruitment-applicant.notes.application_required')]);
        }

        return $this->record($actor, $applicant, $application, $body, RecruitmentApplicantNote::TYPE_GENERAL, $sensitive);
    }

    /** The justification for archiving a CV; written by the archive service only. */
    public function recordArchiveReason(
        User $actor,
        RecruitmentApplicant $applicant,
        ?RecruitmentApplication $application,
        string $reason,
    ): RecruitmentApplicantNote {
        return $this->record($actor, $applicant, $application, $reason, RecruitmentApplicantNote::TYPE_CV_ARCHIVE, false);
    }

    /**
     * Soft-delete with the audit entry in the same transaction.
     *
     * If the audit write fails the note stays — that is the point. The entry
     * carries a hash of the body rather than the body: the activity log has a
     * wider audience than HR, and the note row itself is kept.
     */
    public function delete(User $actor, RecruitmentApplicantNote $note, ?string $reason): void
    {
        $reason = trim((string) $reason) ?: null;

        if (! $note->isDeletable()) {
            throw ValidationException::withMessages(['deletionReason' => __('recruitment-applicant.notes.not_deletable')]);
        }

        if ($note->is_sensitive && ($reason === null || mb_strlen($reason) < self::MIN_REASON)) {
            throw ValidationException::withMessages([
                'deletionReason' => __('recruitment-applicant.notes.deletion_reason_required', ['min' => self::MIN_REASON]),
            ]);
        }

        DB::transaction(function () use ($actor, $note, $reason): void {
            $locked = RecruitmentApplicantNote::query()->whereKey($note->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['deletionReason' => __('recruitment-applicant.notes.already_deleted')]);
            }

            $locked->forceFill([
                'deleted_at' => now(),
                'deleted_by_user_id' => $actor->getKey(),
                'deletion_reason' => $reason,
            ])->save();

            $this->activity->log(
                'recruitment_note.deleted',
                subject: $locked,
                causer: $actor,
                properties: [
                    'note_id' => $locked->getKey(),
                    'applicant_id' => $locked->applicant_id,
                    'application_id' => $locked->application_id,
                    'author_id' => $locked->user_id,
                    'note_type' => $locked->note_type,
                    'is_sensitive' => $locked->is_sensitive,
                    'written_at' => $locked->created_at?->toIso8601String(),
                    'body_sha256' => hash('sha256', (string) $locked->body),
                    'reason' => $reason,
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );
        });
    }

    private function record(
        User $actor,
        RecruitmentApplicant $applicant,
        ?RecruitmentApplication $application,
        string $body,
        string $type,
        bool $sensitive,
    ): RecruitmentApplicantNote {
        if ($application !== null && (int) $application->applicant_id !== (int) $applicant->getKey()) {
            throw ValidationException::withMessages(['applicationId' => __('recruitment-applicant.notes.application_mismatch')]);
        }

        return DB::transaction(function () use ($actor, $applicant, $application, $body, $type, $sensitive): RecruitmentApplicantNote {
            $note = new RecruitmentApplicantNote;
            $note->forceFill([
                'applicant_id' => $applicant->getKey(),
                'application_id' => $application?->getKey(),
                'user_id' => $actor->getKey(),
                'note_type' => $type,
                'is_sensitive' => $sensitive,
                'body' => $body,
                'created_at' => now(),
            ])->save();

            $this->activity->log(
                'recruitment_note.created',
                subject: $note,
                causer: $actor,
                properties: [
                    'note_id' => $note->getKey(),
                    'applicant_id' => $note->applicant_id,
                    'application_id' => $note->application_id,
                    'note_type' => $type,
                    'is_sensitive' => $sensitive,
                ],
                logName: self::AUDIT_LOG,
            );

            return $note;
        });
    }
}
