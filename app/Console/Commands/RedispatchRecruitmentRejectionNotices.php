<?php

namespace App\Console\Commands;

use App\Services\RecruitmentRejectionNoticeService;
use Illuminate\Console\Command;

/**
 * Re-queues rejection emails committed as queued whose job never ran. The
 * decision was saved long before; this only gets the notification out. The
 * job claims each notice under a lock, so one still waiting in the queue is
 * never sent twice.
 */
class RedispatchRecruitmentRejectionNotices extends Command
{
    protected $signature = 'recruitment:rejection-notices';

    protected $description = 'Re-queue rejection emails that never reached the queue';

    public function handle(RecruitmentRejectionNoticeService $notices): int
    {
        $requeued = $notices->redispatchStale();

        $this->info("Stalled rejection emails re-queued: {$requeued}.");

        return self::SUCCESS;
    }
}
