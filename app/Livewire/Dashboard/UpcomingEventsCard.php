<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\News;
use App\Services\News\NewsFeed;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Live events after today (config news.upcoming_events_days ahead) whose
 * audience includes the viewer (NewsFeed::upcomingEvents). Today's events
 * are the today tile's, so the two never list the same item.
 */
class UpcomingEventsCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.upcoming-events-card', [
            'upcomingEvents' => $this->guardedLoad(fn () => $this->loadUpcomingEvents(), collect()),
        ]);
    }

    protected function loadUpcomingEvents(): Collection
    {
        $tz = config('app.timezone');
        $today = now()->timezone($tz)->startOfDay();

        return app(NewsFeed::class)
            ->upcomingEvents(auth()->user(), (int) config('news.upcoming_events_days', 30))
            ->map(function (News $news) use ($tz, $today) {
                $starts = $news->event_starts_at->copy()->timezone($tz);
                // Calendar days between today and the event's day, not 24h periods.
                $daysLeft = (int) $today->diffInDays($starts->copy()->startOfDay(), false);

                $news->event_title = $news->localizedTitle();
                $news->event_description = $news->localizedDescription();
                $news->event_timestamp = $starts;
                $news->event_has_time = $starts->format('H:i') !== '00:00';
                $news->image_url = $news->coverUrl() ?: '/images/news-default.png';
                $news->daysLeft = $daysLeft;
                $news->daysLeftText = trans_choice('dashboard.in-days', $daysLeft);
                $news->url = route('news.show', $news);

                return $news;
            })
            ->sortBy('event_timestamp')
            ->values();
    }
}
