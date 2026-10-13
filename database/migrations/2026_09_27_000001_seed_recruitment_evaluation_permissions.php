<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 Task 8: interview evaluations, HR only.
 *
 * - view-evaluations: the aggregated view of every reviewer's evaluation, for
 *   interviews in the user's recruitment scope. Without it a user sees at
 *   most their own evaluation — never pros, cons, notes or recommendations
 *   written by someone else.
 * - correct-evaluations: correcting a mistake in someone else's evaluation,
 *   or withdrawing it, always with a written reason kept in its history.
 *
 * Writing one's own evaluation needs no permission: it comes from being on
 * the interview, like seeing the interview does.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.view-evaluations',
        'recruitment-applicants.correct-evaluations',
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
