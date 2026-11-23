<?php

namespace App\Services;

use App\Enums\LeavingCategory;
use App\Models\City;
use App\Models\Contract;
use App\Models\TerminationReason;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Why people leave: the termination reason recorded on the contract they left
 * on. One row per person (their latest contract), so someone who left and came
 * back, or was renewed, is not counted as leaving. The page and the Excel
 * export read the same figures, scoped to what the viewer may see.
 */
class LeavingReasonsReport
{
    /** Days back; 0 means everything on record. */
    public const PERIODS = [90, 365, 0];

    /** Filters the page offers, each a column of the position or the contract. */
    public const FILTERS = ['department_id', 'program_id', 'job_level_id', 'position_id', 'contract_type_id'];

    /** Months of service: upper bound (exclusive) of each band; null = no limit. */
    public const TENURE_BANDS = [3, 6, 12, null];

    /** @param  array<string, int|string|null>  $filters */
    public function __construct(public readonly User $viewer, public readonly int $days = 365, public readonly array $filters = []) {}

    public static function normaliseDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 365;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $days = self::normaliseDays($this->days);
        $from = $days > 0 ? now()->subDays($days)->startOfDay() : null;
        $until = now()->addDay()->startOfDay();

        $rows = $this->leavers($from, $until)->get();
        $total = $rows->count();
        $withReason = $rows->whereNotNull('termination_reason_id');

        $reasons = TerminationReason::query()->get()->keyBy('id');
        $cities = City::query()->get()->keyBy('id');

        $previous = $from ? $this->leavers($from->copy()->subDays($days), $from)->count() : null;
        $headcount = $from ? (int) round(($this->headcountOn($from) + $this->headcountOn(now())) / 2) : null;

        return [
            'days' => $days,
            'total' => $total,
            'recorded' => $withReason->count(),
            'missing' => $total - $withReason->count(),
            'previousTotal' => $previous,
            'headcount' => $headcount,
            'turnoverPercent' => $headcount ? round($total / $headcount * 100, 1) : null,
            'averageTenureMonths' => $rows->isEmpty() ? null : round($rows->avg(fn (Contract $c) => $this->tenureMonths($c)), 1),
            'byReason' => $this->share($withReason->groupBy('termination_reason_id'), $total, fn ($id) => ($reasons[$id] ?? null)?->title ?: '-'),
            'byCategory' => $this->categories($rows, $reasons, $total),
            'byCity' => $this->share($rows->groupBy(fn (Contract $c) => $c->employee?->city_id), $total, fn ($id) => ($cities[$id] ?? null)?->name ?: '-'),
            'byTenure' => $this->tenureBands($rows, $total),
            'monthly' => $rows->groupBy(fn (Contract $c) => $this->leftOn($c)->format('Y-m'))->map->count()->sortKeys(),
            'withoutReason' => $rows->whereNull('termination_reason_id')
                ->sortByDesc(fn (Contract $c) => $this->leftOn($c))
                ->values()
                ->map(fn (Contract $c) => [
                    'contract_id' => $c->id,
                    'name' => $c->employee?->name ?? '-',
                    'left_on' => $this->leftOn($c)->toDateString(),
                    'note' => $c->reason_of_termination,
                ])
                ->all(),
        ];
    }

    /** Latest contract of each person, terminated inside [from, until), in the viewer's scope and the filters. */
    private function leavers(?Carbon $from, Carbon $until): Builder
    {
        // Raw SQL does not get the table prefix; wrapping through the grammar does.
        $grammar = Contract::query()->getQuery()->getGrammar();
        $left = 'coalesce('.$grammar->wrap('contracts.terminated_at').', '.$grammar->wrap('contracts.end_date').')';

        return $this->scoped(Contract::query())
            ->latestPerEmployee()
            ->where(fn (Builder $q) => $q->whereNotNull('contracts.terminated_at')->orWhereNotNull('contracts.termination_reason_id'))
            ->when($from, fn (Builder $q) => $q->whereRaw("$left >= ?", [$from->toDateTimeString()]))
            ->whereRaw("$left < ?", [$until->toDateTimeString()])
            ->with('employee');
    }

    /** The viewer's contracts, narrowed by the chosen filters. */
    private function scoped(Builder $query): Builder
    {
        $filters = array_filter(Arr::only($this->filters, self::FILTERS), fn ($v) => filled($v));

        return $query
            ->visibleTo($this->viewer)
            ->when(isset($filters['contract_type_id']), fn (Builder $q) => $q->where('contracts.contract_type_id', (int) $filters['contract_type_id']))
            ->when(
                array_intersect_key($filters, array_flip(['department_id', 'program_id', 'job_level_id', 'position_id'])) !== [],
                fn (Builder $q) => $q->whereHas('position', function (Builder $position) use ($filters) {
                    foreach (['department_id', 'program_id', 'job_level_id'] as $column) {
                        if (isset($filters[$column])) {
                            $position->where($position->qualifyColumn($column), (int) $filters[$column]);
                        }
                    }
                    if (isset($filters['position_id'])) {
                        $position->whereKey((int) $filters['position_id']);
                    }
                }),
            );
    }

    /** People with a contract in force on that day (not terminated before it), same scope and filters. */
    private function headcountOn(Carbon $day): int
    {
        return $this->scoped(Contract::query())
            ->whereDate('contracts.start_date', '<=', $day->toDateString())
            ->whereDate('contracts.end_date', '>=', $day->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('contracts.terminated_at')->orWhereDate('contracts.terminated_at', '>=', $day->toDateString()))
            ->distinct()
            ->count('contracts.employee_id');
    }

    private function leftOn(Contract $contract): Carbon
    {
        return Carbon::parse($contract->terminated_at ?? $contract->end_date);
    }

    private function tenureMonths(Contract $contract): float
    {
        $first = Contract::query()->where('employee_id', $contract->employee_id)->min('start_date') ?? $contract->start_date;

        return max(0, Carbon::parse($first)->floatDiffInMonths($this->leftOn($contract)));
    }

    /** Leavers by months of service: under 3, 3-6, 6-12, 12 and over. */
    private function tenureBands(Collection $rows, int $total): Collection
    {
        $counts = array_fill(0, count(self::TENURE_BANDS), 0);

        foreach ($rows as $row) {
            $months = $this->tenureMonths($row);
            foreach (self::TENURE_BANDS as $i => $limit) {
                if ($limit === null || $months < $limit) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        $labels = [__('Under 3 months'), __('3 to 6 months'), __('6 to 12 months'), __('A year or more')];

        return collect($counts)->map(fn (int $n, int $i) => [
            'label' => $labels[$i],
            'count' => $n,
            'percent' => $total ? round($n / $total * 100, 1) : 0.0,
        ]);
    }

    /** Voluntary / involuntary / ... over every leaver; reasons not yet classified and rows with no reason have their own lines. */
    private function categories(Collection $rows, Collection $reasons, int $total): Collection
    {
        $groups = $rows->groupBy(function (Contract $c) use ($reasons) {
            if ($c->termination_reason_id === null) {
                return 'missing';
            }

            return ($reasons[$c->termination_reason_id] ?? null)?->category?->value ?? 'unclassified';
        });

        $labels = LeavingCategory::options() + ['unclassified' => __('Not classified'), 'missing' => __('Reason not recorded')];

        return collect($labels)
            ->filter(fn ($label, $key) => $groups->has($key))
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $groups[$key]->count(),
                'percent' => $total ? round($groups[$key]->count() / $total * 100, 1) : 0.0,
            ])
            ->values();
    }

    /** @return Collection<int, array{label: string, count: int, percent: float}> */
    private function share(Collection $groups, int $total, Closure $label): Collection
    {
        return $groups->map(fn (Collection $g, $id) => [
            'label' => $label($id),
            'count' => $g->count(),
            'percent' => $total ? round($g->count() / $total * 100, 1) : 0.0,
        ])->sortByDesc('count')->values();
    }
}
