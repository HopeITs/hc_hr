<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentRejectionNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The decision action service: every application status change goes through
 * here, each one recorded as a decision with its actor, date, reason and
 * from/to status. RecruitmentApplication refuses any other status write.
 *
 * Screening (review, shortlist, reject) and the accept decision are manual
 * moves made from the UI. Interview, offer and hiring statuses belong to the
 * steps that produce them, so those states offer only the moves listed here.
 *
 * Authorization is asked here as well as in the component: the component's
 * check guards the screen, this one guards the decision, whoever calls it.
 *
 * A closed or expired campaign does not block screening: reviewing what came
 * in is exactly what happens after the window shuts.
 */
class RecruitmentApplicationStatusService
{
    public const AUDIT_LOG = 'recruitment-application-status';

    public const REASON_CODE = 'manual_review';

    public const REASON_OTHER = 'other';

    public const MIN_REASON = 10;

    /**
     * What a bulk rejection may move. Anyone past screening deserves a
     * decision taken on its own screen, not as one row of a batch.
     */
    public const BULK_REJECTABLE = [Status::Submitted, Status::UnderReview, Status::Shortlisted];

    /*
    |--------------------------------------------------------------------------
    | Task 7 (interview scheduling) — added by Team 2, to review with Rita
    |--------------------------------------------------------------------------
    |
    | INTERVIEW_DRIVEN lists moves that exist in TRANSITIONS but are made only
    | by RecruitmentInterviewSchedulingService, through
    | applyInterviewTransition(), because each one mirrors an interview record: an application is
    | "interview scheduled" exactly when it has an open interview. Offering
    | them as buttons in the decision panel would let the status and the
    | interviews disagree, so targets() and change() leave them out.
    |
    */

    public const REASON_INTERVIEW = 'interview_scheduling';

    /*
    |--------------------------------------------------------------------------
    | Task 9 (job offers) — added by Team 2, to review with Rita
    |--------------------------------------------------------------------------
    |
    | Same rule as INTERVIEW_DRIVEN: each of these moves mirrors the job offer
    | (RecruitmentJobOfferService, through applyOfferTransition()), so the
    | decision panel never offers them. An application is "offer sent" exactly
    | while its offer is with the candidate.
    |
    */

    public const REASON_OFFER = 'job_offer';

    /*
    |--------------------------------------------------------------------------
    | Task 10 (hiring conversion) — added by Team 2, to review with Rita
    |--------------------------------------------------------------------------
    |
    | Same rule again: an application is "hired" exactly when its accepted
    | offer has been converted into an employee and a contract, so only
    | RecruitmentHiringConversionService makes that move, through
    | applyConversionTransition(), and the decision panel never offers it.
    |
    */

    public const REASON_CONVERSION = 'hiring_conversion';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        // HR now decides directly from a new submission: shortlist or reject.
        Status::Submitted => [Status::Shortlisted, Status::Rejected],
        // Legacy records may still be under review; let HR clear them forward.
        Status::UnderReview => [Status::Shortlisted, Status::Rejected],
        // Task 7: + InterviewScheduled (first interview scheduled).
        // Selection follows a completed interview; scheduling owns the move
        // into the interview state, and only Interviewed may be selected.
        Status::Shortlisted => [Status::Rejected, Status::InterviewScheduled],
        // Task 7: this state had no way out before. Interviewed / Shortlisted
        // are set by the interview service when the last open interview ends
        // or is cancelled; Rejected stays a manual screening decision.
        Status::InterviewScheduled => [Status::Interviewed, Status::Rejected, Status::Shortlisted],
        // Task 7: + InterviewScheduled (an additional interview).
        Status::Interviewed => [Status::Selected, Status::Rejected, Status::InterviewScheduled],
        // Task 9: + OfferSent (the offer went to the candidate). Once selected,
        // the manual decision panel stops; offer actions own the next moves.
        Status::Selected => [Status::OfferSent],
        // Task 9: the offer statuses had no way in or out before. All three
        // moves out of OfferSent follow the offer; from OfferRejected a
        // revised offer can be sent, or HR rejects the candidate by hand.
        Status::OfferSent => [Status::OfferAccepted, Status::OfferRejected, Status::Selected],
        Status::OfferRejected => [Status::OfferSent, Status::Rejected],
        // Task 10: the accepted offer became an employee and a contract.
        Status::OfferAccepted => [Status::Hired],
        Status::Rejected => [Status::Submitted],
    ];

    /** @var array<int, array{0: string, 1: string}> [from, to] */
    private const INTERVIEW_DRIVEN = [
        [Status::Shortlisted, Status::InterviewScheduled],
        [Status::Interviewed, Status::InterviewScheduled],
        [Status::InterviewScheduled, Status::Interviewed],
        [Status::InterviewScheduled, Status::Shortlisted],
    ];

    /** @var array<int, array{0: string, 1: string}> [from, to] */
    private const OFFER_DRIVEN = [
        [Status::Selected, Status::OfferSent],       // the offer was sent
        [Status::OfferSent, Status::OfferAccepted],  // the candidate accepted
        [Status::OfferSent, Status::OfferRejected],  // the candidate refused
        [Status::OfferSent, Status::Selected],       // the offer expired or was cancelled
        [Status::OfferRejected, Status::OfferSent],  // a revised offer after a refusal
    ];

    /** @var array<int, array{0: string, 1: string}> [from, to] */
    private const CONVERSION_DRIVEN = [
        [Status::OfferAccepted, Status::Hired],      // employee and contract created
    ];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly RecruitmentRejectionNoticeService $notices,
    ) {}

    /** @return array<int, string> the manual moves from here */
    public function targets(RecruitmentApplication $application): array
    {
        return $this->manualTargetsFrom((string) $application->status);
    }

    /**
     * Task 7: the status move that mirrors an interview being scheduled,
     * completed or cancelled. For RecruitmentInterviewSchedulingService only:
     * call it inside that service's transaction, on the application row it
     * already locked, after it has authorized the interview action itself.
     *
     * Recorded like every other move — a decision row with actor, date and
     * from/to, and an activity entry — so the history never has a gap.
     */
    public function applyInterviewTransition(User $actor, RecruitmentApplication $locked, string $to): RecruitmentApplicationDecision
    {
        return $this->applySystemTransition($actor, $locked, $to, self::INTERVIEW_DRIVEN, self::REASON_INTERVIEW);
    }

    /**
     * Task 9: the status move that mirrors the job offer being sent,
     * accepted, refused, expired or cancelled. For RecruitmentJobOfferService
     * only, on the same terms as applyInterviewTransition().
     */
    public function applyOfferTransition(User $actor, RecruitmentApplication $locked, string $to): RecruitmentApplicationDecision
    {
        return $this->applySystemTransition($actor, $locked, $to, self::OFFER_DRIVEN, self::REASON_OFFER);
    }

    /**
     * Task 10: offer accepted → hired, when the hiring conversion creates the
     * employee and the contract. For RecruitmentHiringConversionService only,
     * on the application row it already locked, inside its transaction.
     */
    public function applyConversionTransition(User $actor, RecruitmentApplication $locked, string $to): RecruitmentApplicationDecision
    {
        return $this->applySystemTransition($actor, $locked, $to, self::CONVERSION_DRIVEN, self::REASON_CONVERSION);
    }

    /** @return array<int, string> the moves this user may make from here */
    public function targetsFor(User $actor, RecruitmentApplication $application): array
    {
        return array_values(array_filter(
            $this->targets($application),
            fn (string $to): bool => Gate::forUser($actor)->allows($this->ability($to), $application),
        ));
    }

    /** Accepting needs its own grant; every other move is a screening one. */
    public function ability(string $to): string
    {
        return $to === Status::Selected ? 'accept' : 'changeStatus';
    }

    /** @return array<int, string> */
    public function rejectionReasons(): array
    {
        return array_values(config('recruitment.decisions.rejection_reasons', []));
    }

    public function requiresReasonCode(string $to): bool
    {
        return $to === Status::Rejected;
    }

    /**
     * A written reason: to take back a rejection or an acceptance, or when
     * the rejection reason is "other".
     */
    public function requiresReason(string $from, string $to, ?string $reasonCode = null): bool
    {
        return $from === Status::Rejected
            || $from === Status::Selected
            || ($to === Status::Rejected && $reasonCode === self::REASON_OTHER);
    }

    public function bulkLimit(): int
    {
        return max(1, (int) config('recruitment.decisions.bulk_limit', 500));
    }

    /**
     * $expectedFrom is the status the reviewer was looking at. If someone else
     * moved the application in the meantime, this refuses rather than
     * overwriting a decision the reviewer never saw.
     *
     * $notify applies to rejections only. The decision is committed first;
     * the email is queued afterwards and can fail without touching it.
     */
    public function change(
        User $actor,
        RecruitmentApplication $application,
        string $expectedFrom,
        string $to,
        ?string $reason,
        ?string $reasonCode = null,
        bool $notify = false,
    ): RecruitmentApplicationDecision {
        Gate::forUser($actor)->authorize($this->ability($to), $application);

        $reason = trim((string) $reason) ?: null;
        $reasonCode = trim((string) $reasonCode) ?: null;
        $notices = [];

        $decision = DB::transaction(function () use ($actor, $application, $expectedFrom, $to, $reason, $reasonCode, $notify, &$notices): RecruitmentApplicationDecision {
            $locked = RecruitmentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;

            if ($from !== $expectedFrom) {
                throw ValidationException::withMessages(['statusTarget' => __('recruitment-applicant.status_change.stale')]);
            }

            // Tasks 7 and 9: manual moves only; interview- and offer-driven ones are refused here.
            if (! in_array($to, $this->manualTargetsFrom($from), true)) {
                throw ValidationException::withMessages(['statusTarget' => __('recruitment-applicant.status_change.not_allowed')]);
            }

            $this->validateReason($from, $to, $reasonCode, $reason, 'statusReasonCode', 'statusReason');

            $decision = $this->record($actor, $locked, $from, $to, $reasonCode, $reason);

            if ($notify && $to === Status::Rejected) {
                [$outcome, $notice] = $this->notices->prepare($actor, $locked, $decision);
                if ($notice !== null) {
                    $notices[] = $notice;
                }
            }

            return $decision;
        });

        $this->notices->dispatch($notices);

        return $decision;
    }

    /**
     * Rejects every selected application still in screening, and — when
     * $notify — queues one apology email per candidate.
     *
     * The ids come from the browser. Any the actor cannot see are dropped and
     * counted, never named. Already-rejected applications keep their original
     * decision; with $notify they get the email only if they never had one.
     * Applications past screening are left alone.
     *
     * The decisions are one transaction: all are saved or none are. Emails are
     * queued only after it commits, and a failure there is recorded on the
     * notice, never rolled back into the decisions.
     *
     * @param  iterable<mixed>  $applicationIds
     * @return array{rejected: int, already_rejected: int, ineligible: int, out_of_scope: int, queued: int, already_notified: int, no_recipient: int, queue_failed: int}
     */
    public function bulkReject(User $actor, iterable $applicationIds, mixed $reasonCode, mixed $reason, bool $notify): array
    {
        Gate::forUser($actor)->authorize('bulkReject', RecruitmentApplication::class);

        $ids = $this->normalizeIds($applicationIds);
        $reasonCode = is_string($reasonCode) ? trim($reasonCode) : null;
        $reason = is_string($reason) ? (trim($reason) ?: null) : null;

        // Validated before any row is touched, with no status to lean on.
        $this->validateReason(Status::Submitted, Status::Rejected, $reasonCode, $reason, 'reasonCode', 'reason');

        $outcome = [
            'rejected' => [], 'already_rejected' => [], 'ineligible' => [],
            'queued' => [], 'already_notified' => [], 'no_recipient' => [],
        ];
        $notices = [];

        DB::transaction(function () use ($actor, $ids, $reasonCode, $reason, $notify, &$outcome, &$notices): void {
            $applications = RecruitmentApplication::query()
                ->visibleTo($actor)
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($notify) {
                $this->preloadForNotices($applications);
            }

            foreach ($applications as $application) {
                $from = (string) $application->status;
                $decision = null;

                if ($from === Status::Rejected) {
                    $outcome['already_rejected'][] = $application->getKey();
                } elseif (in_array($from, self::BULK_REJECTABLE, true)) {
                    $decision = $this->record($actor, $application, $from, Status::Rejected, $reasonCode, $reason, bulk: true);
                    $outcome['rejected'][] = $application->getKey();
                } else {
                    $outcome['ineligible'][] = $application->getKey();

                    continue;
                }

                if ($notify) {
                    [$result, $notice] = $this->notices->prepare($actor, $application, $decision);
                    $outcome[$result][] = $application->getKey();
                    if ($notice !== null) {
                        $notices[] = $notice;
                    }
                }
            }

            $outcome['out_of_scope'] = count($ids) - $applications->count();

            // One entry describing the whole batch, alongside the per-application
            // entries record() writes. Out-of-scope ids are counted, not listed.
            $this->activity->log(
                'recruitment_application.bulk_rejected',
                causer: $actor,
                properties: [
                    'reason_code' => $reasonCode,
                    'notify' => $notify,
                    'requested' => count($ids),
                    'rejected_ids' => $outcome['rejected'],
                    'already_rejected_ids' => $outcome['already_rejected'],
                    'ineligible_ids' => $outcome['ineligible'],
                    'notice_queued_ids' => $outcome['queued'],
                    'out_of_scope_count' => $outcome['out_of_scope'],
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );
        });

        $queueFailed = $this->notices->dispatch($notices);

        return [
            'rejected' => count($outcome['rejected']),
            'already_rejected' => count($outcome['already_rejected']),
            'ineligible' => count($outcome['ineligible']),
            'out_of_scope' => $outcome['out_of_scope'],
            'queued' => count($outcome['queued']) - $queueFailed,
            'already_notified' => count($outcome['already_notified']),
            'no_recipient' => count($outcome['no_recipient']),
            'queue_failed' => $queueFailed,
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $allowed  the moves this caller owns
     */
    private function applySystemTransition(User $actor, RecruitmentApplication $locked, string $to, array $allowed, string $reasonCode): RecruitmentApplicationDecision
    {
        $from = (string) $locked->status;

        if (! in_array([$from, $to], $allowed, true) || ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['statusTarget' => __('recruitment-applicant.status_change.not_allowed')]);
        }

        return $this->record($actor, $locked, $from, $to, null, null, systemReasonCode: $reasonCode);
    }

    /**
     * Tasks 7, 9 and 10: TRANSITIONS minus the system-driven moves.
     *
     * @return array<int, string>
     */
    private function manualTargetsFrom(string $from): array
    {
        return array_values(array_filter(
            self::TRANSITIONS[$from] ?? [],
            fn (string $to): bool => ! $this->isSystemDriven($from, $to),
        ));
    }

    /** Tasks 7, 9 and 10: moves only the interview, offer or conversion services make. */
    private function isSystemDriven(string $from, string $to): bool
    {
        return in_array([$from, $to], self::INTERVIEW_DRIVEN, true)
            || in_array([$from, $to], self::OFFER_DRIVEN, true)
            || in_array([$from, $to], self::CONVERSION_DRIVEN, true);
    }

    /**
     * The applicant (for anonymization) and any existing notice (locked), in
     * two queries for the whole batch instead of two per application.
     */
    private function preloadForNotices(EloquentCollection $applications): void
    {
        $applications->load('applicant:id,anonymized_at');

        $notices = RecruitmentRejectionNotice::query()
            ->whereIn('application_id', $applications->modelKeys())
            ->lockForUpdate()
            ->get()
            ->keyBy('application_id');

        $applications->each(fn (RecruitmentApplication $application) => $application->setRelation(
            'rejectionNotice',
            $notices->get($application->getKey()),
        ));
    }

    private function validateReason(string $from, string $to, ?string $reasonCode, ?string $reason, string $codeField, string $reasonField): void
    {
        if ($this->requiresReasonCode($to) && ! in_array($reasonCode, $this->rejectionReasons(), true)) {
            throw ValidationException::withMessages([$codeField => __('recruitment-applicant.decisions.reason_code_required')]);
        }

        if ($this->requiresReason($from, $to, $reasonCode) && ($reason === null || mb_strlen($reason) < self::MIN_REASON)) {
            throw ValidationException::withMessages([
                $reasonField => __('recruitment-applicant.status_change.reason_required', ['min' => self::MIN_REASON]),
            ]);
        }
    }

    /** Call inside a transaction, on a row already locked and validated. */
    private function record(
        User $actor,
        RecruitmentApplication $locked,
        string $from,
        string $to,
        ?string $reasonCode,
        ?string $reason,
        bool $bulk = false,
        ?string $systemReasonCode = null, // Tasks 7, 9 and 10: set by applySystemTransition()
    ): RecruitmentApplicationDecision {
        RecruitmentApplication::applyDecision($locked, $to);

        $decision = RecruitmentApplicationDecision::query()->forceCreate([
            'application_id' => $locked->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $systemReasonCode ?? ($to === Status::Rejected ? $reasonCode : self::REASON_CODE),
            'reason_text' => $reason,
            'decided_by_user_id' => $actor->getKey(),
            'decided_at' => now(),
        ]);

        // A reopened application's queued apology must not go out.
        if ($from === Status::Rejected) {
            RecruitmentRejectionNotice::query()
                ->where('application_id', $locked->getKey())
                ->where('status', RecruitmentRejectionNotice::STATUS_QUEUED)
                ->update(['status' => RecruitmentRejectionNotice::STATUS_CANCELLED]);
        }

        $this->activity->log(
            'recruitment_application.status_changed',
            subject: $locked,
            causer: $actor,
            properties: [
                'from' => $from,
                'to' => $to,
                'decision_id' => $decision->getKey(),
                'reason_code' => $decision->reason_code,
                'bulk' => $bulk,
                'role' => $actor->myRole(),
            ],
            logName: self::AUDIT_LOG,
        );

        return $decision;
    }

    /**
     * @param  iterable<mixed>  $applicationIds
     * @return array<int, int>
     */
    private function normalizeIds(iterable $applicationIds): array
    {
        $ids = [];
        foreach ($applicationIds as $id) {
            if (is_scalar($id) && ($int = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
                $ids[$int] = $int;
            }
        }

        if ($ids === []) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.decisions.bulk_empty')]);
        }

        if (count($ids) > $this->bulkLimit()) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.decisions.bulk_too_many', ['max' => $this->bulkLimit()])]);
        }

        return array_values($ids);
    }
}
