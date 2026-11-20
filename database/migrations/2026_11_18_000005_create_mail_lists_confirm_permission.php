<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * mail-lists.confirm: confirm that people added to a mailing list here were
 * added to the group in the mail provider's control panel. Person-level (see
 * never_granted_to_roles): it belongs to whoever holds that panel, Mai
 * Youssef, not to a role, so it is created here and given to her account
 * alone. If her account is not found it is simply left for Access Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('mail-lists.confirm', 'web');

        User::query()->where('email', 'mai.youssef@hcsyria.org')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'mail-lists.confirm')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
