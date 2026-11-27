<?php

namespace App\Livewire\Position;

use App\Models\Position;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CurrentHolders extends Component
{
    public bool $showCurrentHolders = false;

    #[Locked]
    public ?int $holdersPositionId = null;

    public function openCurrentHolders(int $positionId): void
    {
        $this->currentHoldersPosition($positionId);
        $this->holdersPositionId = $positionId;
        $this->showCurrentHolders = true;
    }

    public function render(): View
    {
        return view('livewire.position.current-holders', [
            'holdersPosition' => $this->showCurrentHolders && $this->holdersPositionId !== null
                ? $this->currentHoldersPosition($this->holdersPositionId)
                : null,
        ]);
    }

    private function currentHoldersPosition(int $positionId): Position
    {
        $user = auth()->user();
        abort_unless($user?->activeRoleIs('HR') && $user->canOpenReferencePage('positions'), 403);

        $query = Position::query()->with('runningSeatContracts.employee.media');
        if (($cityIds = $user->activeCityIds()) !== null) {
            $query->inCity($cityIds);
        }

        return $query->findOrFail($positionId);
    }
}
