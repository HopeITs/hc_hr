<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use Livewire\Component;

/**
 * "Request leave" as a card: the type (with the days left on it) and the
 * dates, then the full leave form on the leaves page with those filled in
 * (?new=1&balance=&date=&to=, MyLeaveRequestModule). Nothing is sent from
 * here: the reason, the cover, the cost and every check stay where they are,
 * in the one form. The types are the ones that form offers (allAvailableLeaves).
 */
class RequestLeaveCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.request-leave-card', [
            'balances' => $this->guardedLoad(fn () => auth()->user()?->profile?->allAvailableLeaves() ?? collect(), fallback: collect()),
            'today' => today()->toDateString(),
        ]);
    }
}
