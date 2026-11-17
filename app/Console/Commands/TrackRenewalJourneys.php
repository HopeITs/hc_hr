<?php

namespace App\Console\Commands;

use App\Services\RenewalJourneys;
use Illuminate\Console\Command;

/**
 * Renewal journey (DEC-09 increment 2, docs/ux/journeys.md §3.2): starts one
 * journey per contract that ends within 30 days and can renew, then settles
 * the open ones the system finished since (an auto-renewal with its leave
 * balances, a termination). Runs after the evening auto-renewal so a renewal
 * made tonight completes its journey tonight. Idempotent: the unique contract
 * id and the expected-state checks make a second run, a retry or a second
 * worker change nothing.
 */
class TrackRenewalJourneys extends Command
{
    protected $signature = 'renewals:track-journeys';

    protected $description = 'Start renewal journeys 30 days before a contract ends, and settle the ones the system finished';

    public function handle(RenewalJourneys $journeys): int
    {
        $started = $journeys->startDue();
        $settled = $journeys->settleOpen();

        $this->info("Started {$started} renewal journey(s); settled {$settled}.");

        return self::SUCCESS;
    }
}
