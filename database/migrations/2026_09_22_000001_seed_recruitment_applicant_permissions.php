<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the Phase 2 applicant/application permissions and hands them to HR.
 *
 * Mirrors seed_recruitment_permissions, but deliberately narrower: applicant
 * PII is more sensitive than a request or a vacancy, so unlike the Phase 1
 * groups (which also reached manager/general-manager via role_baselines),
 * these two groups go only to super-admin, HR-SYR, and governorate HR.
 *
 * // TODO (Business Rule): the business team has not yet decided whether
 * // program/project managers should see applicants for campaigns raised from
 * // their own requests. Until that is confirmed, ATS access is HR-only —
 * // widen this migration (or add a follow-up one) once the rule is settled.
 */
return new class extends Migration
{
    private const GROUPS = ['recruitment-applicants', 'recruitment-applications'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = $this->permissionNames();

        if ($names === []) {
            return;
        }

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Live config again: keep whatever is held back from roles (export,
        // erasure) out of the grant, or a fresh database would hand it to HR.
        $grantable = array_values(array_diff($names, AccessPermissionCatalog::personLevelNames()));

        // super-admin and HR-SYR hold everything the system offers.
        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($grantable);
        }

        // Governorate HR runs the ATS for campaigns raised inside its own
        // city, same reach it already has over vacancies and campaigns.
        Role::query()
            ->where('name', 'like', 'HR-%')
            ->where('name', '!=', 'HR-SYR')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($grantable));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->permissionNames())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        $groups = collect(config('access-control.groups', []))
            ->filter(fn (array $actions, string $group): bool => in_array($group, self::GROUPS, true));

        return $groups
            ->flatMap(fn (array $actions, string $group): array => array_map(
                fn (string $action): string => "{$group}.{$action}",
                $actions,
            ))
            ->values()
            ->all();
    }
};
