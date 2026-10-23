<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Contract;
use App\Support\StatisticsCache;
use Livewire\Component;

class SalaryPerEmployeeStatistics extends Component
{
    use HandlesLoadFailure;

    /**
     * salary_per is stored as a tinyint (see the contracts migration:
     * 0=Unknown, 1=Month, 2=Hour, 3=Session). Presentation metadata is keyed by
     * that raw value so the DB code, the label and the slice colour stay in one
     * place — previously the id→bucket map and the label/colour map were two
     * separate arrays that had to be kept in sync by hand.
     *
     * Colours are unchanged from the original implementation; the middle entry
     * is the brand primary (#be1f24).
     */
    private const SALARY_PER_META = [
        1 => ['key' => 'per_month', 'label' => 'dashboard.salary-per.month', 'color' => '#be1f24'],
        3 => ['key' => 'per_session', 'label' => 'dashboard.salary-per.session', 'color' => '#58595b'],
        2 => ['key' => 'per_hour', 'label' => 'dashboard.salary-per.hour', 'color' => '#dcddde'],
    ];

    public array $series = [];

    public int $totalEmployees = 0;

    public int $highlightPercentage = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * B3: re-runs the load. The amCharts init re-runs after every Livewire commit, so a re-render is enough.
     */
    public function retryLoad(): void
    {
        $this->loadData();
    }

    public function render()
    {
        return view('livewire.dashboard.salary-per-employee-statistics');
    }

    private function loadData(): void
    {
        $this->guardedLoad(fn () => $this->compute());
    }

    private function compute(): void
    {
        /**
         * Distribution of salary periods across each employee's current contract.
         *
         * Was: load every Employee with every contract eager-loaded, then loop in
         * PHP incrementing three counters. Now a single GROUP BY over one row per
         * employee. Only the three known salary_per values are charted; unknown (0)
         * is excluded, matching the original `isset($map[$type])` guard.
         */
        $counts = StatisticsCache::remember('employees.by-salary-per', fn () => Contract::query()
            ->latestPerEmployee()
            ->whereIn('salary_per', array_keys(self::SALARY_PER_META))
            ->groupBy('salary_per')
            ->selectRaw('salary_per, COUNT(*) as total')
            ->pluck('total', 'salary_per')
            ->all());

        $this->series = [];

        foreach (self::SALARY_PER_META as $salaryPer => $meta) {
            $this->series[] = [
                // Translation key in the map, resolved per request (the
                // counts are cached, the labels are not).
                'label' => __($meta['label']),
                'color' => $meta['color'],
                'value' => (int) ($counts[$salaryPer] ?? 0),
            ];
        }

        $this->totalEmployees = array_sum(array_column($this->series, 'value'));

        $perMonth = (int) ($counts[1] ?? 0);

        $this->highlightPercentage = $this->totalEmployees > 0
            ? (int) round($perMonth / $this->totalEmployees * 100)
            : 0;
    }
}
