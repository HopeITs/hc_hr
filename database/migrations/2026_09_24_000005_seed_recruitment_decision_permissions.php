<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Decision permissions, HR only, like the rest of the applicant group.
 *
 * Accepting a candidate is a hiring decision, not a screening one, so it is
 * granted on top of change-status rather than implied by it. Rejecting many
 * at once — and emailing every one of them — is its own grant for the same
 * reason bulk tagging is: one click reaches hundreds of people. Managers get
 * neither by default; an administrator grants them per role.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.accept',
        'recruitment-applicants.bulk-reject',
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
