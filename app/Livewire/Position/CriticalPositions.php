<?php

namespace App\Livewire\Position;

use App\Models\Position;
use App\Services\CriticalPositions\CriticalPositionAlerts;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Meeting 19/11, track 6: the critical positions in the viewer's scope and which of them need a replacement. */
class CriticalPositions extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->hasActivePermission('positions.view-replacements'), 403);
    }

    public function render(CriticalPositionAlerts $alerts)
    {
        $user = auth()->user();
        $cityIds = $user->activeCityIds();

        $positions = Position::query()
            ->where('is_critical', true)
            ->when($cityIds !== null, fn ($q) => $q->inCity($cityIds))
            ->with(['city', 'center.city', 'jobLevel', 'activeContracts.employee'])
            ->orderBy(transColumn('title'))
            ->get()
            ->filter(fn (Position $p) => Gate::forUser($user)->allows('viewReplacements', $p))
            ->map(fn (Position $p) => ['position' => $p, 'needs' => $alerts->needsReplacement($p)]);

        return view('livewire.position.critical-positions', ['rows' => $positions]);
    }
}
