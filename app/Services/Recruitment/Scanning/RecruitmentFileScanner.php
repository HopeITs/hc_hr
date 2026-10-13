<?php

namespace App\Services\Recruitment\Scanning;

use App\Support\Recruitment\ScanResult;

interface RecruitmentFileScanner
{
    /** False when scanning is switched off; files then stay in quarantine. */
    public function enabled(): bool;

    /** @param  resource  $stream  read from its current position to EOF */
    public function scan($stream): ScanResult;

    /** A one-line answer to "is the scanner there", for the health check. */
    public function version(): ?string;
}
