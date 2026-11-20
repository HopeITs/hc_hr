<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Attendance is scoped by role like the other pages:
 *  - attendance.view-own goes to every role (an employee sees only their own timesheet);
 *  - attendance.view (the people the role looks after) already went to the HR roles and the
 *    administrators; it now also goes to the managers (their direct reports) and the general
 *    manager (everyone).
 * The scope itself is in App\Services\Attendance\Roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $own = Permission::findOrCreate('attendance.view-own', 'web');

        foreach (Role::query()->get() as $role) {
            $role->givePermissionTo($own);
        }

        $view = Permission::findOrCreate('attendance.view', 'web');

        foreach (Role::query()->whereIn('name', ['manager', 'general-manager'])->get() as $role) {
            $role->givePermissionTo($view);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'attendance.view-own')->delete();

        Role::query()->whereIn('name', ['manager', 'general-manager'])->get()
            ->each(fn (Role $role) => $role->revokePermissionTo('attendance.view'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
