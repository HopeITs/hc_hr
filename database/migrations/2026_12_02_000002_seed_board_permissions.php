<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * board-meetings.manage (the chair: schedules, moves, cancels, decides absence requests) and
 * board-meetings.write-minutes (the secretary: attendance and the minutes). Both name a person's
 * job, not a role's, so neither goes to any role: they are granted per user from Access Management.
 * The members themselves need no permission: being a current board member on the employee record is enough.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['board-meetings.manage', 'board-meetings.write-minutes'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
