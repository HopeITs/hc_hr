<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Every catalog permission is created; only the role-grantable ones are
        // handed out below. The rest are granted per user.
        collect(AccessPermissionCatalog::names())
            ->each(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        $permissions = collect(AccessPermissionCatalog::roleGrantableNames())
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }

        foreach (Role::query()->where('name', 'like', 'HR-%')->where('name', '!=', 'HR-SYR')->get() as $role) {
            $role->givePermissionTo($permissions->reject(
                fn (Permission $permission): bool => str_starts_with($permission->name, 'access-management.')
                    || $permission->name === 'activity-log.view',
            ));
        }

        foreach (config('access-control.role_baselines', []) as $roleName => $permissionNames) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', AccessPermissionCatalog::names())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
