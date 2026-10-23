<?php

namespace App\Livewire\Dashboard;

use App\Enums\LeaveTypeCases;
use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * B3: lazy. The unbounded whereHas over leave balances plus per-row
 * latestActiveContract()/department lookups in the view grow with headcount.
 */
#[Lazy]
class NoLeavesEmployeesStatistics extends Component
{
    use HandlesLoadFailure;

    /** @var Collection<int, Employee> */
    public $noLeavesEmployees = [];

    public function placeholder()
    {
        return view('livewire.dashboard.card-placeholder', ['title' => __('general.no-leaves-employees')]);
    }

    public function mount(): void
    {
        $this->loadEmployees();
    }

    public function retryLoad(): void
    {
        $this->loadEmployees();
    }

    public function render()
    {
        return view('livewire.dashboard.no-leaves-employees-statistics');
    }

    private function loadEmployees(): void
    {
        $this->noLeavesEmployees = $this->guardedLoad(
            fn () => Employee::whereHas('contracts', function ($contractQuery) {
                $contractQuery->whereHas('leaveBalances', function ($balanceQuery) {
                    $balanceQuery->where('type_id', LeaveTypeCases::Annual)
                        ->whereDoesntHave('leaveRequests');
                });
            })->with(['media'])->get(),
            fallback: new \Illuminate\Database\Eloquent\Collection,
        );
    }
}
