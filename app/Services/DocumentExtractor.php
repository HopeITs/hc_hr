<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Reads a national ID card through Post Gen's Worker (POST /extract/id), so
 * a new employee's identity need not be typed out. The files go to Gemini
 * through the Worker and are not kept there; the result only pre-fills a form.
 */
class DocumentExtractor
{
    public const MAX_FILES = 4;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function isConfigured(): bool
    {
        return filled(config('services.post_gen.url')) && filled(config('services.post_gen.key'));
    }

    /** @param  list<UploadedFile>  $files  front and back, or one card */
    public function identity(array $files): ExtractedDocument
    {
        return $this->extract('id', $files);
    }

    /** @param  list<UploadedFile>  $files */
    private function extract(string $kind, array $files): ExtractedDocument
    {
        if (! $this->isConfigured()) {
            throw new DocumentExtractionException('POST_GEN_API_KEY is not set.');
        }

        if ($files === [] || count($files) > self::MAX_FILES) {
            throw new DocumentExtractionException(__('Attach between 1 and :max files.', ['max' => self::MAX_FILES]));
        }

        $request = Http::withToken(config('services.post_gen.key'))
            ->timeout(config('services.post_gen.timeout'))
            ->acceptJson();

        foreach ($files as $i => $file) {
            $request->attach('files', fopen($file->getRealPath(), 'r'), 'file-'.($i + 1), ['Content-Type' => $file->getMimeType()]);
        }

        try {
            $response = $request->post(rtrim(config('services.post_gen.url'), '/').'/extract/'.$kind);
        } catch (ConnectionException $e) {
            throw new DocumentExtractionException(__('The document reader could not be reached. Try again in a minute.'), previous: $e);
        }

        // The Worker streams spaces while Gemini reads, then the JSON; its errors come as {error}, often with 200.
        $body = json_decode(trim($response->body()), true);

        if (! is_array($body) || ! ($body['ok'] ?? false)) {
            report(new DocumentExtractionException('Post Gen /extract/'.$kind.' '.$response->status().': '.($body['error'] ?? mb_substr($response->body(), 0, 200))));

            throw new DocumentExtractionException(__('The document could not be read. Try again, or enter it by hand.'));
        }

        return new ExtractedDocument(
            kind: $kind,
            docType: (string) ($body['doc_type'] ?? ''),
            fields: array_map(fn ($v) => (string) $v, (array) ($body['fields'] ?? [])),
            text: (string) ($body['text'] ?? ''),
            lite: (bool) ($body['lite'] ?? false),
        );
    }
}
