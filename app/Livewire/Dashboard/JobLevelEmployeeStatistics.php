<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Contract;
use App\Support\StatisticsCache;
use Livewire\Component;

class JobLevelEmployeeStatistics extends Component
{
    use HandlesLoadFailure;

    public $jobLevelLabels = [];

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
        return view('livewire.dashboard.job-level-employee-statistics');
    }

    private function loadData(): void
    {
        $this->guardedLoad(fn () => $this->compute());
    }

    private function compute(): void
    {
        /**
         * Headcount per job level, based on each employee's latest ACTIVE contract.
         *
         * BEHAVIOUR CHANGE — this card was previously wrong, not merely slow. It used
         * `->with(['contracts' => fn ($q) => $q->where('is_active', true)->limit(1)])`,
         * but a limit inside an eager load caps the whole result set rather than each
         * parent row, so one contract was returned for the entire page and every other
         * employee resolved to null. The chart was effectively rendering a count of one.
         * The grouped query below reports the real distribution.
         *
         * The active-contract filter is passed through latestPerEmployee() so that it
         * also constrains the "is there a newer one" subquery — see that scope's
         * docblock for why applying it to only the outer query would drop employees
         * whose newest contract has expired.
         *
         * Job level names follow the {attribute}_{locale} column convention, so the
         * label column is resolved per request; StatisticsCache keys by locale.
         */
        $labelColumn = 'job_levels.'.transColumn('name');

        $counts = StatisticsCache::remember('employees.by-job-level', fn () => Contract::query()
            ->latestPerEmployee(fn ($query, $table = 'contracts') => $query->where($table.'.is_active', true))
            ->join('positions', 'positions.id', '=', 'contracts.position_id')
            ->join('job_levels', 'job_levels.id', '=', 'positions.job_level_id')
            ->groupBy($labelColumn)
            ->orderBy($labelColumn)
            ->select("{$labelColumn} as label")
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'label')
            ->all());

        $this->jobLevelLabels = array_keys($counts);
        $this->employeeCounts = array_map('intval', array_values($counts));
    }
}
