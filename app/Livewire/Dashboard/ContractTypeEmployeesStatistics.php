<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Contract;
use App\Models\ContractType;
use App\Support\StatisticsCache;
use Livewire\Component;

class ContractTypeEmployeesStatistics extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    /**
     * Donut sections → the contract_type_id values that roll up into them.
     *
     * NOTE: these ids are hard-coded to match ContractTypeSeeder. They were
     * already hard-coded before; keeping them here, named, at least makes the
     * coupling visible rather than burying it mid-method.
     */
    private const GROUPS = [
        'annual' => [1, 2],
        'trial' => [3, 4],
        'service' => [5, 6],
    ];

    public $chartData;

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
        return view('livewire.dashboard.contract-type-employees-statistics', [
            'chartData' => $this->chartData,
        ]);
    }

    private function loadData(): void
    {
        $this->guardedLoad(fn () => $this->compute());
    }

    private function compute(): void
    {
        /**
         * Contract counts per type, rolled up into the three donut sections.
         *
         * Was: `Contract::select('contract_type_id')->get()` — every contract row
         * pulled into a collection — followed by seven separate in-memory ->where()
         * passes over it (one per group plus one per breakdown entry). Now a single
         * GROUP BY returns the per-type totals and the rollup is done over an array
         * of at most six integers.
         *
         * Counts are per CONTRACT, not per employee, which is what this card has
         * always shown — deliberately left as-is.
         */
        [$countsByType, $typeNames] = StatisticsCache::remember('contracts.by-type', fn () => [
            Contract::query()
                ->groupBy('contract_type_id')
                ->selectRaw('contract_type_id, COUNT(*) as total')
                ->pluck('total', 'contract_type_id')
                ->all(),
            ContractType::query()->get(['id', 'name_en', 'name_ar'])
                ->mapWithKeys(fn (ContractType $type) => [$type->id => [
                    'en' => $type->name_en,
                    'local' => $type->{transColumn('name')} ?: $type->name_en,
                ]])
                ->all(),
        ]);

        $this->chartData = collect(self::GROUPS)
            ->map(fn (array $typeIds, string $group) => [
                'category' => __("dashboard.contract-groups.{$group}"),
                'value' => array_sum(array_map(
                    fn ($typeId) => (int) ($countsByType[$typeId] ?? 0),
                    $typeIds,
                )),
                'breakdown' => collect($typeIds)
                    ->map(fn ($typeId) => [
                        'category' => $this->shortLabel($typeNames[$typeId] ?? null),
                        'value' => (int) ($countsByType[$typeId] ?? 0),
                    ])
                    ->values(),
            ])
            ->values();
    }

    /**
     * The seeded names are too long to fit a donut legend, so the two agreement
     * variants are abbreviated. Anything else is shown verbatim.
     */
    private function shortLabel(?array $name): string
    {
        if ($name === null) {
            return __('general.unknown');
        }

        // Matched on the English name (stable seed value), shown in the
        // current locale.
        return match (strtolower((string) $name['en'])) {
            'online agreement contract' => __('general.online'),
            'onsite agreement contract' => __('general.onsite'),
            default => (string) $name['local'],
        };
    }
}
