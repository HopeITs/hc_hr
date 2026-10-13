<?php

namespace App\Console\Commands;

use App\Services\RecruitmentJobOfferService;
use Illuminate\Console\Command;

/**
 * Phase 2 Task 9: expires every sent offer past its expiry date. The
 * application goes back to "selected", where HR can revise the offer or
 * choose another candidate; the vacancy is left exactly as it is.
 *
 * Recording an answer re-checks the clock itself, so an offer is never
 * accepted after its date even if this has not run yet.
 */
class ExpireRecruitmentJobOffers extends Command
{
    protected $signature = 'recruitment:expire-offers';

    protected $description = 'Expire job offers whose answer date has passed';

    public function handle(RecruitmentJobOfferService $offers): int
    {
        $this->info('Offers expired: '.$offers->expireDue().'.');

        return self::SUCCESS;
    }
}
