<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 Task 10: turning an accepted offer into an employee and a
 * contract, HR only.
 *
 * - convert-hires: its own permission, never implied by manage-offers —
 *   hiring creates records in the HR system itself. The policy also asks for
 *   contracts.create and User::canViewSalary() (the contract carries the
 *   salary), so super-admin, salary-blind by design, can follow a hire but
 *   not make one; and creating or linking the employee is checked against
 *   EmployeePolicy as the employee screens do.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.convert-hires',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NAMES as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo(self::NAMES);
        }

        Role::query()
            ->where('name', 'like', 'HR-%')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::NAMES));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NAMES)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
