<?php

namespace Tests\Feature\News;

use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Models\News;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Draft / scheduled / published / expired / archived are derived from the
 * stored status and dates. NewsState::of() (PHP) and the live / released /
 * inState scopes (SQL) must always agree.
 */
class NewsLifecycleTest extends TestCase
{
    use CreatesNews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    }

    public function test_a_published_item_whose_publish_at_has_passed_is_live(): void
    {
        $news = $this->makeNews(['published_at' => now()->subDay()]);

        $this->assertTrue($this->isLive($news));
        $this->assertTrue($this->isReleased($news));
        $this->assertSame(NewsState::Published, $news->state());
        $this->assertSame(NewsState::Published, NewsState::of($news));
    }

    public function test_a_draft_is_neither_live_nor_released(): void
    {
        $news = $this->makeNews(['status' => NewsStatus::Draft, 'published_at' => now()->subDay()]);

        $this->assertFalse($this->isLive($news));
        $this->assertFalse($this->isReleased($news));
        $this->assertSame(NewsState::Draft, $news->state());
    }

    public function test_a_scheduled_item_is_not_live_until_its_publish_at(): void
    {
        $news = $this->makeNews(['published_at' => now()->addDays(2)]);

        $this->assertFalse($this->isLive($news));
        $this->assertFalse($this->isReleased($news));
        $this->assertSame(NewsState::Scheduled, $news->state());

        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:01'));

        $this->assertTrue($this->isLive($news));
        $this->assertTrue($this->isReleased($news));
        $this->assertSame(NewsState::Published, $news->fresh()->state());
    }

    public function test_a_published_item_without_a_publish_at_counts_as_scheduled(): void
    {
        $news = $this->makeNews(['published_at' => null]);

        $this->assertFalse($this->isLive($news));
        $this->assertFalse($this->isReleased($news));
        $this->assertSame(NewsState::Scheduled, $news->state());
    }

    public function test_an_expired_item_is_not_live_but_is_still_released(): void
    {
        $news = $this->makeNews(['published_at' => now()->subDays(5), 'display_until' => now()->subDay()]);

        $this->assertFalse($this->isLive($news));
        $this->assertTrue($this->isReleased($news));
        $this->assertSame(NewsState::Expired, $news->state());
    }

    public function test_an_item_expiring_later_is_live_until_then(): void
    {
        $news = $this->makeNews(['published_at' => now()->subDay(), 'display_until' => now()->addDay()]);

        $this->assertTrue($this->isLive($news));
        $this->assertSame(NewsState::Published, $news->state());

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));

        $this->assertFalse($this->isLive($news));
        $this->assertTrue($this->isReleased($news));
        $this->assertSame(NewsState::Expired, $news->fresh()->state());
    }

    public function test_an_archived_item_is_neither_live_nor_released(): void
    {
        $news = $this->makeNews([
            'status' => NewsStatus::Archived,
            'published_at' => now()->subDays(3),
            'archived_at' => now()->subDay(),
        ]);

        $this->assertFalse($this->isLive($news));
        $this->assertFalse($this->isReleased($news));
        $this->assertSame(NewsState::Archived, $news->state());
    }

    public function test_status_wins_over_dates_for_draft_and_archived(): void
    {
        $futureDraft = $this->makeNews(['status' => NewsStatus::Draft, 'published_at' => now()->addDay()]);
        $expiredArchive = $this->makeNews([
            'status' => NewsStatus::Archived,
            'published_at' => now()->subDays(5),
            'display_until' => now()->subDay(),
        ]);

        $this->assertSame(NewsState::Draft, $futureDraft->state());
        $this->assertSame(NewsState::Archived, $expiredArchive->state());
    }

    public function test_the_in_state_scope_matches_news_state_of_for_a_mixed_set(): void
    {
        $this->makeNews(['status' => NewsStatus::Draft, 'published_at' => null]);
        $this->makeNews(['status' => NewsStatus::Draft, 'published_at' => now()->subDay()]);
        $this->makeNews(['published_at' => null]);
        $this->makeNews(['published_at' => now()->addHour()]);
        $this->makeNews(['published_at' => now()->subDay()]);
        // Boundaries: Publish At exactly now is published; Display Until exactly now is expired.
        $this->makeNews(['published_at' => now()]);
        $this->makeNews(['published_at' => now()->subDay(), 'display_until' => now()]);
        $this->makeNews(['published_at' => now()->subDay(), 'display_until' => now()->addDay()]);
        $this->makeNews(['published_at' => now()->subDays(4), 'display_until' => now()->subDays(2)]);
        $this->makeNews(['status' => NewsStatus::Archived, 'published_at' => now()->subDay()]);
        $this->makeEvent(now()->addDays(3), null, ['published_at' => now()->addDay()]);
        $this->makeEvent(now()->subDays(3), null, ['published_at' => now()->subDays(10), 'display_until' => now()->subDays(2)]);

        $all = News::query()->get();
        $seen = [];

        foreach (NewsState::cases() as $state) {
            $expected = $all->filter(fn (News $news) => NewsState::of($news) === $state)->pluck('id')->sort()->values()->all();
            $actual = News::query()->inState($state)->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $actual, 'inState('.$state->value.') disagrees with NewsState::of');
            // The string form is accepted too.
            $this->assertSame($expected, News::query()->inState($state->value)->pluck('id')->sort()->values()->all());

            $seen = array_merge($seen, $actual);
        }

        // Every item lands in exactly one state, and every state is exercised.
        $this->assertSame($all->pluck('id')->sort()->values()->all(), collect($seen)->sort()->values()->all());
        $this->assertSame(
            collect(NewsState::cases())->map->value->sort()->values()->all(),
            $all->map(fn (News $news) => $news->state()->value)->unique()->sort()->values()->all(),
        );
    }

    public function test_live_is_published_state_and_released_is_published_or_expired(): void
    {
        $this->makeNews(['status' => NewsStatus::Draft]);
        $this->makeNews(['published_at' => now()->addDay()]);
        $this->makeNews();
        $this->makeNews(['published_at' => now()->subDays(4), 'display_until' => now()->subDay()]);
        $this->makeNews(['status' => NewsStatus::Archived]);

        $live = News::query()->live()->pluck('id')->sort()->values()->all();
        $released = News::query()->released()->pluck('id')->sort()->values()->all();

        $this->assertSame(News::query()->inState(NewsState::Published)->pluck('id')->sort()->values()->all(), $live);
        $this->assertSame(
            News::query()->inState(NewsState::Published)->pluck('id')
                ->merge(News::query()->inState(NewsState::Expired)->pluck('id'))->sort()->values()->all(),
            $released,
        );
    }

    public function test_the_scopes_accept_an_explicit_now(): void
    {
        $news = $this->makeNews(['published_at' => now()->addDays(2)]);
        $later = CarbonImmutable::parse('2026-11-07 00:00:00');

        $this->assertFalse(News::query()->whereKey($news->id)->live()->exists());
        $this->assertTrue(News::query()->whereKey($news->id)->live($later)->exists());
        $this->assertTrue(News::query()->whereKey($news->id)->inState(NewsState::Published, $later)->exists());
        $this->assertSame(NewsState::Published, $news->state($later));
    }

    private function isLive(News $news): bool
    {
        return News::query()->whereKey($news->id)->live()->exists();
    }

    private function isReleased(News $news): bool
    {
        return News::query()->whereKey($news->id)->released()->exists();
    }
}
