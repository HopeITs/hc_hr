<?php

namespace Tests\Feature\News;

use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Models\News;
use App\Services\News\NewsFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Upcoming / today / past are decided by the app timezone's calendar day
 * (Europe/Istanbul), in SQL, and never overlap. News articles and
 * announcements are never events, whatever dates they carry.
 */
class NewsEventCategorizationTest extends TestCase
{
    use CreatesNews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 local on Wednesday 4 November 2026.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    }

    public function test_the_clock_is_the_app_timezone(): void
    {
        $this->assertSame(config('app.timezone'), now()->getTimezone()->getName());
    }

    public function test_an_event_tomorrow_is_upcoming(): void
    {
        $event = $this->makeEvent('2026-11-05 09:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame([], $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
        $this->assertSame('upcoming', $event->eventTiming());
    }

    public function test_an_event_later_today_is_today(): void
    {
        $event = $this->makeEvent('2026-11-04 18:00:00', '2026-11-04 20:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
        $this->assertSame('today', $event->eventTiming());
    }

    public function test_an_event_that_already_finished_earlier_today_is_still_today(): void
    {
        $withEnd = $this->makeEvent('2026-11-04 07:00:00', '2026-11-04 09:00:00');
        $withoutEnd = $this->makeEvent('2026-11-04 08:00:00');

        $this->assertSame($this->sorted([$withEnd->id, $withoutEnd->id]), $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
        $this->assertSame('today', $withEnd->eventTiming());
        $this->assertSame('today', $withoutEnd->eventTiming());
    }

    public function test_a_multi_day_event_running_across_today_is_today(): void
    {
        $event = $this->makeEvent('2026-11-03 09:00:00', '2026-11-05 17:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
        $this->assertSame('today', $event->eventTiming());
    }

    public function test_a_multi_day_event_ending_today_is_today(): void
    {
        $event = $this->makeEvent('2026-11-02 09:00:00', '2026-11-04 08:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->todayEvents()));
        $this->assertSame('today', $event->eventTiming());
    }

    public function test_an_event_yesterday_is_past(): void
    {
        $event = $this->makeEvent('2026-11-03 09:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->pastEvents()));
        $this->assertSame([], $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame('past', $event->eventTiming());
    }

    public function test_a_multi_day_event_that_ended_yesterday_is_past(): void
    {
        $event = $this->makeEvent('2026-11-01 09:00:00', '2026-11-03 23:59:00');

        $this->assertSame([$event->id], $this->ids(News::query()->pastEvents()));
        $this->assertSame([], $this->ids(News::query()->todayEvents()));
        $this->assertSame('past', $event->eventTiming());
    }

    public function test_the_day_boundary_is_local_midnight_not_utc(): void
    {
        // 23:30 local is 20:30 UTC on the same day; 01:00 local tomorrow is
        // 22:00 UTC today. Only the local calendar day may decide.
        $lateTonight = $this->makeEvent(Carbon::parse('2026-11-04 23:30:00', config('app.timezone')));
        $earlyTomorrow = $this->makeEvent(Carbon::parse('2026-11-05 01:00:00', config('app.timezone')));
        // 00:30 local today is still 3 November in UTC.
        $justAfterMidnight = $this->makeEvent(Carbon::parse('2026-11-04 00:30:00', config('app.timezone')));

        $this->assertSame($this->sorted([$justAfterMidnight->id, $lateTonight->id]), $this->ids(News::query()->todayEvents()));
        $this->assertSame([$earlyTomorrow->id], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
        $this->assertSame('today', $lateTonight->eventTiming());
        $this->assertSame('today', $justAfterMidnight->eventTiming());
        $this->assertSame('upcoming', $earlyTomorrow->eventTiming());
    }

    public function test_sections_never_overlap_and_event_timing_agrees_with_the_scopes(): void
    {
        $events = collect([
            $this->makeEvent('2026-11-05 09:00:00'),
            $this->makeEvent('2026-11-20 09:00:00', '2026-11-22 18:00:00'),
            $this->makeEvent('2026-11-04 18:00:00'),
            $this->makeEvent('2026-11-04 07:00:00', '2026-11-04 08:00:00'),
            $this->makeEvent('2026-11-03 09:00:00', '2026-11-05 17:00:00'),
            $this->makeEvent('2026-11-03 09:00:00'),
            $this->makeEvent('2026-11-01 09:00:00', '2026-11-03 17:00:00'),
            $this->makeEvent('2026-10-01 09:00:00'),
        ]);

        $sections = [
            'upcoming' => $this->ids(News::query()->upcomingEvents()),
            'today' => $this->ids(News::query()->todayEvents()),
            'past' => $this->ids(News::query()->pastEvents()),
        ];

        $this->assertSame([], array_values(array_intersect($sections['upcoming'], $sections['today'])));
        $this->assertSame([], array_values(array_intersect($sections['upcoming'], $sections['past'])));
        $this->assertSame([], array_values(array_intersect($sections['today'], $sections['past'])));
        // Together they hold every event exactly once.
        $this->assertSame($this->sorted($events->pluck('id')->all()), $this->sorted(array_merge(...array_values($sections))));

        foreach ($events as $event) {
            $timing = $event->eventTiming();

            $this->assertContains($event->id, $sections[$timing], "Event {$event->id} ({$event->event_starts_at}) is '{$timing}' in PHP but not in that scope");
        }

        $this->assertCount(2, $sections['upcoming']);
        $this->assertCount(3, $sections['today']);
        $this->assertCount(3, $sections['past']);
    }

    public function test_the_scopes_order_upcoming_and_today_ascending_and_past_descending(): void
    {
        $far = $this->makeEvent('2026-11-20 09:00:00');
        $near = $this->makeEvent('2026-11-05 09:00:00');
        $evening = $this->makeEvent('2026-11-04 18:00:00');
        $morning = $this->makeEvent('2026-11-04 08:00:00');
        $old = $this->makeEvent('2026-10-01 09:00:00');
        $recent = $this->makeEvent('2026-11-03 09:00:00');

        $this->assertSame([$near->id, $far->id], News::query()->upcomingEvents()->pluck('id')->all());
        $this->assertSame([$morning->id, $evening->id], News::query()->todayEvents()->pluck('id')->all());
        $this->assertSame([$recent->id, $old->id], News::query()->pastEvents()->pluck('id')->all());
    }

    public function test_an_explicit_now_moves_the_sections(): void
    {
        $event = $this->makeEvent('2026-11-05 09:00:00');
        $tomorrow = CarbonImmutable::parse('2026-11-05 12:00:00');
        $dayAfter = CarbonImmutable::parse('2026-11-06 00:00:00');

        $this->assertSame([$event->id], $this->ids(News::query()->todayEvents($tomorrow)));
        $this->assertSame('today', $event->eventTiming($tomorrow));
        $this->assertSame([$event->id], $this->ids(News::query()->pastEvents($dayAfter)));
        $this->assertSame('past', $event->eventTiming($dayAfter));
    }

    public function test_news_and_announcements_never_appear_in_event_scopes(): void
    {
        // Even when they carry event-like dates (legacy rows did).
        $article = $this->makeNews([
            'type' => NewsType::News,
            'event_date' => '2026-11-04',
            'event_starts_at' => Carbon::parse('2026-11-04 12:00:00'),
        ]);
        $announcement = $this->makeNews([
            'type' => NewsType::Announcement,
            'event_starts_at' => Carbon::parse('2026-11-10 12:00:00'),
        ]);
        $oldArticle = $this->makeNews([
            'type' => NewsType::News,
            'event_starts_at' => Carbon::parse('2026-10-10 12:00:00'),
        ]);

        foreach (['upcomingEvents', 'todayEvents', 'pastEvents'] as $scope) {
            $this->assertSame([], $this->ids(News::query()->{$scope}()), "{$scope} returned a non-event");
        }

        $this->assertNull($article->eventTiming());
        $this->assertNull($announcement->eventTiming());
        $this->assertNull($oldArticle->eventTiming());
        $this->assertFalse($article->isEvent());
        $this->assertSame($this->sorted([$article->id, $announcement->id, $oldArticle->id]), $this->ids(News::query()->articles()));
    }

    public function test_an_event_without_a_start_has_no_timing(): void
    {
        $event = $this->makeNews(['type' => NewsType::Event, 'event_starts_at' => null]);

        $this->assertTrue($event->isEvent());
        $this->assertNull($event->eventTiming());
        $this->assertSame([], $this->ids(News::query()->upcomingEvents()));
        $this->assertSame([], $this->ids(News::query()->todayEvents()));
        $this->assertSame([], $this->ids(News::query()->pastEvents()));
    }

    public function test_saving_an_event_syncs_event_date_to_its_local_day(): void
    {
        $event = $this->makeEvent(Carbon::parse('2026-11-04 23:30:00', config('app.timezone')));

        $this->assertSame('2026-11-04', $event->event_date->toDateString());
    }

    public function test_latest_news_excludes_events(): void
    {
        $article = $this->makeNews(['type' => NewsType::News, 'published_at' => now()->subHours(3)]);
        $announcement = $this->makeNews(['type' => NewsType::Announcement, 'published_at' => now()->subHours(2)]);
        $this->makeEvent('2026-11-04 18:00:00', null, ['published_at' => now()->subHour()]);
        $this->makeEvent('2026-11-10 18:00:00', null, ['published_at' => now()->subMinutes(30)]);
        // Not live: never in the feed.
        $this->makeNews(['status' => NewsStatus::Draft]);
        $this->makeNews(['published_at' => now()->addDay()]);
        $this->makeNews(['published_at' => now()->subDays(3), 'display_until' => now()->subDay()]);

        $latest = app(NewsFeed::class)->latestNews(null);

        // Newest publication first.
        $this->assertSame([$announcement->id, $article->id], $latest->pluck('id')->all());
    }

    public function test_the_feed_sections_hold_only_events_and_never_overlap(): void
    {
        $upcoming = $this->makeEvent('2026-11-06 09:00:00');
        $today = $this->makeEvent('2026-11-04 15:00:00');
        $past = $this->makeEvent('2026-11-02 09:00:00');
        // A past event that has expired from active feeds stays in the archive.
        $expiredPast = $this->makeEvent('2026-10-20 09:00:00', null, ['published_at' => now()->subDays(20), 'display_until' => now()->subDays(10)]);
        $this->makeNews();
        $this->makeNews(['type' => NewsType::Announcement]);

        $feed = app(NewsFeed::class);

        $this->assertSame([$upcoming->id], $feed->upcomingEvents(null)->pluck('id')->all());
        $this->assertSame([$today->id], $feed->todayEvents(null)->pluck('id')->all());
        $this->assertSame([$past->id, $expiredPast->id], $feed->pastEvents(null)->pluck('id')->all());
        // Within a window: two days from now includes the 6th, one day does not.
        $this->assertSame([$upcoming->id], $feed->upcomingEvents(null, 2)->pluck('id')->all());
        $this->assertSame([], $feed->upcomingEvents(null, 1)->pluck('id')->all());
    }

    /** @return list<int> */
    private function ids($query): array
    {
        return $this->sorted($query->pluck('news.id')->all());
    }

    /** @param  list<int>  $ids @return list<int> */
    private function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }
}
