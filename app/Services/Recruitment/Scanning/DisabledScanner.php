<?php

namespace App\Services\Recruitment\Scanning;

use App\Support\Recruitment\ScanResult;

/**
 * For machines without ClamAV. It never clears a file: everything stays in
 * quarantine, so switching scanning off can only hide CVs, never expose them.
 */
class DisabledScanner implements RecruitmentFileScanner
{
    public function enabled(): bool
    {
        return false;
    }

    public function scan($stream): ScanResult
    {
        return ScanResult::error('disabled', 'Scanning is disabled.');
    }

    public function version(): ?string
    {
        return null;
    }
}
