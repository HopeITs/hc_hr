<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * contracts.amend: drafting and approving contract amendments. Granted to
 * every HR role — HR-SYR across all cities, each city's HR on its own (the
 * city scope is ContractPolicy's) — and to the administrator roles, the way
 * 2026_07_30_000001 seeded every other permission. ContractPolicy::amend still
 * requires an HR active role, so the grant alone lets nobody else amend.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('contracts.amend', 'web');

        $roles = Role::query()
            ->where('name', 'like', 'HR-%')
            ->orWhereIn('name', config('access-control.administrator_roles', []))
            ->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'contracts.amend')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
