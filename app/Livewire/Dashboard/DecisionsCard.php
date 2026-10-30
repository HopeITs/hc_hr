<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Component;

/**
 * "Work inbox": the inbox rows that need the viewer's decision, oldest first,
 * one at a time, then the rest of the inbox as a short list
 * (WaitingOnYou::decisions). It was a block fixed above the grid; now a tile
 * (media-request b183c0c), and it took over "For you now". Nothing: one quiet
 * line, which the grid folds to the bottom (data-tile-empty).
 */
class DecisionsCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard.decisions-card', [
            'decisions' => $user
                ? $this->guardedLoad(fn () => app(WaitingOnYou::class)->decisions($user), ['cards' => [], 'total' => 0, 'items' => [], 'others' => 0, 'all' => 0])
                : ['cards' => [], 'total' => 0, 'items' => [], 'others' => 0, 'all' => 0],
            'inboxUrl' => route('inbox'),
        ]);
    }
}
