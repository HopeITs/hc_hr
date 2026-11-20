<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * mail-lists.manage: import and edit the members of the mailing lists. Reading
 * the lists, emailing one and sharing a folder with one are open to every
 * employee (the last still needs archives.share and a folder of one's own).
 * Granted to every HR role and the administrator roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('mail-lists.manage', 'web');

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
        Permission::query()->where('name', 'mail-lists.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
