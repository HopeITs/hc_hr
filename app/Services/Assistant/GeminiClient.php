<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The one place that talks to the Gemini API (generateContent).
 *
 * Contents use Gemini's own shape: a list of
 * ['role' => 'user'|'model', 'parts' => [['text' => ...] | ['functionCall' => ...] | ['functionResponse' => ...]]].
 * A model turn that called a tool must be sent back exactly as it came,
 * because newer models attach a thought signature to it.
 */
class GeminiClient
{
    public function isConfigured(): bool
    {
        return filled(config('assistant.gemini.key'));
    }

    /**
     * One model turn: the candidate's `content` (role + parts).
     *
     * @param  list<array<string, mixed>>  $contents
     * @param  list<array<string, mixed>>  $functionDeclarations
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    public function generate(array $contents, ?string $system = null, array $functionDeclarations = [], float $temperature = 0.2, ?int $timeout = null): array
    {
        if (! $this->isConfigured()) {
            throw new GeminiException('GEMINI_API_KEY is not set.');
        }

        $body = array_filter([
            'systemInstruction' => $system !== null ? ['parts' => [['text' => $system]]] : null,
            'contents' => $contents,
            'tools' => $functionDeclarations ? [['functionDeclarations' => $functionDeclarations]] : null,
            'generationConfig' => ['temperature' => $temperature],
        ]);

        $url = rtrim(config('assistant.gemini.base_url'), '/').'/models/'.config('assistant.gemini.model').':generateContent';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => config('assistant.gemini.key')])
                ->timeout($timeout ?? config('assistant.gemini.timeout', 60))
                ->acceptJson()
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new GeminiException('Gemini is unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new GeminiException('Gemini answered '.$response->status().': '.$response->json('error.message', ''), $response->status());
        }

        $content = $response->json('candidates.0.content');

        if (! is_array($content) || empty($content['parts'])) {
            throw new GeminiException('Gemini returned no answer ('.$response->json('candidates.0.finishReason', $response->json('promptFeedback.blockReason', 'empty')).').');
        }

        return ['role' => $content['role'] ?? 'model', 'parts' => $content['parts']];
    }

    /** The text parts of a model turn, joined. */
    public static function text(array $content): string
    {
        return trim(collect($content['parts'] ?? [])
            ->reject(fn (array $part) => $part['thought'] ?? false)
            ->pluck('text')
            ->filter()
            ->implode(''));
    }

    /** @return list<array{name: string, args: array<string, mixed>}> */
    public static function functionCalls(array $content): array
    {
        return collect($content['parts'] ?? [])
            ->pluck('functionCall')
            ->filter()
            ->map(fn (array $call) => ['name' => $call['name'], 'args' => $call['args'] ?? []])
            ->values()
            ->all();
    }

    /** The full text of a document (PDF and other types Gemini reads), Arabic included. */
    public function transcribe(string $bytes, string $mimeType): string
    {
        $content = $this->generate([[
            'role' => 'user',
            'parts' => [
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($bytes)]],
                ['text' => 'Transcribe the complete text of this document in its original language, in reading order. Keep headings, numbered clauses and tables (as plain text rows). Output only the text, with no commentary.'],
            ],
        ]], temperature: 0.0, timeout: config('assistant.gemini.transcribe_timeout', 600));

        return self::text($content);
    }
}
