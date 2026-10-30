<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Services\TeamCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "Out today": who in the viewer's team is away today, read from
 * TeamCoverage on the scope the team calendar opens on, so the tile and the
 * calendar it links to can never disagree about who may be seen. Only
 * accepted leave: a pending request is not an absence yet. The viewer
 * themself is left out. DashboardLayout offers the tile to managers, HR and
 * leadership only.
 */
class WhoIsOutCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    /** People drawn by name; the rest are the "+N". */
    public const SHOWN = 6;

    public function render()
    {
        $user = auth()->user();
        $people = $this->guardedLoad(fn () => $this->outToday(), collect());

        return view('livewire.dashboard.who-is-out-card', [
            'shown' => $people->take(self::SHOWN),
            'total' => $people->count(),
            'calendarUrl' => $user?->hasActivePermission('leaves.view-own') ? route('team.calendar', ['view' => 'day']) : null,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function outToday(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        $coverage = app(TeamCoverage::class);
        $today = CarbonImmutable::today();

        return $coverage->absences($user, $coverage->defaultScope($user), $today, $today)
            ->reject(fn (array $entry) => $entry['is_self'] || $entry['status'] === 'pending')
            ->unique('person')
            ->sortBy('name')
            ->values();
    }
}
