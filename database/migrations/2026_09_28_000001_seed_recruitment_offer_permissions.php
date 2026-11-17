<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 Task 9: job offers, HR only.
 *
 * - view-offers: an offer's status, dates and contract terms, for
 *   applications in the user's recruitment scope. The salary on it is shown
 *   only to users who may see pay figures at all (User::canViewSalary()).
 * - manage-offers: create, edit a draft, send, revise, record the
 *   candidate's answer, cancel. Because creating or revising an offer means
 *   entering a salary, it also needs canViewSalary() — so super-admin, which
 *   is salary-blind by design, can follow offers but not write them.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.view-offers',
        'recruitment-applicants.manage-offers',
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
