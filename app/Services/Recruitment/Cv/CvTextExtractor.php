<?php

namespace App\Services\Recruitment\Cv;

use App\Models\RecruitmentCvAnalysis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Gets the text out of a CV with free local tools only.
 *
 *   pdf   pdftotext; if that yields almost nothing the PDF is a scan, so its
 *         first pages are rendered with pdftoppm and read by tesseract
 *         (Arabic + English)
 *   docx  read straight from the archive, with the same size guards the
 *         upload inspection uses
 *   doc   antiword
 *
 * Every tool runs as a separate process with a timeout and a fixed argument
 * list; no path or name from the applicant ever reaches a shell.
 */
class CvTextExtractor
{
    /**
     * @return array{status: string, method: ?string, text: string, error: ?string}
     */
    public function extract(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'doc' => $this->run('antiword', ['-m', 'UTF-8.txt', $path], 'antiword'),
            default => $this->result(RecruitmentCvAnalysis::UNSUPPORTED, null, '', 'Unsupported file type.'),
        };
    }

    private function pdf(string $path): array
    {
        $text = $this->run('pdftotext', ['-enc', 'UTF-8', '-layout', $path, '-'], 'pdftotext');

        if ($text['status'] === RecruitmentCvAnalysis::COMPLETED
            && mb_strlen(trim($text['text'])) >= (int) config('recruitment.cv_analysis.min_text_chars', 80)) {
            return $text;
        }

        $ocr = config('recruitment.cv_analysis.ocr.enabled', true)
            ? $this->ocr($path)
            : $this->result(RecruitmentCvAnalysis::NO_TEXT, 'ocr', '', null);
        $hasText = $text['status'] === RecruitmentCvAnalysis::COMPLETED && trim($text['text']) !== '';

        // Keep whichever reading found more; a short text layer still beats nothing.
        if ($ocr['status'] === RecruitmentCvAnalysis::COMPLETED && mb_strlen(trim($ocr['text'])) > mb_strlen(trim($text['text']))) {
            return $ocr;
        }

        if ($hasText) {
            return $text;
        }

        // Report why nothing came out: a missing tool is worth knowing about.
        return $text['status'] !== RecruitmentCvAnalysis::COMPLETED ? $text : $ocr;
    }

    private function ocr(string $path): array
    {
        $dir = storage_path('app/private/cv-ocr/'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);

        try {
            $pages = max(1, (int) config('recruitment.cv_analysis.ocr.max_pages', 3));
            $render = $this->run('pdftoppm', [
                '-r', (string) (int) config('recruitment.cv_analysis.ocr.dpi', 200),
                '-f', '1', '-l', (string) $pages, '-png', $path, $dir.'/page',
            ], 'ocr');

            if ($render['status'] !== RecruitmentCvAnalysis::COMPLETED) {
                return $render;
            }

            $images = collect(File::files($dir))
                ->filter(fn ($image): bool => $image->getExtension() === 'png')
                ->sortBy(fn ($image): string => $image->getFilename())
                ->values();

            $text = '';
            foreach ($images as $image) {
                $page = $this->run('tesseract', [
                    $image->getPathname(), 'stdout',
                    '-l', (string) config('recruitment.cv_analysis.ocr.languages', 'ara+eng'),
                ], 'ocr');

                if ($page['status'] !== RecruitmentCvAnalysis::COMPLETED) {
                    return $page;
                }
                $text .= $page['text']."\n";
            }

            return trim($text) === ''
                ? $this->result(RecruitmentCvAnalysis::NO_TEXT, 'ocr', '', null)
                : $this->result(RecruitmentCvAnalysis::COMPLETED, 'ocr', $text, null);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function docx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $this->result(RecruitmentCvAnalysis::FAILED, 'docx', '', 'The DOCX archive could not be opened.');
        }

        try {
            $stat = $zip->statName('word/document.xml');
            $limit = (int) config('recruitment-uploads.docx.max_entry_uncompressed_bytes', 20 * 1024 * 1024);
            if ($stat === false || $stat['size'] > $limit) {
                return $this->result(RecruitmentCvAnalysis::FAILED, 'docx', '', 'The DOCX body is missing or too large.');
            }

            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml)) {
            return $this->result(RecruitmentCvAnalysis::FAILED, 'docx', '', 'The DOCX body could not be read.');
        }

        // Paragraph and line breaks become newlines so each line keeps its meaning.
        $xml = preg_replace(['/<w:p[ >]/', '/<w:br\s*\/?>/', '/<w:tab\s*\/?>/'], ["\n$0", "\n", "\t"], $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim($text) === ''
            ? $this->result(RecruitmentCvAnalysis::NO_TEXT, 'docx', '', null)
            : $this->result(RecruitmentCvAnalysis::COMPLETED, 'docx', $text, null);
    }

    /** @param  array<int, string>  $arguments */
    private function run(string $tool, array $arguments, string $method): array
    {
        $binary = (string) config('recruitment.cv_analysis.binaries.'.$tool, $tool);

        try {
            $process = Process::timeout(max(5, (int) config('recruitment.cv_analysis.timeout', 60)))
                ->run([$binary, ...$arguments]);
        } catch (RuntimeException $exception) {
            return $this->result(RecruitmentCvAnalysis::TOOL_MISSING, $method, '', $tool.': '.$exception->getMessage());
        }

        // 127 (POSIX) / 9009 (Windows): the program itself was not found.
        if (in_array($process->exitCode(), [127, 9009], true)) {
            return $this->result(RecruitmentCvAnalysis::TOOL_MISSING, $method, '', $tool.' is not installed.');
        }

        if (! $process->successful()) {
            return $this->result(RecruitmentCvAnalysis::FAILED, $method, '', $tool.' exited with '.$process->exitCode().'.');
        }

        $text = mb_substr($process->output(), 0, (int) config('recruitment.cv_analysis.max_text_chars', 200000));

        return $this->result(RecruitmentCvAnalysis::COMPLETED, $method, $text, null);
    }

    private function result(string $status, ?string $method, string $text, ?string $error): array
    {
        return ['status' => $status, 'method' => $method, 'text' => $text, 'error' => $error === null ? null : mb_substr($error, 0, 191)];
    }
}
