<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Livewire\Team\Calendar;
use App\Models\Employee;
use App\Services\TeamCoverage;
use App\Support\DirectoryAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "Birthdays this week": the people of the viewer's team (TeamCoverage, on
 * the scope the team calendar opens on, so nobody appears here whom the
 * calendar would not show) whose birthday falls in this Sunday–Saturday week
 * (Team\Calendar::weekOf). Only the day leaves this class: never the year,
 * never an age. A 29 February birthday is kept on 1 March in other years, as
 * recurring holidays are.
 */
class BirthdaysCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.birthdays-card', [
            'people' => $this->guardedLoad(fn () => $this->thisWeek(), collect()),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /** @return Collection<int, array{id: int, name: string, avatar: string, date: CarbonImmutable}> */
    protected function thisWeek(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        $coverage = app(TeamCoverage::class);
        $ids = $coverage->memberIds($user, $coverage->defaultScope($user));

        if ($ids === []) {
            return collect();
        }

        [$from, $to] = Calendar::weekOf(CarbonImmutable::today());

        // month-day => the date it falls on this week
        $week = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $week[$day->format('m-d')] = $day;
        }

        if (! isset($week['02-29']) && isset($week['03-01'])) {
            $week['02-29'] = $week['03-01'];
        }

        $people = Employee::query()
            ->select([...DirectoryAccess::COLUMNS, 'actual_dob'])
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereNotNull('actual_dob')
            ->get()
            ->filter(fn (Employee $e) => isset($week[$e->actual_dob->format('m-d')]));

        $people->load('media');

        return $people
            ->map(fn (Employee $e) => [
                'id' => (int) $e->id,
                'name' => (string) $e->name,
                'avatar' => $e->avatar_url,
                'date' => $week[$e->actual_dob->format('m-d')],
            ])
            ->sortBy([['date', 'asc'], ['name', 'asc']])
            ->values();
    }
}
