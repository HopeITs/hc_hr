<?php

namespace App\Services;

use App\Models\City;
use App\Models\Contract;
use App\Models\TerminationReason;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(public readonly User $viewer, public readonly int $days = 365) {}

    public static function normaliseDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 365;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $rows = $this->leavers()->get();
        $withReason = $rows->whereNotNull('termination_reason_id');

        $reasons = TerminationReason::query()->get()->keyBy('id');
        $cities = City::query()->get()->keyBy('id');

        return [
            'days' => self::normaliseDays($this->days),
            'total' => $rows->count(),
            'recorded' => $withReason->count(),
            'missing' => $rows->count() - $withReason->count(),
            'averageTenureMonths' => $rows->isEmpty() ? null : round($rows->avg(fn (Contract $c) => $this->tenureMonths($c)), 1),
            'byReason' => $this->share($withReason->groupBy('termination_reason_id'), $rows->count(), fn ($id) => ($reasons[$id] ?? null)?->title ?: '-'),
            'byCity' => $this->share($rows->groupBy(fn (Contract $c) => $c->employee?->city_id), $rows->count(), fn ($id) => ($cities[$id] ?? null)?->name ?: '-'),
            'monthly' => $rows->groupBy(fn (Contract $c) => $this->leftOn($c)->format('Y-m'))->map->count()->sortKeys(),
            'withoutReason' => $rows->whereNull('termination_reason_id')
                ->sortByDesc(fn (Contract $c) => $this->leftOn($c))
                ->values()
                ->map(fn (Contract $c) => [
                    'name' => $c->employee?->name ?? '-',
                    'left_on' => $this->leftOn($c)->toDateString(),
                    'note' => $c->reason_of_termination,
                ])
                ->all(),
        ];
    }

    /** Latest contract of each person, terminated, inside the period and the viewer's scope. */
    private function leavers(): Builder
    {
        $days = self::normaliseDays($this->days);

        return Contract::query()
            ->visibleTo($this->viewer)
            ->latestPerEmployee()
            ->where(fn (Builder $q) => $q->whereNotNull('contracts.terminated_at')->orWhereNotNull('contracts.termination_reason_id'))
            ->when($days > 0, fn (Builder $q) => $q->whereRaw(
                'coalesce(contracts.terminated_at, contracts.end_date) >= ?',
                [now()->subDays($days)->startOfDay()->toDateTimeString()],
            ))
            ->with('employee');
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
