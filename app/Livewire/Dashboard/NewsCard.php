<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Services\News\NewsFeed;
use Livewire\Component;

/**
 * The latest live news and announcements the viewer is in the audience of,
 * newest publication first. Events have their own tiles; drafts, scheduled
 * and expired items never reach a feed (NewsFeed).
 */
class NewsCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard.news-card', [
            'newsItems' => $this->guardedLoad(
                fn () => app(NewsFeed::class)->latestNews($user, (int) config('news.dashboard_news_limit', 8)),
                collect(),
            ),
            'canViewAll' => (bool) $user?->hasActivePermission('news.view'),
        ]);
    }
}
