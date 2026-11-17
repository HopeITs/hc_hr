<?php

namespace App\Services\Recruitment\Scanning;

use App\Support\Recruitment\ScanResult;
use RuntimeException;

/**
 * Talks to clamd (the ClamAV daemon) directly, with no package in between.
 *
 * INSTREAM protocol: "zINSTREAM\0", then chunks each prefixed with a 4-byte
 * big-endian length, then a zero-length chunk. The reply is one line:
 *   "stream: OK"                        clean
 *   "stream: <Signature> FOUND"         infected
 *   "INSTREAM size limit exceeded..."   the file is larger than StreamMaxLength
 *   anything else                       an error
 *
 * The file is streamed, never written anywhere clamd could read it from, so
 * clamd needs no access to the application's storage.
 */
class ClamdScanner implements RecruitmentFileScanner
{
    private const ENGINE = 'clamav';

    public function enabled(): bool
    {
        return true;
    }

    public function scan($stream): ScanResult
    {
        try {
            $socket = $this->connect();
        } catch (RuntimeException $exception) {
            return ScanResult::error(self::ENGINE, $exception->getMessage());
        }

        try {
            if (! $this->write($socket, "zINSTREAM\0")) {
                return ScanResult::error(self::ENGINE, 'clamd closed the connection.');
            }

            $chunk = max(1024, (int) config('recruitment-uploads.scanner.chunk_bytes', 65536));
            while (! feof($stream)) {
                $data = fread($stream, $chunk);
                if ($data === false) {
                    return ScanResult::error(self::ENGINE, 'The file could not be read.', permanent: true);
                }
                if ($data === '') {
                    continue;
                }
                if (! $this->write($socket, pack('N', strlen($data)).$data)) {
                    // clamd drops the connection once the size limit is hit;
                    // its reply says so, so read it rather than guess.
                    break;
                }
            }

            $this->write($socket, pack('N', 0));

            return $this->interpret($this->readReply($socket));
        } finally {
            fclose($socket);
        }
    }

    public function version(): ?string
    {
        try {
            $socket = $this->connect();
        } catch (RuntimeException) {
            return null;
        }

        try {
            $this->write($socket, "zVERSION\0");
            $reply = $this->readReply($socket);

            return $reply === '' ? null : $reply;
        } finally {
            fclose($socket);
        }
    }

    /** Public for tests: the whole meaning of a clamd reply lives here. */
    public function interpret(string $reply): ScanResult
    {
        $reply = trim($reply);

        if ($reply === 'stream: OK') {
            return ScanResult::clean(self::ENGINE);
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return ScanResult::infected(self::ENGINE, mb_substr($match[1], 0, 150));
        }

        if (str_contains($reply, 'size limit exceeded')) {
            return ScanResult::error(self::ENGINE, 'The file is larger than clamd StreamMaxLength.', permanent: true);
        }

        return ScanResult::error(self::ENGINE, $reply === '' ? 'clamd sent no reply.' : mb_substr($reply, 0, 150));
    }

    /** @return resource */
    private function connect()
    {
        $socketPath = (string) config('recruitment-uploads.scanner.socket', '');
        $timeout = max(1, (int) config('recruitment-uploads.scanner.timeout', 30));

        $address = $socketPath !== '' && file_exists($socketPath)
            ? 'unix://'.$socketPath
            : 'tcp://'.config('recruitment-uploads.scanner.host', '127.0.0.1').':'.config('recruitment-uploads.scanner.port', 3310);

        $socket = @stream_socket_client($address, $errno, $error, $timeout);
        if (! is_resource($socket)) {
            throw new RuntimeException("clamd is not reachable at {$address}: {$error}");
        }

        stream_set_timeout($socket, $timeout);

        return $socket;
    }

    /** @param  resource  $socket */
    private function write($socket, string $data): bool
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($socket, substr($data, $written));
            if ($result === false || $result === 0) {
                return false;
            }
            $written += $result;
        }

        return true;
    }

    /** @param  resource  $socket */
    private function readReply($socket): string
    {
        $reply = '';
        while (! feof($socket)) {
            $data = fread($socket, 8192);
            if ($data === false || $data === '') {
                break;
            }
            $reply .= $data;
            if (str_contains($reply, "\0")) {
                break;
            }
        }

        return trim(str_replace("\0", '', $reply));
    }
}
