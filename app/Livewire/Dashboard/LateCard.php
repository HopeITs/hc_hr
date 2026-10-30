<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Component;

/**
 * "Past the deadline": leave that started with no decision and contracts that
 * ended without renewal, in the viewer's scope (WaitingOnYou::late). It was a
 * strip fixed above the grid; now a tile (media-request b183c0c). Nothing late:
 * one quiet line, which the grid folds to the bottom (data-tile-empty).
 */
class LateCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard.late-card', [
            'late' => $user ? $this->guardedLoad(fn () => app(WaitingOnYou::class)->late($user)) : null,
        ]);
    }
}
