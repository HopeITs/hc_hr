<?php

namespace App\Services\Assistant;

/**
 * A chat model with function calling. Contents and the returned turn use
 * Gemini's shape whatever the provider underneath: a list of
 * ['role' => 'user'|'model', 'parts' => [['text' => ...] | ['functionCall' => ...] | ['functionResponse' => ...]]].
 */
interface ChatModel
{
    /**
     * One model turn.
     *
     * @param  list<array<string, mixed>>  $contents
     * @param  list<array<string, mixed>>  $functionDeclarations
     * @return array{role: string, parts: list<array<string, mixed>>}
     *
     * @throws GeminiException
     */
    public function generate(array $contents, ?string $system = null, array $functionDeclarations = [], float $temperature = 0.2, ?int $timeout = null): array;

    public function isConfigured(): bool;
}
