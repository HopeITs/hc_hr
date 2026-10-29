<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Checklist;
use Illuminate\Support\Collection;
use Livewire\Component;

/** Items handed out to the viewer, newest first (it was a section of Dashboard\Index). */
class ChecklistCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.checklist-card', [
            'deliveredItems' => $this->guardedLoad(fn () => $this->loadDeliveredItems(), collect()),
        ]);
    }

    protected function loadDeliveredItems(): Collection
    {
        $employeeId = auth()->user()?->employee_id;

        if (! $employeeId) {
            return collect();
        }

        return Checklist::query()
            ->with(['item', 'delivered'])
            ->where('employee_id', $employeeId)
            ->where('delivered_status', 'delivered')
            ->orderByDesc('delivered_at')
            ->get();
    }
}
