<?php

namespace App\Services\Assistant;

use App\Models\PolicyDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Copies the policies in a link-shared Google Drive folder into
 * policy_documents as plain text, which is all the assistant ever reads.
 *
 * PDFs are scanned Arabic more often than not, so their text comes from
 * Gemini rather than a PDF parser. Gemini's free tier allows ~10-15 requests
 * a minute, hence the pause between transcriptions and the one slow retry.
 */
class DrivePolicySync
{
    public const FOLDER = 'application/vnd.google-apps.folder';

    public const GOOGLE_DOC = 'application/vnd.google-apps.document';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    // Gemini takes inline documents up to ~20 MB.
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const API = 'https://www.googleapis.com/drive/v3/files';

    // Seconds between two transcriptions, and before the retry after a 429.
    private const TRANSCRIBE_GAP = 6;

    private const RATE_LIMIT_WAIT = 30;

    private bool $transcribedOnce = false;

    public function __construct(private GeminiClient $gemini) {}

    /**
     * @return array{added: int, updated: int, unchanged: int, skipped: int, removed: int, failed: int, failures: list<array{title: string, reason: string}>}
     */
    public function run(string $folderId, bool $force = false): array
    {
        $summary = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'removed' => 0, 'failed' => 0, 'failures' => []];
        $this->transcribedOnce = false;

        try {
            $files = $this->listFolder($folderId);
        } catch (Throwable $e) {
            // Without a complete listing we cannot tell a removed file from
            // one we failed to see, so nothing is touched.
            $summary['failed']++;
            $summary['failures'][] = ['title' => 'Drive folder', 'reason' => $e->getMessage()];

            return $summary;
        }

        foreach ($files as $file) {
            $outcome = $this->syncFile($file, $force);

            if (is_string($outcome)) {
                $summary['failed']++;
                $summary['failures'][] = ['title' => $file['name'], 'reason' => $outcome];
            } else {
                $summary[$outcome['status']]++;
            }
        }

        // Every listed file counts as present, failed or skipped ones too, so
        // a bad download never deletes the copy we already have.
        $summary['removed'] = PolicyDocument::query()
            ->where('source', PolicyDocument::SOURCE_DRIVE)
            ->whereNotIn('external_id', array_column($files, 'id'))
            ->delete();

        return $summary;
    }

    /**
     * Every file under the folder, subfolders included. Throws on any failed
     * page, so a partial listing is never mistaken for the whole folder.
     *
     * @return list<array<string, mixed>>
     */
    private function listFolder(string $folderId, array $seen = []): array
    {
        $files = [];
        $pageToken = null;
        $seen[$folderId] = true;

        do {
            $response = Http::timeout(30)->get(self::API, array_filter([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime, size, webViewLink)',
                'pageSize' => 100,
                'pageToken' => $pageToken,
                'key' => $this->apiKey(),
            ]));

            if ($response->failed()) {
                throw new RuntimeException('Google Drive listing failed: '.$response->json('error.message', 'HTTP '.$response->status()));
            }

            foreach ($response->json('files', []) as $file) {
                if (($file['mimeType'] ?? null) === self::FOLDER) {
                    // A shortcut loop would otherwise recurse forever.
                    if (! isset($seen[$file['id']])) {
                        array_push($files, ...$this->listFolder($file['id'], $seen));
                    }

                    continue;
                }

                $files[] = $file;
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $files;
    }

    /**
     * @return array{status: 'added'|'updated'|'unchanged'|'skipped'}|string the outcome, or why it failed
     */
    private function syncFile(array $file, bool $force): array|string
    {
        $mime = $file['mimeType'] ?? '';

        if (! in_array($mime, ['application/pdf', self::GOOGLE_DOC, self::DOCX, 'text/plain'], true)) {
            return ['status' => 'skipped'];
        }

        // Eloquent writes a date's wall-clock time as is, so a UTC time must be
        // moved to the app timezone or it reads back hours off on every run.
        $modified = isset($file['modifiedTime'])
            ? Carbon::parse($file['modifiedTime'])->setTimezone(config('app.timezone'))
            : null;
        $document = PolicyDocument::query()->firstOrNew(['external_id' => $file['id']]);

        // Drive reports milliseconds, the column keeps seconds.
        if (! $force && $document->exists && filled($document->content)
            && $modified && $document->source_modified_at?->getTimestamp() === $modified->getTimestamp()) {
            return ['status' => 'unchanged'];
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'Larger than '.(self::MAX_BYTES / 1024 / 1024).' MB.';
        }

        try {
            $text = $this->normalize($this->extract($file['id'], $mime));
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if ($text === '') {
            return 'No text could be read from it.';
        }

        $status = $document->exists ? 'updated' : 'added';

        $document->fill([
            'source' => PolicyDocument::SOURCE_DRIVE,
            'title' => $this->title($file['name'], $mime),
            'mime_type' => $mime,
            'source_url' => $file['webViewLink'] ?? null,
            'content' => $text,
            'source_modified_at' => $modified,
            'synced_at' => now(),
        ])->save();

        return ['status' => $status];
    }

    private function extract(string $id, string $mime): string
    {
        return match ($mime) {
            self::GOOGLE_DOC => $this->fetch(self::API."/{$id}/export", ['mimeType' => 'text/plain']),
            self::DOCX => $this->docxText($this->download($id)),
            'text/plain' => $this->download($id),
            'application/pdf' => $this->transcribe($this->download($id), $mime),
        };
    }

    private function download(string $id): string
    {
        $bytes = $this->fetch(self::API."/{$id}", ['alt' => 'media']);

        // A Google Doc has no size in the listing, and a listed size can lie.
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Larger than '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        return $bytes;
    }

    private function fetch(string $url, array $query): string
    {
        try {
            $response = Http::timeout(120)->get($url, $query + ['key' => $this->apiKey()]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Google Drive is unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('Google Drive download failed: '.$response->json('error.message', 'HTTP '.$response->status()));
        }

        return $response->body();
    }

    private function transcribe(string $bytes, string $mime): string
    {
        if ($this->transcribedOnce) {
            Sleep::for(self::TRANSCRIBE_GAP)->seconds();
        }

        $this->transcribedOnce = true;

        try {
            return $this->gemini->transcribe($bytes, $mime);
        } catch (GeminiException $e) {
            if (! $e->isTemporary()) {
                throw $e;
            }

            Sleep::for(self::RATE_LIMIT_WAIT)->seconds();

            return $this->gemini->transcribe($bytes, $mime);
        }
    }

    /** The paragraphs of a .docx, one per line. */
    private function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'policy');

        try {
            file_put_contents($path, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException('Not a readable .docx file.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

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

    /** The file name without the extension Drive kept from the upload. */
    private function title(string $name, string $mime): string
    {
        return $mime === self::GOOGLE_DOC ? $name : (preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?: $name);
    }

    private function apiKey(): string
    {
        return (string) config('services.google.drive_api_key');
    }
}
