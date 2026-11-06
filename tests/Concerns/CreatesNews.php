<?php

namespace Tests\Concerns;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Models\News;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * News & Events fixtures. News has no factory: these build rows the way the
 * editor stores them (forceCreate, because created_by is not fillable) and
 * read them back, so every assertion sees the stored, cast values.
 *
 * Defaults: a published news article, released an hour ago, for everyone.
 */
trait CreatesNews
{
    protected function makeNews(array $attributes = []): News
    {
        static $seq = 0;
        $seq++;

        return News::query()->forceCreate(array_merge([
            'type' => NewsType::News,
            'status' => NewsStatus::Published,
            'title_en' => 'News item '.$seq,
            'title_ar' => 'خبر '.$seq,
            'description_en' => '',
            'description_ar' => '',
            'published_at' => now()->subHour(),
            'audience_type' => AudienceType::All,
        ], $attributes))->refresh();
    }

    /** A published event; times are read in the app timezone. */
    protected function makeEvent(string|CarbonInterface $startsAt, string|CarbonInterface|null $endsAt = null, array $attributes = []): News
    {
        return $this->makeNews(array_merge([
            'type' => NewsType::Event,
            'event_starts_at' => $startsAt instanceof CarbonInterface ? $startsAt : Carbon::parse($startsAt),
            'event_ends_at' => $endsAt === null ? null : ($endsAt instanceof CarbonInterface ? $endsAt : Carbon::parse($endsAt)),
        ], $attributes));
    }

    /** @param  list<int>  $ids */
    protected function makeNewsFor(AudienceType $audience, array $ids, array $attributes = []): News
    {
        $news = $this->makeNews($attributes);
        $news->syncAudience($audience, $ids);

        return $news->refresh();
    }
}
