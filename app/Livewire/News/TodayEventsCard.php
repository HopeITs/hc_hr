<?php

namespace App\Livewire\News;

use App\Models\Employee;
use App\Models\News;
use Carbon\Carbon;
use Livewire\Component;

class TodayEventsCard extends Component
{
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
                'message' => 'Happy Birthday! 🎉',
                'photo' => $employee->avatar_url
                    // Local placeholder: the previous ui-avatars.com fallback
                    // sent every employee's name to a third-party host.
                    ?: asset('images/default-avatar.png'),
            ]);

        $newsEvents = News::whereDate('event_date', $today)
            ->latest()
            ->get()
            ->map(fn ($news) => [
                'type' => 'event',
                'name' => $news->title,
                'position' => $news->newsable_name ?? '',
                'department' => '',
                'message' => strip_tags($news->description ?? ''),
                'photo' => $news->getFirstMediaUrl('news-image') ?: '/assets/icons/event.svg',
            ]);

        $this->events = $birthdays->concat($newsEvents)->toArray();
    }

    public function render()
    {
        return view('components.dashboard-cards.today-events-card');
    }
}
