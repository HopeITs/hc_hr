<?php

namespace App\Jobs;

use App\Mail\RecruitmentInterviewMail;
use App\Services\RecruitmentInterviewNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one interview email to a candidate. Carries only the notice id, so the
 * payload in the jobs table holds no address, time or meeting link.
 *
 * At most once, exactly like SendRecruitmentRejectionNotice: the notice is
 * claimed (queued -> sending) under a row lock before the mail goes out, and
 * the claim drops an email whose interview was cancelled or moved since it
 * was written — which is also what stops a reminder for a cancelled interview.
 */
class SendRecruitmentInterviewNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $noticeId) {}

    public function handle(RecruitmentInterviewNoticeService $notices): void
    {
        $notice = $notices->claim($this->noticeId);

        if ($notice === null) {
            return;
        }

        try {
            Mail::to($notice->recipient_email)->send(new RecruitmentInterviewMail($notice));
        } catch (Throwable $exception) {
            // The transport refused it, so nothing went out: safe to retry.
            $notices->release($notice->getKey());

            throw $exception;
        }

        $notices->markSent($notice->getKey());
    }

    /** The interview stands; only the notice is marked. */
    public function failed(?Throwable $exception): void
    {
        app(RecruitmentInterviewNoticeService::class)->markFailed(
            $this->noticeId,
            $exception ? class_basename($exception) : 'unknown',
        );
    }
}
