<?php

namespace App\Livewire\Concerns;

use App\Services\DocumentExtractionException;
use App\Services\DocumentExtractor;
use App\Services\ExtractedDocument;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Fill from an ID card" when adding an employee: the files HR attaches go to
 * Post Gen (DocumentExtractor), and what it reads only fills the form. The
 * component decides which fields; nothing is saved here, and the files are
 * not kept. The component also uses WithFileUploads.
 */
trait ReadsScannedDocuments
{
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $documentScans = [];

    /** Reads per user per hour: each one is a Gemini request on the Worker's daily quota. */
    protected int $documentReadsPerHour = 30;

    public function documentReaderEnabled(): bool
    {
        return app(DocumentExtractor::class)->isConfigured();
    }

    /** The attached ID card, read; null after an error it has already shown. */
    protected function readScannedIdCard(): ?ExtractedDocument
    {
        abort_unless($this->documentReaderEnabled(), 404);

        $this->validate([
            'documentScans' => ['required', 'array', 'max:'.DocumentExtractor::MAX_FILES],
            'documentScans.*' => ['file', 'mimetypes:'.implode(',', DocumentExtractor::MIMES), 'max:10240'],
        ], attributes: ['documentScans' => __('documents.reader.files'), 'documentScans.*' => __('documents.reader.files')]);

        $key = 'document-read:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $this->documentReadsPerHour)) {
            $this->addError('documentScans', __('documents.reader.too-many', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]));

            return null;
        }

        RateLimiter::hit($key, 3600);

        try {
            $document = app(DocumentExtractor::class)->identity($this->documentScans);
        } catch (DocumentExtractionException $e) {
            $this->addError('documentScans', $e->getMessage());

            return null;
        } finally {
            // the scans are not kept: Livewire's temporary files are cleaned up on their own
            $this->documentScans = [];
        }

        return $document;
    }
}
