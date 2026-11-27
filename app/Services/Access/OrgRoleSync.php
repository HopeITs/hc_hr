<?php

namespace App\Services\Access;

use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleSuspension;
use App\Notifications\RolesSuspendedForNoContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\Activity;

/**
 * Keeps the roles that follow from the org chart and the contracts in step
 * with them (decided 2026-11-24):
 *
 *  - every user of an active employee holds `employee`;
 *  - `manager` is held exactly by whoever holds, on a running contract, a
 *    position that a staffed position reports to (direct_manager_id) — the
 *    same line leave decisions already follow. A general manager is not
 *    given it: that role already covers everyone;
 *  - a raised role (config('access-control.suspend_without_contract')) is
 *    stood down while its holder has no running contract, kept in
 *    user_role_suspensions, and given back when a contract runs again. HR
 *    Syria is told either way.
 *
 * HR, general-manager and administrator roles are granted by hand from
 * Access Management; this stands them down and back up, and (SeatSuccession)
 * gives the HR and general-manager ones of a position's last holder to whoever
 * holds it next.
 *
 * Runs for one user at sign-in and for everyone nightly (access:sync-org-roles).
 * A user without an employee record, or whose employee is inactive, is left
 * alone: they cannot sign in, and AccessRevoker already stood them down.
 */
class OrgRoleSync
{
    /** @var array<string, int>|null */
    private ?array $roleIds = null;

    /**
     * @return array{added: list<string>, removed: list<string>, suspended: list<string>, restored: list<string>, inherited: list<string>}
     */
    public function syncUser(User $user, bool $notify = true): array
    {
        $changes = ['added' => [], 'removed' => [], 'suspended' => [], 'restored' => [], 'inherited' => []];
        $employee = $user->profile;

        if ($employee === null || ! $employee->is_active) {
            return $changes;
        }

        DB::transaction(function () use ($user, &$changes): void {
            $held = $user->roles()->pluck('roles.name')->all();
            $running = $this->runningPositionIds($user->employee_id);

            if (! in_array('employee', $held, true) && $this->roleId('employee') !== null) {
                $user->assignRole('employee');
                $changes['added'][] = 'employee';
            }

            $shouldManage = $running !== []
                && ! in_array('general-manager', $held, true)
                && $this->leadsAStaffedPosition($running);

            if ($shouldManage && ! in_array('manager', $held, true) && $this->roleId('manager') !== null) {
                $user->assignRole('manager');
                $changes['added'][] = 'manager';
            } elseif (! $shouldManage && in_array('manager', $held, true)) {
                $user->removeRole('manager');
                $changes['removed'][] = 'manager';
            }

            if ($running === []) {
                foreach ($held as $name) {
                    if ($this->isSuspendable($name)) {
                        UserRoleSuspension::query()->firstOrCreate(
                            ['user_id' => $user->id, 'role_id' => $this->roleId($name)],
                            ['reason' => UserRoleSuspension::REASON_NO_CONTRACT, 'suspended_at' => now()],
                        );
                        $user->removeRole($name);
                        $changes['suspended'][] = $name;
                    }
                }
            } else {
                UserRoleSuspension::query()->with('role')->where('user_id', $user->id)->get()
                    ->each(function (UserRoleSuspension $suspension) use ($user, &$changes): void {
                        if ($suspension->role !== null && ! $user->hasRole($suspension->role->name)) {
                            $user->assignRole($suspension->role->name);
                            $changes['restored'][] = $suspension->role->name;
                        }
                        $suspension->delete();
                    });

                // The raised roles of whoever held these positions before (SeatSuccession).
                $changes['inherited'] = app(SeatSuccession::class)->inheritRoles($user);
            }

            if ($changes['removed'] !== [] || $changes['suspended'] !== []) {
                $this->keepOneRoleActive($user);
            }
        });

        $user->unsetRelation('roles');
        $user->forgetRemembered();

        if (array_filter($changes) !== []) {
            Activity::useLog('security')
                ->performedOn($user)
                ->withProperties($changes)
                ->log('roles_synced_with_org_chart');
        }

        if ($notify && ($changes['suspended'] !== [] || $changes['restored'] !== [] || $changes['inherited'] !== [])) {
            $this->notifyHrSyria($user, $changes['suspended'], $changes['restored'], $changes['inherited']);
        }

        return $changes;
    }

    /**
     * @return array<string, int> how many users had each kind of change
     */
    public function syncAll(): array
    {
        $totals = ['added' => 0, 'removed' => 0, 'suspended' => 0, 'restored' => 0, 'inherited' => 0];

        User::query()
            ->whereNotNull('employee_id')
            ->whereHas('profile', fn ($q) => $q->where('is_active', true))
            ->with('profile')
            ->chunkById(200, function (Collection $users) use (&$totals): void {
                foreach ($users as $user) {
                    foreach ($this->syncUser($user) as $kind => $roles) {
                        $totals[$kind] += $roles === [] ? 0 : 1;
                    }
                }
            });

        return $totals;
    }

    /** @return list<int> positions this employee holds on a running, non-draft contract */
    private function runningPositionIds(?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }

        return Contract::query()
            ->activeNow()
            ->where('employee_id', $employeeId)
            ->where('type', '!=', ContractType::Draft)
            ->pluck('position_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<int> $positionIds */
    private function leadsAStaffedPosition(array $positionIds): bool
    {
        return Position::query()
            ->where('is_active', true)
            ->whereIn('direct_manager_id', $positionIds)
            ->whereNotIn('id', $positionIds)
            ->whereHas('contracts', fn ($q) => $q->activeNow()->where('type', '!=', ContractType::Draft))
            ->exists();
    }

    private function isSuspendable(string $roleName): bool
    {
        foreach (config('access-control.suspend_without_contract', []) as $entry) {
            if ($entry === $roleName || ($entry === 'HR-*' && Str::startsWith($roleName, 'HR-'))) {
                return true;
            }
        }

        return false;
    }

    /** After a role was taken away, make sure one of the remaining ones is the active one. */
    private function keepOneRoleActive(User $user): void
    {
        $user->unsetRelation('roles');

        if ($user->roles()->wherePivot('is_active', true)->exists()) {
            return;
        }

        if ($roleId = $user->defaultLoginRoleId()) {
            $user->roles()->updateExistingPivot($roleId, ['is_active' => true]);
        }
    }

    /**
     * @param  list<string>  $suspended
     * @param  list<string>  $restored
     * @param  list<string>  $inherited
     */
    private function notifyHrSyria(User $user, array $suspended, array $restored, array $inherited = []): void
    {
        $recipients = User::query()
            ->whereKeyNot($user->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'HR-SYR'))
            ->whereHas('profile', fn ($q) => $q->where('is_active', true))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new RolesSuspendedForNoContract($user->profile, $suspended, $restored, $inherited));
        }
    }

    private function roleId(string $name): ?int
    {
        $this->roleIds ??= Role::query()->where('guard_name', 'web')->pluck('id', 'name')->map(fn ($id) => (int) $id)->all();

        return $this->roleIds[$name] ?? null;
    }
}
