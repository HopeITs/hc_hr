<?php

namespace App\Console\Commands;

use App\Support\TaskAttemptSummary;
use Illuminate\Console\Command;

/**
 * Aggregate-only report of X2 task attempts (X2-I). It runs on the server's
 * command line, so only people with server access can read it; there is no
 * web page. Cells smaller than config('telemetry.min_cell') are hidden.
 */
class TaskAttemptsReport extends Command
{
    protected $signature = 'telemetry:report {--days=30 : Attempts started in the last N days}';

    protected $description = 'Counts and median durations of the four X2 task attempts (anonymous)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $summary = app(TaskAttemptSummary::class)->summarise(now()->subDays($days), now());

        $this->line("Task attempts started in the last {$days} day(s). Open attempts younger than "
            .config('telemetry.abandon_after_minutes').' min are excluded; older open ones count as abandoned.');

        $this->table(
            ['Task', 'Role', 'Lang', 'Attempts', 'Completed', 'Cancelled', 'Abandoned', 'Completion', 'With validation error', 'Median time (completed)'],
            array_map(fn (array $row) => [
                $row['task'], $row['role_context'], $row['locale'],
                $row['attempts'], $row['completed'], $row['cancelled'], $row['abandoned'],
                $row['completion_rate'], $row['validation_error_rate'], $row['median_duration'],
            ], $summary),
        );

        if (! config('telemetry.enabled')) {
            $this->warn('Collection is OFF (TASK_TELEMETRY_ENABLED is not true): no new attempts are being recorded.');
        }

        return self::SUCCESS;
    }
}
