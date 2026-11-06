<?php

namespace App\Services\News\Publishing;

use App\Models\News;
use App\Models\NewsExternalPublication;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The seam for publishing news to external platforms. With no publisher
 * configured (today) available() is empty and the UI shows the capability
 * as not yet available; nothing pretends to publish.
 */
class ExternalPublishing
{
    public const MAX_ATTEMPTS = 5;

    /** @return Collection<int, ExternalPublisher> */
    public function available(): Collection
    {
        return collect(config('news.external_publishers', []))
            ->map(fn (string $class) => app($class))
            ->filter(fn ($publisher) => $publisher instanceof ExternalPublisher && $publisher->isConfigured())
            ->values();
    }

    public function isAvailable(): bool
    {
        return $this->available()->isNotEmpty();
    }

    /** Publish (or retry) $news on $platform, recording the outcome. */
    public function publish(News $news, string $platform): NewsExternalPublication
    {
        $publisher = $this->available()->first(fn (ExternalPublisher $p) => $p->platform() === $platform);

        abort_if($publisher === null, 422, 'This platform is not configured.');

        $record = NewsExternalPublication::query()->firstOrNew(['news_id' => $news->getKey(), 'platform' => $platform]);

        if ($record->status === NewsExternalPublication::STATUS_PUBLISHED || $record->attempts >= self::MAX_ATTEMPTS) {
            return $record;
        }

        $record->attempts++;
        $record->last_attempt_at = now();

        try {
            $result = $publisher->publish($news);
            $record->fill([
                'status' => NewsExternalPublication::STATUS_PUBLISHED,
                'external_id' => $result['external_id'],
                'external_url' => $result['external_url'] ?? null,
                'published_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $record->fill(['status' => NewsExternalPublication::STATUS_FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        $record->save();

        return $record;
    }
}
