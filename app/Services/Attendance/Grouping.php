<?php

namespace App\Services\Attendance;

use Illuminate\Support\Collection;

/**
 * Timesheets laid out the way HR reads them: for each city, the people grouped by the project
 * they work on (program) or by department. Used by the timesheet page and by the printout, so
 * both order and count the same way.
 */
class Grouping
{
    public const PROJECT = 'program';

    public const DEPARTMENT = 'department';

    public static function normalize(?string $by): string
    {
        return $by === self::DEPARTMENT ? self::DEPARTMENT : self::PROJECT;
    }

    /** The group id a person belongs to (null = none set), as printed in the "group" parameter. */
    public static function groupId(array $item, string $by): ?int
    {
        return $item['sheet']['placement'][$by.'_id'] ?? null;
    }

    /**
     * @param  Collection<int, array{employee: \App\Models\Employee, sheet: array<string, mixed>}>  $items
     * @return Collection<int, array<string, mixed>> one block per city: id, name, groups[], totals
     */
    public function group(Collection $items, string $by): Collection
    {
        $by = self::normalize($by);

        return $items
            ->groupBy(fn (array $item) => (int) $item['employee']->city_id)
            ->map(function (Collection $inCity, int $cityId) use ($by) {
                $groups = $inCity
                    ->groupBy(fn (array $item) => self::groupId($item, $by) ?? 0)
                    ->map(function (Collection $members, int $groupId) use ($by) {
                        $members = $members->sortBy(fn (array $item) => mb_strtolower($item['employee']->name))->values();

                        return [
                            'key' => $by.'-'.($groupId ?: 'none'),
                            'id' => $groupId ?: null,
                            'name' => $members->first()['sheet']['placement'][$by] ?? null,
                            'items' => $members,
                            'totals' => $this->totals($members),
                        ];
                    })
                    // Named groups by name; the people with none set come last.
                    ->sortBy(fn (array $group) => [$group['id'] === null ? 1 : 0, mb_strtolower((string) $group['name'])])
                    ->values();

                return [
                    'id' => $cityId,
                    'name' => $inCity->first()['employee']->city?->name,
                    'groups' => $groups,
                    'totals' => $this->totals($inCity),
                ];
            })
            ->sortBy(fn (array $block) => mb_strtolower((string) $block['name']))
            ->values();
    }

    /** @return array{people: int, to_decide: int, overtime_hours: float, overtime_days: float} */
    private function totals(Collection $items): array
    {
        return [
            'people' => $items->count(),
            'to_decide' => (int) $items->sum(fn (array $i) => $i['sheet']['totals']['to_decide']),
            'overtime_hours' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_hours']), 2),
            'overtime_days' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_days']), 2),
        ];
    }
}
