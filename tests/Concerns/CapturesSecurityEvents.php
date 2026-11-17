<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * Points SecurityMonitor at an in-memory Monolog handler so a test can read
 * back exactly what monitoring wrote. phpunit.xml sends the real channel to
 * `null`, so nothing else in the suite touches a log file.
 */
trait CapturesSecurityEvents
{
    protected function captureSecurityEvents(): void
    {
        // Dedupe and burst counters live in the cache; a previous test's must not leak in.
        Cache::flush();

        config([
            'logging.channels.security_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'recruitment-privacy.monitoring.channel' => 'security_capture',
        ]);

        Log::forgetChannel('security_capture');
    }

    /** @return array<int, array{event: string|null, level: string, context: array<string, mixed>}> */
    protected function securityEvents(?string $event = null): array
    {
        $handler = collect(Log::channel('security_capture')->getLogger()->getHandlers())
            ->first(fn ($candidate): bool => $candidate instanceof TestHandler);

        return collect($handler->getRecords())
            ->map(fn ($record): array => [
                'event' => $record->context['event'] ?? null,
                'level' => strtolower($record->level->getName()),
                'context' => $record->context,
            ])
            ->when($event !== null, fn ($events) => $events->where('event', $event))
            ->values()
            ->all();
    }
}
