<?php

namespace App\Services;

use App\Models\RecruitmentApplicantFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archiving takes a CV out of active review without removing it.
 *
 * A CV that came with an application is archived under that vacancy, and the
 * vacancy is its explanation. One with no vacancy behind it has none, so the
 * reviewer must write one: it is stored as an internal note and the file
 * points at it.
 */
class RecruitmentCvArchiveService
{
    public const AUDIT_LOG = 'recruitment-cv-archive';

    public const MIN_REASON = 10;

    public function __construct(
        private readonly RecruitmentApplicantNoteService $notes,
        private readonly ActivityLogger $activity,
    ) {}

    public function requiresReason(RecruitmentApplicantFile $file): bool
    {
        return $file->application_id === null || $file->application?->vacancy_id === null;
    }

    public function archive(User $actor, RecruitmentApplicantFile $file, ?string $reason): RecruitmentApplicantFile
    {
        $reason = trim((string) $reason) ?: null;

        if (! $file->isCv()) {
            throw ValidationException::withMessages(['archiveReason' => __('recruitment-applicant.cv_archive.not_a_cv')]);
        }

        $withoutVacancy = $this->requiresReason($file);

        if ($reason !== null && mb_strlen($reason) > RecruitmentApplicantNoteService::MAX_BODY) {
            throw ValidationException::withMessages(['archiveReason' => __('recruitment-applicant.notes.body_too_long', ['max' => RecruitmentApplicantNoteService::MAX_BODY])]);
        }

        if ($withoutVacancy && ($reason === null || mb_strlen($reason) < self::MIN_REASON)) {
            throw ValidationException::withMessages([
                'archiveReason' => __('recruitment-applicant.cv_archive.reason_required', ['min' => self::MIN_REASON]),
            ]);
        }

        return DB::transaction(function () use ($actor, $file, $reason, $withoutVacancy): RecruitmentApplicantFile {
            $locked = RecruitmentApplicantFile::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['archiveReason' => __('recruitment-applicant.cv_archive.already_archived')]);
            }

            $note = $reason === null ? null : $this->notes->recordArchiveReason(
                $actor,
                $locked->applicant,
                $locked->application,
                $reason,
            );

            $locked->forceFill([
                'archived_at' => now(),
                'archived_by_user_id' => $actor->getKey(),
                'archive_note_id' => $note?->getKey(),
            ])->save();

            $this->activity->log(
                'recruitment_cv.archived',
                subject: $locked,
                causer: $actor,
                properties: [
                    'file_id' => $locked->getKey(),
                    'applicant_id' => $locked->applicant_id,
                    'application_id' => $locked->application_id,
                    'without_vacancy' => $withoutVacancy,
                    'note_id' => $note?->getKey(),
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );

            return $locked;
        });
    }
}
