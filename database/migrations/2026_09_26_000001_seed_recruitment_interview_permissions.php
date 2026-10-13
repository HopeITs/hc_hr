<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 Task 7: scheduling interviews is HR's, like the rest of the
 * applicant group — creating, editing, rescheduling, cancelling and
 * recording the outcome of an interview all sit behind this one grant.
 *
 * Participants get nothing here on purpose. A manager or technical
 * interviewer sees an interview because they are on it, which is checked
 * against the participants table, not against a permission a role holds.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.schedule-interviews',
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
