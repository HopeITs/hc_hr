<?php

namespace App\Console\Commands;

use App\Enums\RecruitmentFileScanStatus;
use App\Jobs\ScanRecruitmentApplicantFile;
use App\Models\RecruitmentApplicantFile;
use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use App\Services\RecruitmentFileScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * The antivirus sweep: queues (or, with --now, runs) a scan for every file
 * still waiting in quarantine. Scheduled every fifteen minutes as a safety
 * net, and the way to clear the backlog of files uploaded before scanning
 * was switched on.
 */
class ScanRecruitmentFiles extends Command
{
    protected $signature = 'recruitment:scan-files
        {--now : Scan in this process instead of queueing}
        {--limit=500 : Files per run}';

    protected $description = 'Scan recruitment uploads still waiting in quarantine with ClamAV';

    public function handle(RecruitmentFileScanner $scanner, RecruitmentFileScanService $scans): int
    {
        if (! $scanner->enabled()) {
            $this->warn('Scanning is disabled (RECRUITMENT_SCANNER). Files stay in quarantine.');

            return self::SUCCESS;
        }

        // Fresh uploads already have a job of their own on the way.
        $ids = RecruitmentApplicantFile::query()
            ->where('scan_status', RecruitmentFileScanStatus::Pending)
            ->where('uploaded_at', '<=', now()->subMinutes((int) config('recruitment-uploads.scanner.sweep_after_minutes', 5)))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        if (! $this->option('now')) {
            $ids->each(fn ($id) => Bus::dispatch(new ScanRecruitmentApplicantFile((int) $id)));
            $this->info("Queued {$ids->count()} file(s) for scanning.");

            return self::SUCCESS;
        }

        $outcomes = [];
        foreach ($ids as $id) {
            try {
                $outcome = $scans->scan((int) $id);
            } catch (Throwable $exception) {
                $this->error("File {$id}: {$exception->getMessage()}");
                $outcome = 'error';
            }
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        $this->table(['Outcome', 'Files'], collect($outcomes)->map(fn ($count, $outcome) => [$outcome, $count])->values());

        return ($outcomes['error'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
