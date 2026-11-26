<?php

namespace App\Console\Commands;

use App\Services\Assistant\GeminiClient;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\PolicyFileReader;
use Illuminate\Console\Command;

/**
 * Proves on this host that a policy PDF HR uploads will be read: the Gemini
 * key is set, Gemini answers from here (it refuses some countries), the
 * text comes back whole, and PHP lets a file of the allowed size in. Exits 1
 * on any failure. Run it after a deploy or a key change.
 */
class CheckPolicyPdfReading extends Command
{
    protected $signature = 'policies:pdf-check';

    protected $description = 'Check that policy PDFs can be read on this host (Gemini key, reach, upload limits)';

    public function handle(GeminiClient $gemini): int
    {
        $ok = true;

        $ok = $this->uploadLimits() && $ok;

        if (! $gemini->isConfigured()) {
            $this->error('GEMINI_API_KEY is not set: PDF uploads will be refused.');

            return self::FAILURE;
        }

        $this->line('Model: '.config('assistant.gemini.model').' (then '.(implode(', ', (array) config('assistant.gemini.fallback_models')) ?: 'none').')');

        $token = 'HCHR-CHECK-'.random_int(1000, 9999);

        try {
            $text = $gemini->transcribe($this->pdf($token), 'application/pdf');
        } catch (GeminiException $e) {
            $this->error('Gemini could not read a test PDF: '.$e->getMessage());

            if ($e->isUnavailableHere()) {
                $this->line('Gemini refuses this host or this key; it will not start working by itself.');
            }

            return self::FAILURE;
        }

        if (! str_contains($text, $token)) {
            $this->error('Gemini answered, but not with the test PDF\'s text. It answered: '.mb_substr($text, 0, 200));

            return self::FAILURE;
        }

        $this->info('Gemini read the test PDF correctly.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** PHP must let in a file as large as the page accepts. */
    private function uploadLimits(): bool
    {
        $limit = PolicyFileReader::MAX_KB * 1024;
        $ok = true;

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $value = $this->bytes((string) ini_get($key));

            if ($value > 0 && $value < $limit) {
                $this->error("{$key} is ".ini_get($key).': files above that are refused before the page sees them (the page allows '.(PolicyFileReader::MAX_KB / 1024).' MB).');
                $ok = false;
            }
        }

        $livewire = (array) config('livewire.temporary_file_upload.rules', []);
        $this->line('Livewire upload rules: '.(implode(' ', array_map('strval', $livewire)) ?: 'default (max 12 MB)'));

        return $ok;
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** A one-page PDF that says $text. */
    private function pdf(string $text): string
    {
        $stream = "BT /F1 24 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
