<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Critical positions (meeting 19/11, track 6):
 *  - positions.mark-critical: HR roles and the administrators set or clear the tag;
 *  - positions.view-replacements: the suggested-replacements panel, HR roles (their
 *    city), the general manager and the administrators. Never managers or employees.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $mark = Permission::findOrCreate('positions.mark-critical', 'web');
        $view = Permission::findOrCreate('positions.view-replacements', 'web');

        $hr = Role::query()
            ->where('name', 'like', 'HR-%')
            ->orWhereIn('name', config('access-control.administrator_roles', []))
            ->get();

        foreach ($hr as $role) {
            $role->givePermissionTo($mark, $view);
        }

        Role::query()->where('name', 'general-manager')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($view));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['positions.mark-critical', 'positions.view-replacements'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
