<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Applicant permissions go to HR only.
 *
 * Managers hold recruitment-campaigns.view to follow their own campaigns, but
 * applicant records, CVs and internal notes are HR's. No role baseline carries
 * these, so manager and general-manager get none of them here.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = array_map(
            fn (string $action): string => "recruitment-applicants.{$action}",
            config('access-control.groups.recruitment-applicants', []),
        );

        if ($names === []) {
            return;
        }

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Read from live config, so a permission added to the group after this
        // migration first ran — export, erasure — would be handed to every HR
        // role on any database built from scratch. The ones held back from
        // roles are created above and granted here to nobody.
        $grantable = array_values(array_diff($names, AccessPermissionCatalog::personLevelNames()));

        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($grantable);
        }

        Role::query()
            ->where('name', 'like', 'HR-%')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($grantable));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'like', 'recruitment-applicants.%')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
