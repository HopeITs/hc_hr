<?php

namespace App\Services\Board;

use App\Services\Recruitment\Cv\CvTextExtractor;
use Carbon\CarbonImmutable;
use Normalizer;
use Throwable;

/**
 * Reads an old minutes file (from before the system) and finds what its header says: the meeting's number, its date,
 * the time it ran from and to, and who led it. Only free local tools read the file (pdftotext, the Word archive,
 * antiword); the minutes never leave the server. What is found is a suggestion the person uploading confirms.
 */
class BoardArchiveReader
{
    public function __construct(private readonly CvTextExtractor $extractor) {}

    /**
     * @return array{text: string, number: ?int, date: ?string, from: ?string, to: ?string, led_by: ?string, location: ?string}
     */
    public function read(string $path, string $extension, ?string $fileName = null): array
    {
        try {
            $text = (string) ($this->extractor->extract($path, $extension)['text'] ?? '');
        } catch (Throwable $e) {
            report($e);
            $text = '';
        }

        return ['text' => $this->clean($text)] + $this->parse($text, $fileName);
    }

    /**
     * @return array{number: ?int, date: ?string, from: ?string, to: ?string, led_by: ?string, location: ?string}
     */
    public function parse(string $text, ?string $fileName = null): array
    {
        $text = $this->clean($text);
        $lines = preg_split('/\R/u', $text) ?: [];

        return [
            'number' => $this->number($text) ?? $this->numberFromName($fileName),
            'date' => $this->date($text) ?? $this->dateFromName($fileName),
            ...$this->times($lines),
            'led_by' => $this->after($lines, '/المسؤول\s*عن\s*الاجتماع\s*:?\s*(.+)$/u'),
            'location' => $this->after($lines, '/المكان\s*:?\s*([^:]+?)\s*$/u'),
        ];
    }

    /** Plain text: compatibility forms folded, direction marks and odd spaces removed. */
    public function clean(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        // Bidi embeddings / marks that pdftotext leaves around every Arabic run.
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text) ?? $text;
        // Eastern Arabic digits read as the same numbers.
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    }

    private function number(string $text): ?int
    {
        // "رقم الاجتماع: 046" (a PDF can split the digits: "04 6").
        if (preg_match('/رقم\s*(?:ال)?اجتماع\s*:?\s*([0-9][0-9 ]{0,5})/u', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);

            return $digits !== '' ? (int) $digits : null;
        }

        return null;
    }

    private function numberFromName(?string $name): ?int
    {
        return $name !== null && preg_match('/(\d{1,4})(?!.*\d)/u', pathinfo($name, PATHINFO_FILENAME), $m) ? (int) $m[1] : null;
    }

    /** The first date in the text: dd/mm/yyyy, or yyyy/mm/dd as a right-to-left PDF reads it back. */
    private function date(string $text): ?string
    {
        $labelled = preg_match('/التاري\s*خ\s*:?\s*([0-9][0-9\s\/.\-]{6,14}[0-9])/u', $text, $m) ? $m[1] : null;

        foreach (array_filter([$labelled, $text]) as $haystack) {
            if (preg_match('/(\d{4})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{1,2})/u', $haystack, $m)) {
                $date = $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
            } elseif (preg_match('/(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{4})/u', $haystack, $m)) {
                $date = $this->makeDate((int) $m[3], (int) $m[2], (int) $m[1]);
            } else {
                $date = null;
            }

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function dateFromName(?string $name): ?string
    {
        return $name !== null ? $this->date(pathinfo($name, PATHINFO_FILENAME)) : null;
    }

    private function makeDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) && $year >= 2000 && $year <= (int) date('Y') + 1
            ? CarbonImmutable::create($year, $month, $day)->toDateString()
            : null;
    }

    /**
     * "من الساعة 11.00" … "حتى الساعة 12.30": the earliest time on a line about the hour is the start, the latest the end.
     *
     * @param  list<string>  $lines
     * @return array{from: ?string, to: ?string}
     */
    private function times(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            if (! preg_match('/الساعة|التوقيت/u', $line)) {
                continue;
            }

            preg_match_all('/(?<!\d)(\d{1,2})[.:](\d{2})(?!\d)/u', $line, $all, PREG_SET_ORDER);

            foreach ($all as $m) {
                if ((int) $m[1] <= 23 && (int) $m[2] <= 59) {
                    $found[] = sprintf('%02d:%02d', $m[1], $m[2]);
                }
            }
        }

        sort($found);

        return ['from' => $found[0] ?? null, 'to' => count($found) > 1 ? end($found) : null];
    }

    /** @param list<string> $lines */
    private function after(array $lines, string $pattern): ?string
    {
        foreach ($lines as $line) {
            // A two-column PDF line: take the part that holds the label.
            foreach (preg_split('/\s{3,}/u', trim($line)) ?: [] as $part) {
                if (preg_match($pattern, trim($part), $m)) {
                    $value = trim($m[1], " :-\t");

                    return $value !== '' ? mb_substr($value, 0, 255) : null;
                }
            }
        }

        return null;
    }
}
