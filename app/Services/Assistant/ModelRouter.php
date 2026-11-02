<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the configured providers in turn (config assistant.providers_order)
 * and returns the first answer.
 *
 * A provider that fails for a reason that will not go away in seconds
 * (over quota, overloaded, unreachable, "location not supported", bad key)
 * is put on a cooldown in the cache, so the next questions go straight to
 * one that answers instead of waiting on it again. Within one conversation
 * (one Assistant::reply) the provider that answered keeps answering the
 * following tool rounds, unless it fails.
 */
class ModelRouter implements ChatModel
{
    private const COOLDOWN_KEY = 'assistant:provider-cooldown:';

    /** The provider that answered last in this conversation. */
    private ?string $sticky = null;

    /** A copy with no provider chosen yet: one per reply. */
    public function conversation(): static
    {
        $copy = clone $this;
        $copy->sticky = null;

        return $copy;
    }

    public function isConfigured(): bool
    {
        return $this->configured() !== [];
    }

    /** The provider that answered last, if any. */
    public function current(): ?string
    {
        return $this->sticky;
    }

    public function generate(array $contents, ?string $system = null, array $functionDeclarations = [], float $temperature = 0.2, ?int $timeout = null): array
    {
        $configured = $this->configured();

        if ($configured === []) {
            throw new AssistantModelException('No AI provider is configured.');
        }

        $timeout ??= (int) config('assistant.chat_timeout', 25);
        $last = null;

        foreach ($this->candidates($configured) as $name) {
            $started = microtime(true);

            try {
                $turn = $this->client($name)->generate($contents, $system, $functionDeclarations, $temperature, $timeout);
            } catch (Throwable $e) {
                $last = $e;
                $this->failed($name, $e, $started);

                if ($this->sticky === $name) {
                    $this->sticky = null;
                }

                continue;
            }

            if ($this->sticky !== $name) {
                Log::info('Assistant: answered by '.$name, ['provider' => $name, 'ms' => $this->ms($started)]);
            }

            $this->sticky = $name;

            return $turn;
        }

        Log::warning('Assistant: no provider could answer', ['tried' => $configured]);

        // Temporary on purpose: every provider failing is "try again later".
        throw new AssistantModelException('No AI provider could answer: '.($last?->getMessage() ?? 'all are cooling down'), 503, $last);
    }

    /** @return list<string> configured providers, in the configured order */
    private function configured(): array
    {
        $order = config('assistant.providers_order', ['gemini']);
        $order = is_string($order) ? explode(',', $order) : (array) $order;

        return array_values(array_filter(
            array_unique(array_map(fn ($name) => strtolower(trim((string) $name)), $order)),
            fn (string $name) => $name !== '' && ($this->client($name)?->isConfigured() ?? false),
        ));
    }

    /**
     * The sticky provider first, then the rest without a cooldown. When all
     * of them are cooling down, the ones that were only busy are tried
     * anyway: answering late is better than not at all. Those refused for
     * this host or key are not.
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    private function candidates(array $configured): array
    {
        $ready = array_values(array_filter($configured, fn (string $name) => ! Cache::has(self::COOLDOWN_KEY.$name)));

        if ($this->sticky !== null && in_array($this->sticky, $ready, true)) {
            $ready = [$this->sticky, ...array_values(array_diff($ready, [$this->sticky]))];
        }

        if ($ready !== []) {
            return $ready;
        }

        return array_values(array_filter(
            $configured,
            fn (string $name) => Cache::get(self::COOLDOWN_KEY.$name) === 'temporary',
        ));
    }

    private function failed(string $name, Throwable $e, float $started): void
    {
        $code = (int) $e->getCode();

        [$reason, $seconds] = match (true) {
            $e instanceof GeminiException && $e->isUnavailableHere() => ['unavailable', (int) config('assistant.cooldown.unavailable', 1800)],
            $e instanceof GeminiException && $e->isTemporary() => ['temporary', (int) config('assistant.cooldown.temporary', 120)],
            default => ['error', 0],
        };

        if ($seconds > 0) {
            Cache::put(self::COOLDOWN_KEY.$name, $reason, $seconds);
        }

        // The provider's own error line only: it never holds the question.
        Log::warning('Assistant: '.$name.' failed', [
            'provider' => $name,
            'status' => $code,
            'reason' => $reason,
            'cooldown_seconds' => $seconds,
            'ms' => $this->ms($started),
            'error' => Str::limit($e->getMessage(), 200),
        ]);
    }

    private function client(string $name): ?ChatModel
    {
        $config = config('assistant.providers.'.$name);

        // Built on each use, so a config change applies at once.
        return match (true) {
            $name === 'gemini' => app(GeminiClient::class),
            is_array($config) => new OpenAiCompatibleClient($name, $config),
            default => null,
        };
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
