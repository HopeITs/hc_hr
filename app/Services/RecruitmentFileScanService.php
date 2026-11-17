<?php

namespace App\Services;

use App\Enums\RecruitmentFileScanStatus;
use App\Exceptions\RecruitmentScannerUnavailableException;
use App\Jobs\AnalyzeRecruitmentCv;
use App\Models\RecruitmentApplicantFile;
use App\Services\Monitoring\SecurityMonitor;
use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Moves an uploaded file out of quarantine, or keeps it there for good.
 *
 *   clean       copied to the clean disk and downloadable; a CV is queued
 *               for analysis
 *   infected    stays locked in quarantine, with the signature recorded
 *   scan error  the file stays pending and is tried again, by the job's
 *               retries and then by the scheduled sweep. A scanner that is
 *               down says nothing about the file, so it is never released
 *               and never condemned on that basis.
 */
class RecruitmentFileScanService
{
    public const AUDIT_LOG = 'recruitment-file-scan';

    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const DISABLED = 'disabled';

    public function __construct(
        private readonly RecruitmentFileScanner $scanner,
        private readonly RecruitmentApplicantFileService $files,
        private readonly ActivityLogger $activity,
        private readonly SecurityMonitor $monitor,
    ) {}

    /**
     * @throws RecruitmentScannerUnavailableException when the scanner could
     *                                                not answer; the caller retries later
     */
    public function scan(int $fileId): string
    {
        if (! $this->scanner->enabled()) {
            return self::DISABLED;
        }

        $file = RecruitmentApplicantFile::query()->find($fileId);
        if ($file === null || $file->scan_status !== RecruitmentFileScanStatus::Pending) {
            return self::SKIPPED;
        }

        $media = $file->getFirstMedia('document');
        if (! $media instanceof Media) {
            return $this->fail($file, ['message' => 'The quarantined file is missing.']);
        }

        $stream = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());
        if (! is_resource($stream)) {
            return $this->fail($file, ['message' => 'The quarantined file could not be read.']);
        }

        try {
            $result = $this->scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        if ($result->isClean()) {
            return $this->release($file, $result->metadata());
        }

        if ($result->isInfected()) {
            return $this->condemn($file, $result->metadata());
        }

        if ($result->permanent) {
            return $this->fail($file, $result->metadata());
        }

        throw new RecruitmentScannerUnavailableException((string) $result->message);
    }

    /** @param array<string, scalar|null> $metadata */
    private function release(RecruitmentApplicantFile $file, array $metadata): string
    {
        try {
            $file = $this->files->markClean($file, $metadata);
        } catch (RuntimeException $exception) {
            // Another worker got there first; anything else is real.
            if ($file->fresh()?->scan_status !== RecruitmentFileScanStatus::Pending) {
                return self::SKIPPED;
            }

            throw $exception;
        }

        if ($file->isCv() && config('recruitment.cv_analysis.enabled', true)) {
            rescue(fn () => Bus::dispatch(new AnalyzeRecruitmentCv($file->getKey())), report: true);
        }

        return self::CLEAN;
    }

    /** @param array<string, scalar|null> $metadata */
    private function condemn(RecruitmentApplicantFile $file, array $metadata): string
    {
        $this->files->markQuarantined($file, $metadata);

        $this->activity->log(
            'recruitment_file.infected',
            subject: $file,
            properties: [
                'application_id' => $file->application_id,
                'file_type' => $file->file_type,
                'signature' => $metadata['signature'] ?? null,
            ],
            logName: self::AUDIT_LOG,
        );

        $this->monitor->record(SecurityMonitor::SCAN_INFECTED, [
            'file_id' => $file->getKey(),
            'application_id' => $file->application_id,
            'file_type' => $file->file_type,
            'signature' => $metadata['signature'] ?? null,
        ]);

        return self::INFECTED;
    }

    /** @param array<string, scalar|null> $metadata */
    private function fail(RecruitmentApplicantFile $file, array $metadata): string
    {
        try {
            $this->files->markScanFailed($file, $metadata);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->activity->log(
            'recruitment_file.scan_failed',
            subject: $file,
            properties: ['application_id' => $file->application_id, 'message' => $metadata['message'] ?? null],
            logName: self::AUDIT_LOG,
        );

        $this->monitor->record(SecurityMonitor::SCAN_FAILED, [
            'file_id' => $file->getKey(),
            'application_id' => $file->application_id,
            'file_type' => $file->file_type,
        ]);

        return self::FAILED;
    }
}
