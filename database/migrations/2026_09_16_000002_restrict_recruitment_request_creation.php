<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'recruitment.requests.create';

    private const RESTRICTED_ROLES = [
        'employee',
        'manager',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($permission !== null) {
            Role::query()
                ->whereIn('name', self::RESTRICTED_ROLES)
                ->where('guard_name', 'web')
                ->get()
                ->each(function (Role $role) use ($permission): void {
                    $role->revokePermissionTo($permission);
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($permission !== null) {
            Role::query()
                ->whereIn('name', self::RESTRICTED_ROLES)
                ->where('guard_name', 'web')
                ->get()
                ->each(function (Role $role) use ($permission): void {
                    $role->givePermissionTo($permission);
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
