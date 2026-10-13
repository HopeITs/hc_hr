<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus as ApplicationStatus;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Jobs\SendRecruitmentInterviewNotice;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewNotice as Notice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The candidate's interview emails — scheduled, moved, cancelled, and the
 * reminder — kept strictly downstream of the interview record, on the same
 * design as RecruitmentRejectionNoticeService.
 *
 * prepare() runs inside the scheduling transaction and only writes a row;
 * dispatch() runs after commit and only queues a job carrying the row id. So
 * nothing is queued for a change that rolls back ("no reminder before
 * commit"), and a queue that is down marks the row failed without touching
 * the interview.
 *
 * Each row names the slot it describes (scheduled_for). claim() re-reads the
 * interview under a lock before anything is sent, and drops the email when it
 * no longer matches: a cancelled interview gets no reminder, a moved one gets
 * no email about its old time, and an application closed since gets nothing.
 */
class RecruitmentInterviewNoticeService
{
    public const AUDIT_LOG = 'recruitment-interview-notice';

    /** Application statuses after which the candidate hears nothing more about interviews. */
    private const CLOSED_APPLICATION = [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn, ApplicationStatus::Archived];

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Call inside the transaction that holds the interview's row lock.
     * Null when there is nobody to write to (anonymized applicant, no valid
     * address) or, for a reminder, when one already exists for this slot.
     */
    public function prepare(?User $actor, RecruitmentInterview $locked, string $kind, ?int $offsetMinutes = null): ?Notice
    {
        $email = $this->recipient($locked);

        if ($email === null) {
            return null;
        }

        if ($kind === Notice::KIND_REMINDER && Notice::query()
            ->where('interview_id', $locked->getKey())
            ->where('kind', Notice::KIND_REMINDER)
            ->where('scheduled_for', $locked->scheduled_at)
            ->where('offset_minutes', $offsetMinutes)
            ->exists()) {
            return null;
        }

        return Notice::query()->forceCreate([
            'interview_id' => $locked->getKey(),
            'kind' => $kind,
            'scheduled_for' => $locked->scheduled_at,
            'offset_minutes' => $kind === Notice::KIND_REMINDER ? $offsetMinutes : null,
            'recipient_email' => $email,
            'status' => Notice::STATUS_QUEUED,
            'requested_by_user_id' => $actor?->getKey(),
            'queued_at' => now(),
        ]);
    }

    /**
     * Inside the transaction: every email still waiting about this interview
     * is now out of date — it was moved, cancelled or has ended.
     */
    public function supersedeQueued(RecruitmentInterview $locked): int
    {
        return Notice::query()
            ->where('interview_id', $locked->getKey())
            ->where('status', Notice::STATUS_QUEUED)
            ->update(['status' => Notice::STATUS_CANCELLED, 'updated_at' => now()]);
    }

    /** Has the candidate already been reminded of this slot? */
    public function reminderSentFor(RecruitmentInterview $interview, Carbon $slot): bool
    {
        return Notice::query()
            ->where('interview_id', $interview->getKey())
            ->where('kind', Notice::KIND_REMINDER)
            ->where('scheduled_for', $slot)
            ->whereIn('status', [Notice::STATUS_SENDING, Notice::STATUS_SENT])
            ->exists();
    }

    /**
     * Queues the jobs once the outermost transaction commits — not merely the
     * scheduling service's own, which is only a savepoint when a caller wraps
     * it in a transaction of its own. A rollback anywhere above discards the
     * callback, so no job ever exists for a notice that was never committed.
     * Outside any transaction it runs at once.
     *
     * @param  array<int, Notice|null>  $notices
     */
    public function dispatchAfterCommit(array $notices): void
    {
        $notices = array_values(array_filter($notices));

        if ($notices !== []) {
            DB::afterCommit(fn () => $this->dispatch($notices));
        }
    }

    /**
     * Queues now. Call only once the rows are committed (see
     * dispatchAfterCommit). Returns how many could not be queued; those are
     * marked failed.
     *
     * @param  iterable<Notice|null>  $notices
     */
    public function dispatch(iterable $notices): int
    {
        $failed = 0;

        foreach ($notices as $notice) {
            if ($notice === null) {
                continue;
            }

            try {
                // Dispatched here, not from a PendingDispatch destructor, so a
                // queue failure lands in this catch.
                Bus::dispatch((new SendRecruitmentInterviewNotice($notice->getKey()))
                    ->onQueue(config('recruitment.interviews.notice_queue', 'default')));
            } catch (Throwable $exception) {
                report($exception);
                $this->markFailed($notice->getKey(), 'queue_unavailable', onlyIfQueued: true);
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * Writes the reminder rows that are due now and queues them after commit.
     *
     * A reminder is due once the interview is within its offset and still in
     * the future. A slot fixed after that window opened gets none for that
     * offset — the candidate was emailed about it moments ago.
     *
     * @return int how many reminders were queued
     */
    public function queueDueReminders(?Carbon $now = null): int
    {
        $now ??= now();
        $offsets = $this->reminderOffsets();

        if ($offsets === []) {
            return 0;
        }

        $candidates = RecruitmentInterview::query()
            ->open()
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $now->copy()->addMinutes(max($offsets)))
            ->pluck('id');

        $notices = [];

        foreach ($candidates as $interviewId) {
            $notices = array_merge($notices, DB::transaction(function () use ($interviewId, $offsets, $now): array {
                $interview = RecruitmentInterview::query()->whereKey($interviewId)->lockForUpdate()->first();

                // Re-read under the lock: it may have been cancelled or moved
                // since the list above was taken.
                if ($interview === null || ! $interview->isOpen() || $interview->scheduled_at->lte($now)) {
                    return [];
                }

                $interview->loadMissing('application.applicant');

                if (in_array($interview->application?->status, self::CLOSED_APPLICATION, true)) {
                    return [];
                }

                $queued = [];

                foreach ($offsets as $offset) {
                    $windowOpens = $interview->scheduled_at->copy()->subMinutes($offset);

                    if ($now->lt($windowOpens)) {
                        continue;
                    }

                    if ($interview->slot_set_at !== null && $interview->slot_set_at->gt($windowOpens)) {
                        continue;
                    }

                    $notice = $this->prepare(null, $interview, Notice::KIND_REMINDER, $offset);

                    if ($notice !== null) {
                        $queued[] = $notice;
                    }
                }

                return $queued;
            }));
        }

        $this->dispatchAfterCommit($notices);

        return count($notices);
    }

    /**
     * Safety net for a queue that was down: a row still queued long after it
     * was written is dispatched again. claim() makes a duplicate harmless.
     */
    public function redispatchStale(?Carbon $now = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subMinutes(max(1, (int) config('recruitment.interviews.redispatch_after_minutes', 15)));

        $stale = Notice::query()
            ->where('status', Notice::STATUS_QUEUED)
            ->where('queued_at', '<', $cutoff)
            ->limit(200)
            ->get();

        Notice::query()->whereKey($stale->modelKeys())->update(['queued_at' => $now]);

        // These rows were committed long ago; the update above is the only write.
        $this->dispatchAfterCommit($stale->all());

        return $stale->count();
    }

    /**
     * Takes a queued email for sending, under a row lock. Null when there is
     * nothing to send — and in that case the row says why (cancelled).
     */
    public function claim(int $noticeId): ?Notice
    {
        return DB::transaction(function () use ($noticeId): ?Notice {
            $notice = Notice::query()->whereKey($noticeId)->lockForUpdate()->first();

            if ($notice === null || $notice->status !== Notice::STATUS_QUEUED) {
                return null;
            }

            $interview = RecruitmentInterview::query()->whereKey($notice->interview_id)->lockForUpdate()->first();
            $interview?->load('application.applicant', 'application.campaign.vacancy.position');

            if (! $this->stillTrue($notice, $interview)) {
                $notice->forceFill(['status' => Notice::STATUS_CANCELLED])->save();

                return null;
            }

            $notice->forceFill([
                'status' => Notice::STATUS_SENDING,
                'attempts' => $notice->attempts + 1,
            ])->save();

            return $notice->setRelation('interview', $interview);
        });
    }

    /** The transport refused the mail; hand the notice back for a retry. */
    public function release(int $noticeId): void
    {
        Notice::query()
            ->whereKey($noticeId)
            ->where('status', Notice::STATUS_SENDING)
            ->update(['status' => Notice::STATUS_QUEUED]);
    }

    public function markSent(int $noticeId): void
    {
        Notice::query()->whereKey($noticeId)->update([
            'status' => Notice::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    public function markFailed(int $noticeId, string $reason, bool $onlyIfQueued = false): void
    {
        $notice = Notice::query()->find($noticeId);

        if ($notice === null || in_array($notice->status, [Notice::STATUS_SENT, Notice::STATUS_CANCELLED], true)
            || ($onlyIfQueued && $notice->status !== Notice::STATUS_QUEUED)) {
            return;
        }

        $notice->forceFill([
            'status' => Notice::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 191),
        ])->save();

        $this->activity->log(
            'recruitment_interview_notice.failed',
            subject: $notice,
            properties: ['interview_id' => (int) $notice->interview_id, 'kind' => $notice->kind, 'reason' => $notice->failure_reason],
            logName: self::AUDIT_LOG,
        );
    }

    /** @return array<int, int> reminder offsets in minutes, largest first */
    public function reminderOffsets(): array
    {
        $offsets = array_values(array_unique(array_filter(
            array_map('intval', (array) config('recruitment.interviews.reminder_offsets_minutes', [])),
            fn (int $offset): bool => $offset > 0,
        )));
        rsort($offsets);

        return $offsets;
    }

    /** Is what this email says still true right now? */
    private function stillTrue(Notice $notice, ?RecruitmentInterview $interview): bool
    {
        if ($interview === null || $interview->application === null) {
            return false;
        }

        if ($interview->application->applicant?->anonymized_at !== null) {
            return false;
        }

        if ($notice->kind === Notice::KIND_CANCELLED) {
            return $interview->status === Status::Cancelled;
        }

        // Scheduled, rescheduled and reminder emails describe an interview
        // that is still going to happen, at exactly the slot they name.
        if (! $interview->isOpen() || ! $interview->scheduled_at->equalTo($notice->scheduled_for)) {
            return false;
        }

        if (in_array($interview->application->status, self::CLOSED_APPLICATION, true)) {
            return false;
        }

        return $notice->kind !== Notice::KIND_REMINDER || $interview->scheduled_at->isFuture();
    }

    /** Where they applied from; nothing once the applicant is anonymized. */
    private function recipient(RecruitmentInterview $interview): ?string
    {
        $application = $interview->application()->with('applicant:id,anonymized_at')->first();

        if ($application === null || $application->applicant?->anonymized_at !== null) {
            return null;
        }

        $email = trim((string) $application->email_normalized);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
