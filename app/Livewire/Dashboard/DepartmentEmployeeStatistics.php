<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Contract;
use App\Support\StatisticsCache;
use Livewire\Component;

class DepartmentEmployeeStatistics extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public $departmentLabels = [];

    public $employeeCounts = [];

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * B3: re-runs the load. This card's Chart.js script is pushed once at page load
     * and reads the data inline, so a successful retry reloads the page instead of
     * leaving a chart that was initialised from empty data.
     */
    public function retryLoad(): void
    {
        $this->loadData();

        if (! $this->loadFailed) {
            $this->js('window.location.reload()');
        }
    }

    public function render()
    {
        return view('livewire.dashboard.department-employee-statistics');
    }

    private function loadData(): void
    {
        $this->guardedLoad(fn () => $this->compute());
    }

    private function compute(): void
    {
        /**
         * Headcount per department, based on each employee's current contract.
         *
         * Previously this hydrated every Employee with contracts.position.department
         * eager-loaded and reduced it with countBy() in PHP — four tables fully in
         * memory to produce a handful of integers. It is now a single grouped query
         * against contracts, narrowed to one row per employee by latestPerEmployee().
         *
         * Scope is intentionally org-wide, matching the previous behaviour: this
         * card shows organisation totals, not the viewer's city.
         */
        $counts = StatisticsCache::remember('employees.by-department', fn () => Contract::query()
            ->latestPerEmployee()
            ->join('positions', 'positions.id', '=', 'contracts.position_id')
            ->join('departments', 'departments.id', '=', 'positions.department_id')
            ->groupBy('departments.code')
            ->orderBy('departments.code')
            ->select('departments.code as label')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'label')
            ->all());

        $this->departmentLabels = array_keys($counts);
        $this->employeeCounts = array_map('intval', array_values($counts));
    }
}
