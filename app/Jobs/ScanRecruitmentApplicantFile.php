<?php

namespace App\Jobs;

use App\Services\Monitoring\SecurityMonitor;
use App\Services\RecruitmentFileScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans one uploaded file. Safe to run twice: a file that is no longer
 * pending is skipped.
 *
 * When clamd stays unreachable through every retry the file is simply left
 * pending — never released, never condemned — and the scheduled
 * recruitment:scan-files sweep picks it up once the daemon is back.
 */
class ScanRecruitmentApplicantFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $fileId) {}

    public function handle(RecruitmentFileScanService $scans): void
    {
        $scans->scan($this->fileId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Recruitment file scan gave up; the file stays in quarantine until the next sweep.', [
            'file_id' => $this->fileId,
            'reason' => $exception?->getMessage(),
        ]);

        app(SecurityMonitor::class)->record(SecurityMonitor::SCAN_GAVE_UP, [
            'file_id' => $this->fileId,
            'exception' => $exception === null ? null : class_basename($exception),
        ]);
    }
}
