<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Badge;
use Illuminate\Support\Collection;
use Livewire\Component;

/** The viewer's own badges and warnings, newest first (it was a section of Dashboard\Index). */
class BadgesCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.badges-card', [
            'myBadges' => $this->guardedLoad(fn () => $this->loadMyBadges(), collect()),
        ]);
    }

    protected function loadMyBadges(): Collection
    {
        $employeeId = auth()->user()?->employee_id;

        if (! $employeeId) {
            return collect();
        }

        return Badge::query()->with('employee')->where('employee_id', $employeeId)->orderByDesc('date')->get();
    }
}
