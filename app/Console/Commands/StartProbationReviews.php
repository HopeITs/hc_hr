<?php

namespace App\Console\Commands;

use App\Services\ProbationReviews;
use Illuminate\Console\Command;

/**
 * Probation review (meeting 19/11, track 4): starts a review for every running
 * trial that entered its last third, and reminds the direct manager weekly
 * until they answer. Idempotent: the unique contract id and the notification
 * ledger make a second run, a retry or a second worker change nothing.
 *
 * Scheduled at 21:20, before the contract amendments (21:25), deactivation
 * (21:30) and auto-renewal (21:35): a trial ending tonight still gets its
 * review, and the sweeps that follow never see a trial to roll over.
 */
class StartProbationReviews extends Command
{
    protected $signature = 'probation:start-reviews';

    protected $description = 'Start the last-third review of trial contracts and remind the managers who have not answered';

    public function handle(ProbationReviews $reviews): int
    {
        $started = $reviews->startDue();
        $reminded = $reviews->remindPending();

        $this->info("Started {$started} probation review(s); sent {$reminded} reminder(s).");

        return self::SUCCESS;
    }
}
