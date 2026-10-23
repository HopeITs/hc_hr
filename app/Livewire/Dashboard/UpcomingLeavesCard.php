<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * B3: NOT lazy. Measured ~21 queries / ~14 ms for an empty set; making it #[Lazy]
 * also leaves `upcomingLeaves` unset under Livewire::test(), which the existing
 * UpcomingLeavesCardTest asserts on. Revisit together with that test.
 * Visibility stays in LeaveRequest::upcomingVisibleTo() — the role/city scope is
 * not reimplemented here.
 */
class UpcomingLeavesCard extends Component
{
    use HandlesLoadFailure;

    public $upcomingLeaves;

    public function mount(): void
    {
        $this->loadLeaves();
    }

    public function retryLoad(): void
    {
        $this->loadLeaves();
    }

    public function render()
    {
        return view('livewire.dashboard.upcoming-leaves-card');
    }

    private function loadLeaves(): void
    {
        $this->upcomingLeaves = $this->guardedLoad(function () {
            $user = auth()->user()->load([
                'profile.positions',
                'profile.contracts.position',
            ]);

            return LeaveRequest::query()
                ->upcoming()
                ->upcomingVisibleTo($user)
                ->with([
                    'leaveBalance.leaveType',
                    'leaveBalance.contract.position',
                    'leaveBalance.contract.employee',
                ])
                ->orderBy('start_at')
                ->get();
        }, fallback: new Collection);
    }
}
