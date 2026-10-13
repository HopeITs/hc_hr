<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class AccessPermissionCatalog
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return config('access-control.groups', []);
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return collect(self::groups())
            ->flatMap(fn (array $actions, string $group): array => array_map(
                fn (string $action): string => "{$group}.{$action}",
                $actions,
            ))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{name: string, label: string, permissions: Collection}>
     */
    public static function forDisplay(Collection $permissions): Collection
    {
        return collect(self::groups())->map(function (array $actions, string $group) use ($permissions): array {
            return [
                'name' => $group,
                'label' => __("access.groups.{$group}"),
                'permissions' => collect($actions)->map(function (string $action) use ($group, $permissions): array {
                    $name = "{$group}.{$action}";

                    return [
                        'id' => (int) $permissions->firstWhere('name', $name)?->id,
                        'name' => $name,
                        'label' => __("access.actions.{$action}"),
                    ];
                })->filter(fn (array $permission): bool => $permission['id'] > 0),
            ];
        })->values();
    }
}
