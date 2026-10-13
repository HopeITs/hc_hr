<?php

namespace App\Console\Commands;

use App\Services\Recruitment\Privacy\RecruitmentRetentionService;
use Illuminate\Console\Command;

/**
 * Phase 2 Task 11: keeps applicant retention dates current and anonymizes
 * applicants who are due — see RecruitmentRetentionService for the rules, and
 * config/recruitment-privacy.php for why automatic anonymization is off until
 * the retention periods are confirmed.
 *
 * Run it with --dry-run first on any host: it changes nothing and prints what
 * a real run would do.
 */
class SweepRecruitmentRetention extends Command
{
    protected $signature = 'recruitment:retention-sweep {--dry-run : Report what would change without changing anything}';

    protected $description = 'Stamp applicant retention dates and anonymize applicants who are due or asked to be forgotten';

    public function handle(RecruitmentRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = $retention->sweep($dryRun);

        $this->info(($dryRun ? '[dry run] ' : '').'Retention sweep finished.');
        $this->table(['Result', 'Applicants'], collect($stats)->map(fn (int $count, string $key): array => [$key, $count])->values()->all());

        if (! config('recruitment-privacy.retention.enforce') && $stats['due_held'] > 0) {
            $this->warn("{$stats['due_held']} applicant(s) are past retention_until but were left alone: RECRUITMENT_RETENTION_ENFORCE is off.");
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
