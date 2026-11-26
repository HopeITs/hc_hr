<?php

namespace App\Services\Assistant;

use App\Services\Policies\DocxMarkdown;
use RuntimeException;

/**
 * Turns a policy file into the Markdown the policy page shows, the search
 * looks through and the assistant reads: headings, numbered clauses, bold and
 * tables kept.
 *
 *  - Word: from its own structure (DocxMarkdown), on this server.
 *  - PDF: written out by Gemini. PDFs are scanned Arabic more often than not,
 *    and no PDF parser keeps Arabic in order (tried 2026-11-26).
 *  - Text: as it is.
 */
class PolicyFileReader
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    // Livewire takes uploads of up to 12 MB (its default rule), and Gemini a
    // request of up to 20 MB, with the PDF in it as base64 (a third larger):
    // 12 MB fits both.
    public const MAX_KB = 12 * 1024;

    public const EXTENSIONS = ['pdf', 'docx', 'txt'];

    private const PDF_INSTRUCTION = 'Transcribe the complete text of this document in its original language, in reading order, as Markdown: '
        .'# for the title and ## / ### for section headings, numbered clauses as a numbered list ("1. ") and bullets as "- ", '
        .'**bold** where the document is bold, and tables as Markdown tables. Never translate, summarise or leave anything out. '
        .'Output only the Markdown, with no commentary and no code fence.';

    public function __construct(private GeminiClient $gemini, private DocxMarkdown $docx) {}

    /** @throws RuntimeException|GeminiException when no text can be read */
    public function read(string $path, string $extension): string
    {
        $text = $this->normalize(match (strtolower($extension)) {
            'pdf' => $this->unfence($this->gemini->transcribe((string) file_get_contents($path), 'application/pdf', self::PDF_INSTRUCTION)),
            'docx' => $this->docx->convert($path),
            'txt' => (string) file_get_contents($path),
            default => throw new RuntimeException('Unsupported file type.'),
        });

        if ($text === '') {
            throw new RuntimeException('No text could be read from it.');
        }

        return $text;
    }

    public function mimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'docx' => self::DOCX,
            default => 'text/plain',
        };
    }

    /** The file name without its extension. */
    public function title(string $name): string
    {
        return trim(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?: $name);
    }

    /** A model that wraps its answer in ```markdown fences anyway. */
    private function unfence(string $text): string
    {
        return preg_replace('/^\s*```[a-z]*\s*\n(.*)\n\s*```\s*$/s', '$1', $text) ?? $text;
    }

    /** Unix line ends, no BOM, no runs of blank lines. */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
