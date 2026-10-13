<?php

namespace App\Console\Commands;

use App\Services\HiringCampaignService;
use Illuminate\Console\Command;

/**
 * Writes expiry onto campaigns whose window has run out.
 *
 * A tidying job, not a gate. Every check that matters — whether the advert is
 * readable, whether an application is accepted — already measures the window
 * against the clock and treats a campaign with a past `ends_at` as expired
 * whatever the column says. Without that this command would be a security
 * control that runs once a day, which is no control at all.
 *
 * What it buys is honest lists: a campaign that ended overnight reads as
 * expired in the morning rather than as open-but-not-really.
 */
class ExpireDueHiringCampaigns extends Command
{
    protected $signature = 'recruitment:expire-campaigns';

    protected $description = 'Mark hiring campaigns whose end date has passed as expired';

    public function handle(HiringCampaignService $campaigns): int
    {
        $this->info("Expired {$campaigns->expireDue()} campaigns.");

        return self::SUCCESS;
    }
}
