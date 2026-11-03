<?php

namespace App\Console\Commands;

use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Sends the push / email rows whose time has come (quiet hours over, digest
 * due, working day started). Scheduled every minute in routes/console.php.
 */
class DispatchDueNotifications extends Command
{
    protected $signature = 'notifications:dispatch-due {--limit=500 : Most rows to handle in one run}';

    protected $description = 'Send push and email notifications that are now due';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue(max(1, (int) $this->option('limit')));

        $this->info("Handled {$count} outbox row(s).");

        return self::SUCCESS;
    }
}
