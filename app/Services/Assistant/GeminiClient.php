<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * The one place that talks to the Gemini API (generateContent).
 *
 * Contents use Gemini's own shape: a list of
 * ['role' => 'user'|'model', 'parts' => [['text' => ...] | ['functionCall' => ...] | ['functionResponse' => ...]]].
 * A model turn that called a tool must be sent back exactly as it came,
 * because newer models attach a thought signature to it.
 */
class GeminiClient implements ChatModel
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
        $turn = $this->turn($contents, $system, $functionDeclarations, $temperature, $timeout);
        unset($turn['finishReason']);

        return $turn;
    }

    /** The text parts of a model turn, joined. */
    public static function text(array $content, bool $trim = true): string
    {
        $text = collect($content['parts'] ?? [])
            ->reject(fn (array $part) => $part['thought'] ?? false)
            ->pluck('text')
            ->filter()
            ->implode('');

        return $trim ? trim($text) : $text;
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

    /**
     * The full text of a document (PDF and other types Gemini reads), Arabic
     * included. A long document runs past the answer's token limit: the
     * model is then asked to go on from where it stopped, so a policy never
     * comes back cut short. Any other stop (a refusal, a recitation block)
     * fails loudly instead of keeping half a text.
     *
     * @throws GeminiException
     */
    public function transcribe(string $bytes, string $mimeType, ?string $instruction = null): string
    {
        $contents = [[
            'role' => 'user',
            'parts' => [
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($bytes)]],
                ['text' => $instruction ?? 'Transcribe the complete text of this document in its original language, in reading order. Keep headings, numbered clauses and tables (as plain text rows). Output only the text, with no commentary.'],
            ],
        ]];

        $text = '';
        $rounds = max(1, (int) config('assistant.gemini.transcribe_rounds', 8));

        for ($round = 1; $round <= $rounds; $round++) {
            $turn = $this->turn(
                $contents, null, [], 0.0,
                (int) config('assistant.gemini.transcribe_timeout', 600),
                attempts: max(1, (int) config('assistant.gemini.transcribe_attempts', 3)),
                maxOutputTokens: (int) config('assistant.gemini.transcribe_max_tokens', 65536),
            );

            // Untrimmed: a part may end or start in the middle of a sentence,
            // and the space between two words is then at its edge.
            $text .= self::text($turn, trim: false);

            if ($turn['finishReason'] === 'STOP') {
                return trim($text);
            }

            if ($turn['finishReason'] !== 'MAX_TOKENS') {
                throw new GeminiException('Gemini stopped reading the document ('.$turn['finishReason'].').', 422);
            }

            unset($turn['finishReason']);
            $contents[] = $turn;
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Continue the transcription exactly where you stopped, with no repetition and no commentary.']]];
        }

        throw new GeminiException('The document is too long to transcribe in '.$rounds.' parts.', 413);
    }

    /**
     * generate(), plus why the model stopped (STOP, MAX_TOKENS, ...). Each
     * model is tried $attempts times, waiting a little longer each time,
     * before the next one in the list.
     *
     * @return array{role: string, parts: list<array<string, mixed>>, finishReason: string}
     */
    private function turn(array $contents, ?string $system, array $functionDeclarations, float $temperature, ?int $timeout, int $attempts = 1, ?int $maxOutputTokens = null): array
    {
        if (! $this->isConfigured()) {
            throw new GeminiException('GEMINI_API_KEY is not set.');
        }

        $body = array_filter([
            'systemInstruction' => $system !== null ? ['parts' => [['text' => $system]]] : null,
            'contents' => self::withObjects($contents),
            'tools' => $functionDeclarations ? [['functionDeclarations' => $functionDeclarations]] : null,
            'generationConfig' => array_filter(['temperature' => $temperature, 'maxOutputTokens' => $maxOutputTokens], fn ($v) => $v !== null),
        ]);

        // The free tier answers "high demand" (503) for minutes at a time, one
        // model at a time: the next model in the list usually still answers.
        $models = array_values(array_unique(array_filter([
            config('assistant.gemini.model'),
            ...(array) config('assistant.gemini.fallback_models', []),
        ])));

        foreach ($models as $i => $model) {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    return $this->request($model, $body, $timeout);
                } catch (GeminiException $e) {
                    $lastModel = $i === count($models) - 1;

                    if (! $e->isTemporary() || ($lastModel && $attempt === $attempts)) {
                        throw $e;
                    }

                    if ($attempt < $attempts) {
                        sleep((int) config('assistant.gemini.retry_wait', 3) * $attempt);
                    }
                }
            }
        }

        throw new GeminiException('No Gemini model is configured.');
    }

    /**
     * A call without arguments comes back as `"args": {}`, which PHP decodes
     * to [] and would send back as a JSON list; Gemini refuses that ("Proto
     * field is not repeating"). The same holds for an empty tool response.
     */
    private static function withObjects(array $contents): array
    {
        foreach ($contents as &$content) {
            foreach ($content['parts'] ?? [] as $i => $part) {
                if (isset($part['functionCall']) && empty($part['functionCall']['args'])) {
                    $content['parts'][$i]['functionCall']['args'] = new stdClass;
                }

                if (isset($part['functionResponse']) && empty($part['functionResponse']['response'])) {
                    $content['parts'][$i]['functionResponse']['response'] = new stdClass;
                }
            }
        }

        return $contents;
    }

    /** @return array{role: string, parts: list<array<string, mixed>>, finishReason: string} */
    private function request(string $model, array $body, ?int $timeout): array
    {
        $url = rtrim(config('assistant.gemini.base_url'), '/').'/models/'.$model.':generateContent';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => config('assistant.gemini.key')])
                ->timeout($timeout ?? config('assistant.gemini.timeout', 60))
                ->acceptJson()
                ->post($url, $body);
        } catch (ConnectionException $e) {
            // Reported as a gateway timeout so it counts as temporary.
            throw new GeminiException('Gemini is unreachable: '.$e->getMessage(), 504, $e);
        }

        if ($response->failed()) {
            throw new GeminiException('Gemini answered '.$response->status().': '.$response->json('error.message', ''), $response->status());
        }

        $content = $response->json('candidates.0.content');

        if (! is_array($content) || empty($content['parts'])) {
            throw new GeminiException('Gemini returned no answer ('.$response->json('candidates.0.finishReason', $response->json('promptFeedback.blockReason', 'empty')).').');
        }

        return [
            'role' => $content['role'] ?? 'model',
            'parts' => $content['parts'],
            'finishReason' => (string) $response->json('candidates.0.finishReason', 'STOP'),
        ];
    }
}
