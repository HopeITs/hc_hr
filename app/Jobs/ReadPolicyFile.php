<?php

namespace App\Jobs;

use App\Models\PolicyDocument;
use App\Services\Assistant\GeminiException;
use App\Services\Assistant\PolicyFileReader;
use App\Services\PolicyAcknowledgements;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads a policy's file into its text, in the background: a PDF goes through
 * Gemini and takes minutes. A busy Gemini is tried again later (tries/backoff);
 * any other failure leaves the policy "failed" with the reason HR is shown.
 *
 * The text is only swapped in when the reading succeeded, so a replaced policy
 * stays readable meanwhile. A new version moves source_modified_at, which asks
 * everyone who had agreed to agree again, and tells them.
 */
class ReadPolicyFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 1800;

    public function __construct(public int $documentId, public bool $newVersion = false) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(PolicyFileReader $reader, PolicyAcknowledgements $acknowledgements): void
    {
        $document = PolicyDocument::query()->with('media')->find($this->documentId);

        if ($document === null || $document->media === null) {
            return;
        }

        try {
            $text = $reader->read($document->media->getPath(), (string) $document->media->extension);
        } catch (GeminiException $e) {
            if ($e->isTemporary() && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 1800);

                return;
            }

            $this->fail($document, $e, $e->isUnavailableHere() || ! filled(config('assistant.gemini.key')) ? 'unavailable' : ($e->isTemporary() ? 'busy' : 'unreadable'));

            return;
        } catch (Throwable $e) {
            $this->fail($document, $e, 'unreadable');

            return;
        }

        $document->forceFill([
            'content' => $text,
            'read_status' => PolicyDocument::READY,
            'read_error' => null,
            'source_modified_at' => now(),
        ])->save();

        if ($this->newVersion) {
            $acknowledgements->notifyNewVersion($document);
        }
    }

    private function fail(PolicyDocument $document, Throwable $e, string $reason): void
    {
        report($e);

        $document->forceFill(['read_status' => PolicyDocument::FAILED, 'read_error' => $reason])->save();
    }
}
