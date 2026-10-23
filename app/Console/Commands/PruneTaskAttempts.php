<?php

namespace App\Console\Commands;

use App\Models\TaskAttempt;
use Illuminate\Console\Command;

/**
 * DEC-10: task-attempt telemetry is deleted after 90 days (X2-I). Scheduled
 * daily in routes/console.php. Runs whether or not collection is enabled, so
 * switching collection off never leaves old rows behind.
 */
class PruneTaskAttempts extends Command
{
    protected $signature = 'telemetry:prune';

    protected $description = 'Delete task-attempt telemetry older than the retention period (DEC-10: 90 days)';

    public function handle(): int
    {
        $days = (int) config('telemetry.retention_days', 90);

        $deleted = TaskAttempt::query()
            ->where('started_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} task attempt(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
