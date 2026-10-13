<?php

namespace App\Console\Commands;

use App\Services\RecruitmentInterviewNoticeService;
use Illuminate\Console\Command;

/**
 * Phase 2 Task 7: queues the candidate reminders that are due, and re-queues
 * any interview email still waiting because the queue was down when it was
 * written. Runs every five minutes; reminder offsets come from
 * config('recruitment.interviews.reminder_offsets_minutes').
 *
 * Only open interviews are looked at, and each one is re-read under a lock,
 * so a cancelled interview never gets a reminder. Each reminder row is
 * committed before its job is queued.
 */
class SendRecruitmentInterviewReminders extends Command
{
    protected $signature = 'recruitment:interview-reminders';

    protected $description = 'Queue due interview reminders for candidates and re-queue stalled interview emails';

    public function handle(RecruitmentInterviewNoticeService $notices): int
    {
        $queued = $notices->queueDueReminders();
        $requeued = $notices->redispatchStale();

        $this->info("Reminders queued: {$queued}. Stalled emails re-queued: {$requeued}.");

        return self::SUCCESS;
    }
}
