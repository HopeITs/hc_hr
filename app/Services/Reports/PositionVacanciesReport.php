<?php

namespace App\Services\Reports;

use App\Models\City;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Capacity against people in post. "In post" is positions.exist_emp, the
 * counter ContractObserver keeps in step with the contracts; capacity is
 * positions.max_emp. City scoping goes through Position::scopeInCity, the
 * single source of truth for it. Inactive positions are left out.
 */
class PositionVacanciesReport
{
    public const FILTERS = ['department_id', 'program_id', 'city_id'];

    /** Rows of the vacancy list on the page; the export has them all. */
    public const LIST_LIMIT = 50;

    /** @param  array<string, int|string|null>  $filters  department_id, program_id, city_id, critical (bool), state (all|vacant|empty|full|over) */
    public function __construct(public readonly User $viewer, public readonly array $filters = []) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $positions = $this->positions()->get();
        $state = $this->filters['state'] ?? 'all';

        $rows = $positions->map(function (Position $p) {
            $capacity = (int) $p->max_emp;
            $filled = (int) $p->exist_emp;

            return [
                'id' => $p->id,
                'title' => $p->title ?: '-',
                'city' => $this->cityName($p),
                'department' => $p->department?->name ?: '-',
                'program' => $p->program?->name ?: '-',
                'capacity' => $capacity,
                'filled' => $filled,
                'vacant' => max(0, $capacity - $filled),
                'over' => max(0, $filled - $capacity),
                'critical' => (bool) $p->is_critical,
            ];
        });

        $listed = $rows->filter(fn (array $r) => match ($state) {
            'vacant' => $r['vacant'] > 0,
            'empty' => $r['filled'] === 0 && $r['capacity'] > 0,
            'full' => $r['vacant'] === 0 && $r['over'] === 0,
            'over' => $r['over'] > 0,
            default => true,
        })->sortBy([['critical', 'desc'], ['vacant', 'desc'], ['title', 'asc']])->values();

        $capacity = $rows->sum('capacity');
        $filled = $rows->sum('filled');

        return [
            'positions' => $rows->count(),
            'capacity' => $capacity,
            'filled' => $filled,
            'vacantSeats' => $rows->sum('vacant'),
            'fillPercent' => $capacity ? round(min($filled, $capacity) / $capacity * 100, 1) : null,
            'emptyPositions' => $rows->filter(fn ($r) => $r['filled'] === 0 && $r['capacity'] > 0)->count(),
            'overPositions' => $rows->where('over', '>', 0)->count(),
            'criticalVacant' => $rows->where('critical', true)->filter(fn ($r) => $r['vacant'] > 0)->count(),
            'byCity' => $this->group($rows, 'city'),
            'byDepartment' => $this->group($rows, 'department'),
            'list' => $listed,
            'listTotal' => $listed->count(),
        ];
    }

    private function positions(): Builder
    {
        $cityIds = $this->viewer->activeCityIds();
        $filters = array_filter(Arr::only($this->filters, self::FILTERS), fn ($v) => filled($v));

        return Position::query()
            ->where('is_active', true)
            ->when($cityIds !== null, fn (Builder $q) => $q->inCity($cityIds))
            ->when(isset($filters['city_id']), fn (Builder $q) => $q->inCity((int) $filters['city_id']))
            ->when(isset($filters['department_id']), fn (Builder $q) => $q->where('department_id', (int) $filters['department_id']))
            ->when(isset($filters['program_id']), fn (Builder $q) => $q->where('program_id', (int) $filters['program_id']))
            ->when(! empty($this->filters['critical']), fn (Builder $q) => $q->where('is_critical', true))
            ->with(['center.city', 'city', 'department', 'program']);
    }

    private function cityName(Position $p): string
    {
        $city = $p->city ?? $p->center?->city;

        return $city instanceof City ? ($city->name ?: '-') : '-';
    }

    /** @return Collection<int, array{label: string, positions: int, capacity: int, filled: int, vacant: int, percent: float|null}> */
    private function group(Collection $rows, string $key): Collection
    {
        return $rows->groupBy($key)->map(function (Collection $g, string $label) {
            $capacity = $g->sum('capacity');

            return [
                'label' => $label,
                'positions' => $g->count(),
                'capacity' => $capacity,
                'filled' => $g->sum('filled'),
                'vacant' => $g->sum('vacant'),
                'percent' => $capacity ? round(min($g->sum('filled'), $capacity) / $capacity * 100, 1) : null,
            ];
        })->sortByDesc('vacant')->values();
    }
}
