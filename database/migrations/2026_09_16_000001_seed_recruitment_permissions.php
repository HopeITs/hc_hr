<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the recruitment permissions and hands each role its baseline.
 *
 * Follows the same shape as seed_access_control_permissions, with one
 * difference that matters: two permissions are created but attached to no role
 * at all. 'approve-finance' and 'submit-without-manager' identify specific
 * people, so a role carrying either would turn every one of its holders into
 * the organization's financial approver, or let them skip manager approval.
 * They are granted per user from Access Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = $this->recruitmentPermissionNames();

        if ($names === []) {
            return;
        }

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Also subtract what access-control keeps from every bulk grant: this
        // reads the live catalog, so it would otherwise pick up permissions
        // added to a recruitment group long after it first ran.
        $userLevel = [...$this->userLevelPermissionNames(), ...AccessPermissionCatalog::personLevelNames()];
        $roleGrantable = array_values(array_diff($names, $userLevel));

        // super-admin and HR-SYR hold everything the system offers.
        foreach (config('access-control.administrator_roles', []) as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($roleGrantable);
        }

        // Governorate HR runs recruitment inside its own city, direct openings
        // included — a direct opening by a governorate still passes through
        // HR Syria before it is final, so the permission alone decides nothing.
        Role::query()
            ->where('name', 'like', 'HR-%')
            ->where('name', '!=', 'HR-SYR')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($roleGrantable));

        // manager and general-manager take their recruitment baseline from
        // config, alongside the permissions they already had.
        foreach (config('access-control.role_baselines', []) as $roleName => $permissionNames) {
            $recruitment = array_values(array_filter(
                $permissionNames,
                fn (string $name): bool => str_starts_with($name, 'recruitment-'),
            ));

            if ($recruitment === []) {
                continue;
            }

            Role::findOrCreate($roleName, 'web')->givePermissionTo($recruitment);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->recruitmentPermissionNames())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, string>
     */
    private function recruitmentPermissionNames(): array
    {
        $groups = collect(config('access-control.groups', []))
            ->filter(fn (array $actions, string $group): bool => str_starts_with($group, 'recruitment-'));

        return $groups
            ->flatMap(fn (array $actions, string $group): array => array_map(
                fn (string $action): string => "{$group}.{$action}",
                $actions,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function userLevelPermissionNames(): array
    {
        return array_values(array_filter(config('recruitment.user_level_permissions', [])));
    }
};
