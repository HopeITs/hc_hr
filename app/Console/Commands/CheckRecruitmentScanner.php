<?php

namespace App\Console\Commands;

use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use Illuminate\Console\Command;

/**
 * Proves the antivirus works end to end, the way salary:key:check does for
 * pay figures: a scanner that is down looks exactly like one with nothing
 * to do, so run this after installing or reconfiguring clamd.
 *
 * It sends the EICAR test string (harmless, and recognized by every
 * antivirus as a test) and expects it flagged, then sends plain text and
 * expects it cleared. Nothing is written to disk.
 */
class CheckRecruitmentScanner extends Command
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected $signature = 'recruitment:scanner-check';

    protected $description = 'Check that ClamAV is reachable and detects the EICAR test file';

    public function handle(RecruitmentFileScanner $scanner): int
    {
        if (! $scanner->enabled()) {
            $this->error('Scanning is disabled (RECRUITMENT_SCANNER is not "clamd"). Every upload stays in quarantine.');

            return self::FAILURE;
        }

        $version = $scanner->version();
        if ($version === null) {
            $this->error('clamd is not reachable. Check CLAMD_SOCKET / CLAMD_HOST / CLAMD_PORT and that clamav-daemon is running.');

            return self::FAILURE;
        }
        $this->line("clamd: {$version}");

        $infected = $scanner->scan($this->stream(self::EICAR));
        if (! $infected->isInfected()) {
            $this->error('clamd did not flag the EICAR test string: '.($infected->message ?? $infected->outcome));

            return self::FAILURE;
        }
        $this->line("EICAR detected as {$infected->signature}.");

        $clean = $scanner->scan($this->stream('An ordinary CV with nothing in it.'));
        if (! $clean->isClean()) {
            $this->error('clamd did not clear a plain text file: '.($clean->message ?? $clean->outcome));

            return self::FAILURE;
        }

        $this->info('The antivirus is working: uploads will be scanned and released automatically.');

        return self::SUCCESS;
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
