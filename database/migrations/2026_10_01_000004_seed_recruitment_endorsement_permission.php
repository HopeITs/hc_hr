<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the endorsement permission and manager applicant-view access.
 *
 * 'recruitment-applicants.endorse' allows HR and managers to cast a shortlist
 * vote on an application they can see. 'recruitment-applicants.view' is also
 * granted to manager/general-manager so they can access the applicants page.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $endorse = Permission::findOrCreate('recruitment-applicants.endorse', 'web');
        $view    = Permission::findOrCreate('recruitment-applicants.view', 'web');

        // HR roles already hold recruitment-applicants.* from previous
        // migrations; give them endorse as well.
        Role::query()
            ->where('name', 'like', 'HR-%')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo([$endorse, $view]));

        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo([$endorse, $view]);
        }

        // Managers see and endorse applicants for campaigns in their scope.
        foreach (['manager', 'general-manager'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo([$endorse, $view]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'recruitment-applicants.endorse')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
