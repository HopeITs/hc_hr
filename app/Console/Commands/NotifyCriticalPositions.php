<?php

namespace App\Console\Commands;

use App\Services\CriticalPositions\CriticalPositionAlerts;
use App\Services\NotificationDeliveryLedger;
use Illuminate\Console\Command;

/** Meeting 19/11, track 6: tell HR and the direct manager about critical positions that need a replacement. */
class NotifyCriticalPositions extends Command
{
    protected $signature = 'positions:notify-critical';

    protected $description = 'Notify HR and the direct manager when a critical position has no holder';

    public function handle(CriticalPositionAlerts $alerts, NotificationDeliveryLedger $ledger): int
    {
        $this->info('Sent '.$alerts->run($ledger).' critical position alert(s).');

        return self::SUCCESS;
    }
}
