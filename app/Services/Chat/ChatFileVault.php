<?php

namespace App\Services\Chat;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Encrypts chat attachments at rest, piece by piece, so a 50 MB file never
 * has to be held in memory (memory_limit is 128M).
 *
 * Format: "HCCF", a version byte and the piece size, then for each piece a
 * 12-byte nonce, the ciphertext length, the ciphertext and a 16-byte GCM tag.
 * Each piece is authenticated together with its position and whether it is
 * the last one, so a file cannot be reordered, truncated or extended without
 * the read failing. AES-256-GCM through OpenSSL, which every PHP build has.
 *
 * The key is per purpose, not per file, so forwarding a file is a plain copy
 * of the encrypted bytes under a new random name.
 */
class ChatFileVault
{
    private const MAGIC = 'HCCF';

    private const VERSION = 1;

    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public function __construct(private readonly ChatKeys $keys) {}

    /**
     * Encrypt a local file into the chat disk under a random name.
     *
     * @return array{path: string, size: int} the stored path and the plaintext size
     */
    public function store(string $sourcePath): array
    {
        $in = @fopen($sourcePath, 'rb');

        if ($in === false) {
            throw new RuntimeException('Cannot read the file to encrypt.');
        }

        $path = $this->newPath();
        $target = $this->disk()->path($path);
        $this->ensureDirectory(dirname($target));
        $out = fopen($target, 'wb');

        try {
            $chunk = $this->chunkBytes();
            fwrite($out, self::MAGIC.chr(self::VERSION).pack('N', $chunk));

            $size = 0;
            $index = 0;
            $current = $this->readPiece($in, $chunk);

            // Read one piece ahead, so the last piece can be marked as last.
            do {
                $next = $current === '' ? '' : $this->readPiece($in, $chunk);
                $isLast = $next === '';
                $size += strlen($current);
                fwrite($out, $this->sealPiece($current, $index, $isLast));
                $index++;
                $current = $next;
            } while (! $isLast);
        } catch (Throwable $e) {
            fclose($out);
            @unlink($target);

            throw $e;
        } finally {
            fclose($in);
        }

        fclose($out);

        return ['path' => $path, 'size' => $size];
    }

    /** Encrypt bytes already in memory (a thumbnail). */
    public function storeContents(string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hccf');
        file_put_contents($tmp, $contents);

        try {
            return $this->store($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Decrypt a stored file, handing each plaintext piece to $write as it is
     * read, so it can go straight into a download response.
     *
     * @param  callable(string): void  $write
     */
    public function stream(string $path, callable $write): void
    {
        $in = @fopen($this->disk()->path($path), 'rb');

        if ($in === false) {
            throw new RuntimeException('Encrypted chat file not found.');
        }

        try {
            $header = fread($in, 9);

            if (strlen($header) !== 9 || substr($header, 0, 4) !== self::MAGIC || ord($header[4]) !== self::VERSION) {
                throw new RuntimeException('Not an encrypted chat file.');
            }

            $index = 0;
            $sawLast = false;

            while (! $sawLast) {
                $nonce = fread($in, self::NONCE_BYTES);
                $lengthBytes = fread($in, 4);

                if (strlen($nonce) !== self::NONCE_BYTES || strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('Encrypted chat file is truncated.');
                }

                $length = unpack('N', $lengthBytes)[1];
                $ciphertext = $length > 0 ? fread($in, $length) : '';
                $tag = fread($in, self::TAG_BYTES);

                if (strlen($ciphertext) !== $length || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('Encrypted chat file is truncated.');
                }

                // A piece claims to be last or not; only the right claim at
                // the right position authenticates.
                $plain = $this->openPiece($nonce, $ciphertext, $tag, $index, true);
                $sawLast = $plain !== null;

                if (! $sawLast) {
                    $plain = $this->openPiece($nonce, $ciphertext, $tag, $index, false);

                    if ($plain === null) {
                        throw new RuntimeException('Encrypted chat file failed its integrity check.');
                    }
                }

                if ($plain !== '') {
                    $write($plain);
                }

                $index++;
            }

            if (fread($in, 1) !== '') {
                throw new RuntimeException('Encrypted chat file has data after its end.');
            }
        } finally {
            fclose($in);
        }
    }

    /** The whole decrypted file; for small files only (thumbnails, tests). */
    public function read(string $path): string
    {
        $buffer = '';
        $this->stream($path, function (string $piece) use (&$buffer): void {
            $buffer .= $piece;
        });

        return $buffer;
    }

    /** A copy under a new name, for forwarding. No decryption needed. */
    public function copy(string $path): string
    {
        $copy = $this->newPath();
        $this->ensureDirectory(dirname($this->disk()->path($copy)));
        $this->disk()->copy($path, $copy);

        return $copy;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    private function sealPiece(string $plain, int $index, bool $isLast): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain, self::CIPHER, $this->keys->for(ChatKeys::FILES), OPENSSL_RAW_DATA,
            $nonce, $tag, $this->aad($index, $isLast), self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Chat file encryption failed.');
        }

        return $nonce.pack('N', strlen($ciphertext)).$ciphertext.$tag;
    }

    private function openPiece(string $nonce, string $ciphertext, string $tag, int $index, bool $isLast): ?string
    {
        $plain = openssl_decrypt(
            $ciphertext, self::CIPHER, $this->keys->for(ChatKeys::FILES), OPENSSL_RAW_DATA,
            $nonce, $tag, $this->aad($index, $isLast),
        );

        return $plain === false ? null : $plain;
    }

    private function aad(int $index, bool $isLast): string
    {
        return 'hccf|v'.self::VERSION.'|'.$index.'|'.($isLast ? '1' : '0');
    }

    /** @param resource $in */
    private function readPiece($in, int $bytes): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes && ! feof($in)) {
            $read = fread($in, $bytes - strlen($buffer));

            if ($read === false || $read === '') {
                break;
            }

            $buffer .= $read;
        }

        return $buffer;
    }

    private function newPath(): string
    {
        $name = Str::random(40);

        // Two levels of folders so no single directory grows huge.
        return substr($name, 0, 2).'/'.substr($name, 2, 2).'/'.$name.'.bin';
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function chunkBytes(): int
    {
        return max(1024, (int) config('chat.file_chunk_bytes', 1024 * 1024));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('chat.disk', 'chat-files'));
    }
}
