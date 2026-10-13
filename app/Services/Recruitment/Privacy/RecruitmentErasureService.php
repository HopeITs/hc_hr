<?php

namespace App\Services\Recruitment\Privacy;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentPrivacyAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A candidate asked to be forgotten, and HR recorded it.
 *
 * The request is durable before anything else happens: it is stamped on the
 * applicant and audited, and retention_until is pulled forward to now. If
 * nothing stands in the way the applicant is anonymized straight away. If an
 * application is still open the request waits — the nightly sweep carries it
 * out once HR has closed that application (withdrawing it is the natural
 * step), whether or not automatic retention is enforced, because an explicit
 * request is not a retention period.
 *
 * Authorized here as well as by the controller, like every service in this
 * module: the caller's check is not the only one.
 */
class RecruitmentErasureService
{
    public const COMPLETED = 'completed';

    public const PENDING = 'pending';

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly RecruitmentAnonymizer $anonymizer,
        private readonly RecruitmentPrivacyAuditor $audit,
    ) {}

    /**
     * @return array{status: string, blocker: string|null}
     */
    public function request(User $actor, RecruitmentApplicant $applicant, string $channel): array
    {
        Gate::forUser($actor)->authorize('requestErasure', $applicant);

        $blocker = null;

        $alreadyDone = DB::transaction(function () use ($actor, $applicant, $channel, &$blocker): bool {
            $locked = RecruitmentApplicant::query()->whereKey($applicant->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->anonymized_at !== null) {
                $this->record($actor, $locked, RecruitmentPrivacyAudit::OUTCOME_SUCCESS, $channel, 'already_anonymized');

                return true;
            }

            $locked->forceFill([
                'erasure_requested_at' => $locked->erasure_requested_at ?? now(),
                'retention_until' => now(),
            ])->save();

            $blocker = $this->policy->blocker($locked);

            $this->record(
                $actor,
                $locked,
                $blocker === null ? RecruitmentPrivacyAudit::OUTCOME_SUCCESS : RecruitmentPrivacyAudit::OUTCOME_BLOCKED,
                $channel,
                $blocker,
            );

            return false;
        });

        if ($alreadyDone) {
            return ['status' => self::COMPLETED, 'blocker' => null];
        }

        if ($blocker !== null) {
            return ['status' => self::PENDING, 'blocker' => $blocker];
        }

        // Asked again inside the anonymizer, under its own lock: an application
        // could have been submitted in the moment since the check above.
        $blocker = $this->anonymizer->anonymize($applicant, RecruitmentAnonymizer::REASON_ERASURE, $actor);

        return $blocker === null
            ? ['status' => self::COMPLETED, 'blocker' => null]
            : ['status' => self::PENDING, 'blocker' => $blocker];
    }

    private function record(User $actor, RecruitmentApplicant $applicant, string $outcome, string $channel, ?string $reason): void
    {
        $this->audit->record(
            RecruitmentPrivacyAudit::ACTION_ERASURE_REQUEST,
            $outcome,
            $actor,
            recordCount: 1,
            subjectType: RecruitmentPrivacyAuditor::SUBJECT_APPLICANT,
            subjectId: (int) $applicant->getKey(),
            reason: $reason,
            details: ['channel' => $channel],
        );
    }
}
