<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * config('access-control.role_baselines') reaches a role only when the role is
 * created (App\Models\Role::booted), so a permission added to a baseline later
 * never reached the roles already in production: the managers were missing
 * contracts.view (their direct reports' contracts) and the recruitment
 * vacancy / campaign views, the general manager badges.view.
 *
 * Additive only: nothing a role holds is taken away, so a grant or a removal
 * made from Access Management stays as it is — except that a permission
 * removed by hand from a baseline role comes back if it is in the baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('access-control.role_baselines', []) as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $role->givePermissionTo(array_map(
                fn (string $name) => Permission::findOrCreate($name, 'web'),
                $permissions,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Additive baseline grants are intentionally preserved on rollback.
    }
};
