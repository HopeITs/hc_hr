<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 Task 11: candidate data export and erasure on request.
 *
 * Deliberately NOT granted to any role, administrator roles and every HR-*
 * role included. Both permissions sit in config('access-control.
 * never_granted_to_roles'), so no bulk grant reaches them, and both are read
 * with User::hasExplicitPermission(), which does not treat an administrator
 * role as holding everything. They are handed out from Access Management to
 * the people who are answerable for candidate data.
 */
return new class extends Migration
{
    private const NAMES = [
        'recruitment-applicants.export',
        'recruitment-applicants.request-erasure',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NAMES as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NAMES)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
