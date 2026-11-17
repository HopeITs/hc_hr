<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tagging permissions, HR only, like the rest of the applicant group.
 *
 * Tagging one application, tagging many at once and changing the tag list
 * itself are three separate grants: a bulk operation reaches hundreds of
 * records in one click, and renaming a tag relabels every record it is on.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.tag',
        'recruitment-applicants.bulk-tag',
        'recruitment-applicants.manage-tags',
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
