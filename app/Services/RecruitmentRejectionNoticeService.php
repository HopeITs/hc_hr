<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Jobs\SendRecruitmentRejectionNotice;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentRejectionNotice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The rejection email, kept strictly downstream of the decision.
 *
 * prepare() runs inside the decision's transaction and only writes a row;
 * dispatch() runs after commit and only queues. Nothing here can undo a
 * decision: if the queue is unreachable the row is marked failed and the
 * rejection stands. The row's unique application_id is what keeps a
 * candidate from ever getting a second apology.
 */
class RecruitmentRejectionNoticeService
{
    public const AUDIT_LOG = 'recruitment-rejection-notice';

    public const QUEUED = 'queued';

    public const ALREADY_NOTIFIED = 'already_notified';

    public const NO_RECIPIENT = 'no_recipient';

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Call inside the transaction that holds the application's row lock.
     *
     * @return array{0: string, 1: ?RecruitmentRejectionNotice}
     */
    public function prepare(User $actor, RecruitmentApplication $locked, ?RecruitmentApplicationDecision $decision): array
    {
        if ($locked->status !== Status::Rejected) {
            return [self::NO_RECIPIENT, null];
        }

        // A bulk caller preloads these, already locked, in one query.
        $notice = $locked->relationLoaded('rejectionNotice')
            ? $locked->rejectionNotice
            : RecruitmentRejectionNotice::query()->where('application_id', $locked->getKey())->lockForUpdate()->first();

        if ($notice?->isSettled()) {
            return [self::ALREADY_NOTIFIED, null];
        }

        $email = $this->recipient($locked);

        if ($email === null) {
            return [self::NO_RECIPIENT, null];
        }

        $attributes = [
            'decision_id' => $decision?->getKey() ?? $notice?->decision_id ?? $this->latestRejection($locked),
            'recipient_email' => $email,
            'status' => RecruitmentRejectionNotice::STATUS_QUEUED,
            'requested_by_user_id' => $actor->getKey(),
            'queued_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ];

        // A failed or cancelled notice is re-queued in place, never duplicated.
        $notice = $notice
            ? tap($notice, fn (RecruitmentRejectionNotice $existing) => $existing->forceFill($attributes)->save())
            : RecruitmentRejectionNotice::query()->forceCreate(['application_id' => $locked->getKey()] + $attributes);

        return [self::QUEUED, $notice];
    }

    /**
     * Call after commit. Returns how many could not be queued; those are
     * marked failed and can be retried from the bulk action.
     *
     * @param  iterable<RecruitmentRejectionNotice>  $notices
     */
    public function dispatch(iterable $notices): int
    {
        $failed = 0;

        foreach ($notices as $notice) {
            try {
                // Dispatched here, not from a PendingDispatch destructor, so a
                // queue failure lands in this catch.
                Bus::dispatch((new SendRecruitmentRejectionNotice($notice->getKey()))
                    ->onQueue(config('recruitment.decisions.notice_queue', 'default')));
            } catch (Throwable $exception) {
                report($exception);
                // On a sync queue the job's own failed() has already said why.
                $this->markFailed($notice->getKey(), 'queue_unavailable', onlyIfQueued: true);
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * Safety net for a notice committed as queued whose job never reached the
     * queue — the process died between commit and dispatch, or the payload was
     * lost. Such a row counts as settled, so without this nothing would ever
     * send it. claim() makes a duplicate dispatch harmless.
     *
     * Returns how many were queued again.
     */
    public function redispatchStale(?Carbon $now = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subMinutes(max(1, (int) config('recruitment.decisions.redispatch_after_minutes', 15)));

        $stale = RecruitmentRejectionNotice::query()
            ->where('status', RecruitmentRejectionNotice::STATUS_QUEUED)
            ->where('queued_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(200)
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        // Pushed forward first, so a slow queue is not flooded every sweep.
        RecruitmentRejectionNotice::query()
            ->whereKey($stale->modelKeys())
            ->where('status', RecruitmentRejectionNotice::STATUS_QUEUED)
            ->update(['queued_at' => $now]);

        return $stale->count() - $this->dispatch($stale);
    }

    /**
     * Takes a queued notice for sending, under a row lock. Null when there is
     * nothing to send: already claimed, sent, failed, or the application was
     * reopened (which cancels it here).
     */
    public function claim(int $noticeId): ?RecruitmentRejectionNotice
    {
        return DB::transaction(function () use ($noticeId): ?RecruitmentRejectionNotice {
            $notice = RecruitmentRejectionNotice::query()->whereKey($noticeId)->lockForUpdate()->first();

            if ($notice === null || $notice->status !== RecruitmentRejectionNotice::STATUS_QUEUED) {
                return null;
            }

            $notice->load('application.campaign.vacancy.position', 'application.applicant');

            if ($notice->application?->status !== Status::Rejected) {
                $notice->forceFill(['status' => RecruitmentRejectionNotice::STATUS_CANCELLED])->save();

                return null;
            }

            $notice->forceFill([
                'status' => RecruitmentRejectionNotice::STATUS_SENDING,
                'attempts' => $notice->attempts + 1,
            ])->save();

            return $notice;
        });
    }

    /** The transport refused the mail; hand the notice back for a retry. */
    public function release(int $noticeId): void
    {
        RecruitmentRejectionNotice::query()
            ->whereKey($noticeId)
            ->where('status', RecruitmentRejectionNotice::STATUS_SENDING)
            ->update(['status' => RecruitmentRejectionNotice::STATUS_QUEUED]);
    }

    public function markSent(int $noticeId): void
    {
        RecruitmentRejectionNotice::query()->whereKey($noticeId)->update([
            'status' => RecruitmentRejectionNotice::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    public function markFailed(int $noticeId, string $reason, bool $onlyIfQueued = false): void
    {
        $notice = RecruitmentRejectionNotice::query()->find($noticeId);

        if ($notice === null || $notice->status === RecruitmentRejectionNotice::STATUS_SENT
            || ($onlyIfQueued && $notice->status !== RecruitmentRejectionNotice::STATUS_QUEUED)) {
            return;
        }

        $notice->forceFill([
            'status' => RecruitmentRejectionNotice::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 191),
        ])->save();

        $this->activity->log(
            'recruitment_rejection_notice.failed',
            subject: $notice,
            properties: ['application_id' => (int) $notice->application_id, 'reason' => $notice->failure_reason],
            logName: self::AUDIT_LOG,
        );
    }

    /** Where they applied from; nothing once the applicant is anonymized. */
    private function recipient(RecruitmentApplication $application): ?string
    {
        $anonymized = $application->relationLoaded('applicant')
            ? $application->applicant?->anonymized_at !== null
            : $application->applicant()->whereNotNull('anonymized_at')->exists();

        if ($anonymized) {
            return null;
        }

        $email = trim((string) $application->email_normalized);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function latestRejection(RecruitmentApplication $application): ?int
    {
        $id = $application->decisions()
            ->where('to_status', Status::Rejected)
            ->latest('decided_at')
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
