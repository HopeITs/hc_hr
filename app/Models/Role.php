<?php

namespace App\Models;

use App\Support\AccessPermissionCatalog;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::created(function (Role $role): void {
            $permissions = Permission::query()
                ->whereIn('name', AccessPermissionCatalog::names())
                ->get();

            if (in_array($role->name, config('access-control.administrator_roles', []), true)) {
                $role->givePermissionTo($permissions);
            } elseif (Str::startsWith($role->name, 'HR-')) {
                $role->givePermissionTo($permissions->reject(
                    fn (Permission $permission): bool => str_starts_with($permission->name, 'access-management.')
                        || $permission->name === 'activity-log.view',
                ));
            } else {
                $baseline = config("access-control.role_baselines.{$role->name}", []);

                if ($baseline !== []) {
                    $role->givePermissionTo($baseline);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
