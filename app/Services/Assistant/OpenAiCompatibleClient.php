<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * A provider speaking the OpenAI `/chat/completions` dialect (Groq,
 * OpenRouter, Cerebras, Mistral, ...), behind the Gemini-shaped ChatModel.
 *
 * Gemini contents carry no call ids, so they are made up here from the
 * position of the call (stable across rounds, since earlier turns are sent
 * again unchanged) and matched to the function responses that follow.
 */
class OpenAiCompatibleClient implements ChatModel
{
    /**
     * Gemini 3 refuses a function-call turn without a thought signature. A
     * turn written by another provider has none, so it gets the placeholder
     * Gemini documents for exactly that case, in case Gemini answers the
     * next round. Every other provider ignores the field.
     */
    private const FOREIGN_SIGNATURE = 'skip_thought_signature_validator';

    /**
     * @param  array{name?: string, base_url?: string, key?: ?string, model?: ?string, fallback_models?: list<string>, timeout?: int, headers?: array<string, string>}  $config
     */
    public function __construct(private string $id, private array $config) {}

    public function name(): string
    {
        return $this->config['name'] ?? $this->id;
    }

    public function isConfigured(): bool
    {
        return filled($this->config['key'] ?? null)
            && filled($this->config['base_url'] ?? null)
            && $this->models() !== [];
    }

    public function generate(array $contents, ?string $system = null, array $functionDeclarations = [], float $temperature = 0.2, ?int $timeout = null): array
    {
        if (! $this->isConfigured()) {
            throw new AssistantModelException($this->name().' is not configured.', 0, provider: $this->id);
        }

        $body = array_filter([
            'messages' => self::messages($contents, $system),
            'tools' => $functionDeclarations ? self::tools($functionDeclarations) : null,
            'temperature' => $temperature,
        ], fn ($value) => $value !== null);

        $models = $this->models();

        foreach ($models as $i => $model) {
            try {
                return $this->request($model, $body, $timeout);
            } catch (AssistantModelException $e) {
                if (! $e->isTemporary() || $i === count($models) - 1) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Gemini contents as chat messages.
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(array $contents, ?string $system = null): array
    {
        $messages = $system !== null ? [['role' => 'system', 'content' => $system]] : [];

        // Ids of calls not answered yet, oldest first: [['id' => ..., 'name' => ...]].
        $pending = [];
        $round = 0;

        foreach ($contents as $content) {
            $parts = $content['parts'] ?? [];
            $text = trim(collect($parts)
                ->reject(fn (array $part) => $part['thought'] ?? false)
                ->pluck('text')
                ->filter()
                ->implode(''));

            if (($content['role'] ?? 'user') === 'model') {
                $calls = array_values(array_filter(array_column($parts, 'functionCall')));
                $message = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];

                if ($calls !== []) {
                    foreach ($calls as $i => $call) {
                        $id = self::callId($round, $i);
                        $pending[] = ['id' => $id, 'name' => $call['name']];
                        $message['tool_calls'][] = [
                            'id' => $id,
                            'type' => 'function',
                            'function' => [
                                'name' => $call['name'],
                                'arguments' => self::json($call['args'] ?? []),
                            ],
                        ];
                    }

                    $round++;
                } elseif ($text === '') {
                    continue;
                }

                $messages[] = $message;

                continue;
            }

            foreach ($parts as $part) {
                if (! isset($part['functionResponse'])) {
                    continue;
                }

                $response = $part['functionResponse'];
                $match = collect($pending)->search(fn (array $call) => $call['name'] === ($response['name'] ?? null));
                $match = $match === false ? (array_key_first($pending) ?? false) : $match;
                $id = $match === false ? self::callId($round++, 0) : $pending[$match]['id'];

                if ($match !== false) {
                    unset($pending[$match]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'content' => self::json($response['response'] ?? []),
                ];
            }

            if ($text !== '') {
                $messages[] = ['role' => 'user', 'content' => $text];
            }
        }

        return $messages;
    }

    /**
     * Gemini function declarations as chat-completions tools.
     *
     * @return list<array<string, mixed>>
     */
    public static function tools(array $functionDeclarations): array
    {
        return array_map(fn (array $declaration) => [
            'type' => 'function',
            'function' => [
                'name' => $declaration['name'],
                'description' => $declaration['description'] ?? '',
                'parameters' => $declaration['parameters'] ?? ['type' => 'object', 'properties' => new stdClass],
            ],
        ], $functionDeclarations);
    }

    /**
     * A chat-completions message as a Gemini model turn.
     *
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    public static function turn(array $message): array
    {
        $parts = [];
        $text = is_string($message['content'] ?? null) ? trim($message['content']) : '';

        if ($text !== '') {
            $parts[] = ['text' => $text];
        }

        foreach (array_values($message['tool_calls'] ?? []) as $i => $call) {
            $arguments = $call['function']['arguments'] ?? '{}';
            $args = is_array($arguments) ? $arguments : json_decode((string) $arguments, true);

            $part = ['functionCall' => ['name' => $call['function']['name'] ?? '', 'args' => is_array($args) ? $args : []]];

            if ($i === 0) {
                $part['thoughtSignature'] = self::FOREIGN_SIGNATURE;
            }

            $parts[] = $part;
        }

        // An empty answer stays an (empty) answer, as with Gemini: the
        // assistant replaces it with its own "don't know" line.
        return ['role' => 'model', 'parts' => $parts ?: [['text' => '']]];
    }

    /** @return list<string> */
    private function models(): array
    {
        return array_values(array_unique(array_filter([
            $this->config['model'] ?? null,
            ...(array) ($this->config['fallback_models'] ?? []),
        ])));
    }

    /** @return array{role: string, parts: list<array<string, mixed>>} */
    private function request(string $model, array $body, ?int $timeout): array
    {
        $url = rtrim($this->config['base_url'], '/').'/chat/completions';

        try {
            $response = Http::withToken($this->config['key'])
                ->withHeaders(array_filter((array) ($this->config['headers'] ?? [])))
                ->timeout($timeout ?? (int) ($this->config['timeout'] ?? 30))
                ->acceptJson()
                ->post($url, ['model' => $model] + $body);
        } catch (ConnectionException $e) {
            // Reported as a gateway timeout so it counts as temporary.
            throw new AssistantModelException($this->name().' is unreachable: '.$e->getMessage(), 504, $e, $this->id);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->json('message') ?? $response->json('detail') ?? '';

            throw new AssistantModelException(
                $this->name().' answered '.$response->status().': '.(is_string($detail) ? $detail : json_encode($detail)),
                $response->status(),
                provider: $this->id,
            );
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new AssistantModelException($this->name().' returned no answer ('.$response->json('choices.0.finish_reason', 'empty').').', 0, provider: $this->id);
        }

        return self::turn($message);
    }

    /**
     * Mistral accepts only 9 letters and digits as a call id; the others
     * accept anything, so every provider gets that form.
     */
    private static function callId(int $round, int $index): string
    {
        return sprintf('call%02d%03d', $round % 100, $index % 1000);
    }

    /** Always a JSON object, `{}` when empty, never a list. */
    private static function json(mixed $value): string
    {
        return json_encode(
            empty($value) ? new stdClass : $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
