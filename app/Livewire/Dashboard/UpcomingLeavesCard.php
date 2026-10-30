<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Services\TeamCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Upcoming leaves on the home page. Since C4 it reads TeamCoverage (C5), the
 * same reader as the team calendar: the active role's default team, accepted
 * leave only for every role (team-coverage-contract.md §3.1 Q2, DEC-05 rev.),
 * colleague-level projection where that is all the role may see. So the old
 * technical-manager line and rejected rows on the HR card are gone (T-3, T-4).
 *
 * The rows are computed in render() and never kept in public state, so no
 * request id or model reaches a colleague-level browser. Bounded to the
 * service's 62 days (today and the next 61).
 */
class UpcomingLeavesCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public const HORIZON_DAYS = 61;

    public function render()
    {
        $user = auth()->user();
        $coverage = app(TeamCoverage::class);
        $today = CarbonImmutable::today();

        /** @var Collection<int, array<string, mixed>> $leaves */
        $leaves = $this->guardedLoad(fn () => $coverage->absences(
            $user,
            $coverage->defaultScope($user),
            $today,
            $today->addDays(self::HORIZON_DAYS),
            ['status' => 'accepted'],
        ), fallback: collect());

        return view('livewire.dashboard.upcoming-leaves-card', [
            'upcomingLeaves' => $leaves,
            'calendarUrl' => route('team.calendar'),
        ]);
    }
}
