<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Center;
use App\Models\City;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Events of the next seven days meant for the viewer: for everyone, or for
 * their own record, city, center or program (it was a section of
 * Dashboard\Index).
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
        [$employee, $contract] = $this->tileViewer();

        $start = now()->timezone(config('app.timezone'))->addDay()->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        $employeeId = $employee->id ?? null;
        $cityId = $employee->city_id ?? null;
        $centerId = $contract->position->center_id ?? null;
        $programId = $contract->position->program_id ?? null;

        return News::query()
            ->whereBetween('event_date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($cityId, $centerId, $programId, $employeeId) {
                $q->whereNull('newsable_type')
                    ->orWhere(function ($q2) use ($employeeId) {
                        $q2->where('newsable_type', Employee::class)->where('newsable_id', $employeeId);
                    })
                    ->orWhere(function ($q2) use ($cityId) {
                        $q2->where('newsable_type', City::class)->where('newsable_id', $cityId);
                    })
                    ->orWhere(function ($q2) use ($centerId) {
                        $q2->where('newsable_type', Center::class)->where('newsable_id', $centerId);
                    })
                    ->orWhere(function ($q2) use ($programId) {
                        $q2->where('newsable_type', Program::class)->where('newsable_id', $programId);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('display_until')->orWhere('display_until', '>=', now()->startOfDay());
            })
            ->get()
            ->map(function ($news) {
                $timestamp = $news->event_date;
                $daysLeft = now()->diffInDays($timestamp, false);

                $news->event_title = $news->title;
                $news->event_description = $news->description;
                $news->event_timestamp = $timestamp;
                $news->image_url = $news->getFirstMediaUrl('news-image') ?: '/images/news-default.png';
                $news->daysLeftText = trans_choice('dashboard.in-days', (int) $daysLeft);

                return $news;
            })
            ->sortBy('event_timestamp')
            ->values();
    }
}
