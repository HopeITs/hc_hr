<?php

namespace App\Console\Commands;

use App\Services\HiringCampaignService;
use Illuminate\Console\Command;

class SyncRecruitmentCampaignStates extends Command
{
    protected $signature = 'recruitment:sync-campaign-states';

    protected $description = 'Open scheduled recruitment campaigns and expire campaigns whose window ended';

    public function handle(HiringCampaignService $campaigns): int
    {
        $result = $campaigns->syncScheduledStates();
        $this->info("Opened {$result['opened']} and expired {$result['expired']} recruitment campaign(s).");

        return self::SUCCESS;
    }
}
