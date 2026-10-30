<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\IsDashboardTile;
use Livewire\Component;

class MyLeavesCard extends Component
{
    use IsDashboardTile;

    public $title = '';

    public $total = 12;

    public $remaining = 12;

    public $totalDays = 12;

    public $remainingDays = 12;

    public $used = 0;

    public $pending = 0;

    public $percentage = 50;

    public function mount()
    {
        $user = auth()->user();
        $leave = $user->profile->availableLeaves()->first();
        $this->title = $leave->leaveType->name;
        $this->total = $leave->deserved_formatted;
        $this->remaining = $leave->remaining;
        $this->totalDays = $leave->int_total;
        $this->remainingDays = $leave->int_remaining;
        $this->used = $leave->used_formatted;
        $this->pending = formatDuration($leave->pending_days, $leave->pending_hours);
        $this->percentage = round($leave->used / $leave->deserved * 100);

    }

    public function render()
    {

        return view('livewire.dashboard.my-leaves-card', [
        ]);
    }
}
