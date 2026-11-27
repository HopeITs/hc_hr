<?php

namespace App\Livewire\Position;

use App\Models\Center;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PositionsModule extends Component
{
    #[Locked]
    public bool $sharedSeats = false;

    #[Locked]
    public ?int $centerId = null;

    #[Locked]
    public ?int $cityId = null;

    public function mount(): void
    {
        $this->sharedSeats = request()->boolean('shared') && (bool) auth()->user()?->activeRoleIs('HR');
        $this->centerId = request()->integer('center') ?: null;
        $this->cityId = request()->integer('city') ?: null;
    }

    public function render()
    {
        return view('livewire.position.index', [
            'center' => $this->centerId === null ? null : Center::with('city')->find($this->centerId),
        ]);
    }
}
