<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * message-templates.manage: write and edit the email / WhatsApp templates.
 * message-templates.use: pick one on a person's card. Both go to every HR role
 * and the administrator roles, the way contracts.amend was granted; anyone
 * else (a manager, say) is granted `use` per user from Access Management.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['message-templates.manage', 'message-templates.use'];

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
