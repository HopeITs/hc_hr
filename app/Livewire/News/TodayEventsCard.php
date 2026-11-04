<?php

namespace App\Livewire\News;

use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Employee;
use App\Models\News;
use App\Services\News\NewsFeed;
use Carbon\Carbon;
use Livewire\Component;

class TodayEventsCard extends Component
{
    use IsDashboardTile;

    public array $events = [];

    public function mount(): void
    {
        $this->loadEvents();
    }

    public function loadEvents(): void
    {
        $today = Carbon::today();

        $birthdays = Employee::whereNotNull('actual_dob')
            ->get()
            ->filter(fn ($e) => optional($e->actual_dob)->format('m-d') === $today->format('m-d'))
            ->map(fn ($employee) => [
                'type' => 'birthday',
                'name' => $employee->name,
                'position' => $employee->latestActiveContract()?->position?->title ?? '',
                'department' => $employee->latestActiveContract()?->position?->department?->name ?? '',
                'message' => __('dashboard.happy-birthday').' 🎉',
                'photo' => $employee->avatar_url
                    // Local placeholder: the previous ui-avatars.com fallback
                    // sent every employee's name to a third-party host.
                    ?: asset('images/default-avatar.png'),
            ]);

        // Live events taking place today whose audience includes the
        // viewer (NewsFeed): drafts, scheduled, expired items and other
        // audiences never reach this tile.
        $newsEvents = app(NewsFeed::class)->todayEvents(auth()->user())
            ->map(fn (News $news) => [
                'type' => 'event',
                'name' => $news->localizedTitle(),
                // The first tag says what the event is about; no tag, no line.
                'position' => $news->tagNames()[0] ?? '',
                'department' => '',
                'message' => strip_tags($news->localizedDescription() ?? ''),
                // An event without a cover shows no image at all.
                'photo' => $news->coverUrl(),
                'url' => route('news.show', $news),
            ]);

        $this->events = $birthdays->concat($newsEvents)->toArray();
    }

    public function render()
    {
        return view('components.dashboard-cards.today-events-card');
    }
}
