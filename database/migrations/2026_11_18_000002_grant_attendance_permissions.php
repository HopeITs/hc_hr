<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * attendance.view: see the timesheets and print them (a city HR role sees its own city).
 * attendance.manage: upload the device report, link device numbers, decide days.
 * Both go to every HR role and the administrator roles, the way message-templates were
 * granted; anyone else is granted per user from Access Management.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['attendance.view', 'attendance.manage'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()
            ->where('name', 'like', 'HR-%')
            ->orWhereIn('name', config('access-control.administrator_roles', []))
            ->get();

        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');

            foreach ($roles as $role) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
