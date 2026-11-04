<?php

namespace App\Services\News;

use App\Models\Employee;
use App\Models\News;
use App\Models\User;
use App\Support\News\NewsViewer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a viewer's feeds show. Every dashboard tile, the calendar, the
 * assistant and the News & Events page read through here, so draft,
 * scheduled, expired and audience rules are decided once, in SQL.
 *
 * Sections never overlap: an event is upcoming, today or past (by the app
 * timezone's calendar day), and the news section holds no events.
 */
class NewsFeed
{
    /** Live items the viewer is in the audience of. */
    public function live(User|Employee|NewsViewer|null $viewer, ?CarbonInterface $now = null): Builder
    {
        return News::query()
            ->live($now)
            ->visibleTo($viewer)
            ->with(['newsable', 'creator.profile', 'media']);
    }

    /** Released items (expired included), for the News & Events page and archive. */
    public function released(User|Employee|NewsViewer|null $viewer, ?CarbonInterface $now = null): Builder
    {
        return News::query()
            ->released($now)
            ->visibleTo($viewer)
            ->with(['newsable', 'creator.profile', 'media']);
    }

    /** News articles and announcements, newest publication first. */
    public function latestNews(User|Employee|NewsViewer|null $viewer, int $limit = 8, ?CarbonInterface $now = null): Collection
    {
        return $this->live($viewer, $now)
            ->articles()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    public function todayEvents(User|Employee|NewsViewer|null $viewer, ?CarbonInterface $now = null): Collection
    {
        return $this->live($viewer, $now)->todayEvents($now)->get();
    }

    /** Events after today, within $days days when given. */
    public function upcomingEvents(User|Employee|NewsViewer|null $viewer, ?int $days = null, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        return $this->live($viewer, $now)
            ->upcomingEvents($now)
            ->when($days !== null, fn (Builder $q) => $q->where('event_starts_at', '<=', $now->copy()->addDays($days)->endOfDay()))
            ->get();
    }

    /** Past events stay reachable on the News & Events page, expired or not. */
    public function pastEvents(User|Employee|NewsViewer|null $viewer, int $limit = 20, ?CarbonInterface $now = null): Collection
    {
        return $this->released($viewer, $now)->pastEvents($now)->limit($limit)->get();
    }

    /** Live events whose day falls in [$from, $to], for the calendar. */
    public function eventsBetween(User|Employee|NewsViewer|null $viewer, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $now = null): Collection
    {
        return $this->live($viewer, $now)
            ->ofType('event')
            ->whereNotNull('event_starts_at')
            ->where('event_starts_at', '<=', $to->copy()->endOfDay())
            ->where(fn (Builder $q) => $q
                ->where('event_starts_at', '>=', $from->copy()->startOfDay())
                ->orWhere('event_ends_at', '>=', $from->copy()->startOfDay()))
            ->orderBy('event_starts_at')
            ->get();
    }
}
