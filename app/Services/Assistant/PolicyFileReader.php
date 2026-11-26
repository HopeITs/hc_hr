<?php

namespace App\Services\Assistant;

use RuntimeException;
use ZipArchive;

/**
 * Turns a policy file HR uploads into the plain text the assistant and the
 * policies pages read. Only Word and plain text: their text is in the file
 * itself, so nothing leaves the server to read them. PDFs (mostly scanned
 * Arabic) are not taken: HR uploads the Word original instead.
 */
class PolicyFileReader
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const MAX_KB = 20 * 1024;

    public const EXTENSIONS = ['docx', 'txt'];

    /** @throws RuntimeException when no text can be read */
    public function read(string $path, string $extension): string
    {
        $text = $this->normalize(match (strtolower($extension)) {
            'docx' => $this->docxText($path),
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
            'docx' => self::DOCX,
            default => 'text/plain',
        };
    }

    /** The file name without its extension. */
    public function title(string $name): string
    {
        return trim(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?: $name);
    }

    /** The paragraphs of a .docx, one per line. */
    private function docxText(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a readable .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('Not a readable .docx file.');
        }

        preg_match_all('#<w:p[ >].*?</w:p>#s', $xml, $paragraphs);

        return collect($paragraphs[0])
            ->map(fn (string $p) => preg_replace(['#<w:tab/>#', '#<w:br/>#'], ["\t", "\n"], $p))
            ->map(fn (string $p) => html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_XML1, 'UTF-8'))
            ->implode("\n");
    }

    /** Unix line ends, no BOM, no runs of blank lines. */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
