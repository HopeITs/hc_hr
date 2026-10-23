<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Who may see a colleague in the in-app directory, and how much of them.
 *
 * The rules are docs/ux/access-policy.md §4.3 and §4.4 as signed off on
 * 2026-10-20 (§9). They are read from the viewer's *active* role, and "own
 * city" means the employee's city_id is in the active role's activeCityIds()
 * (null, i.e. no boundary, for every role except the regional HR-<city>
 * roles).
 *
 * Directory tier (D): photo, name, current position and its program,
 * department, center and city, work contact, board badge, manager and direct
 * reports. Existence of an *active* colleague is not confidential, so every
 * signed-in user sees every active colleague in every city at this tier.
 * A *former* (inactive) employee's existence is withheld from everyone but
 * the general manager, HR-SYR, super-admin and the regional HR of that city.
 *
 * Colleague records tier (R): career history without pay, education,
 * experience, skills, courses. Self; a manager for their DIRECT reports only
 * (the technical-manager line no longer exists, DEC-05 revision); the general
 * manager, HR-SYR and super-admin everywhere; regional HR in its own cities.
 *
 * Private (P) and financial (F) are unchanged and stay where they were
 * (EmployeeProfile, User::canViewSalary()).
 *
 * Both the single-record check (canSeeDirectory) and the query constraint
 * (constrain) live here, side by side, so that a list and the record it links
 * to can never disagree. The single-record check answers an active employee
 * without touching the roles table, which is what keeps the photo endpoint
 * cheap on pages with a hundred faces.
 */
final class DirectoryAccess
{
    /**
     * The employee columns a Directory-tier surface needs, and nothing else.
     * Surfaces that only ever show D select these, so private and financial
     * columns are not even loaded for them.
     */
    public const COLUMNS = [
        'id', 'city_id', 'username',
        'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar',
        'work_phone', 'whatsapp_work', 'telegram_work',
        'is_active', 'is_board_member',
    ];

    /** The linked account, limited to the work email the directory shows. */
    public const ACCOUNT_COLUMNS = 'account:id,employee_id,email';

    /** May $user see $employee at the Directory tier (at least D)? */
    public static function canSeeDirectory(User $user, Employee $employee): bool
    {
        if (self::isSelf($user, $employee) || $employee->is_active) {
            return true;
        }

        // A former employee.
        if (self::seesFormerEverywhere($user)) {
            return true;
        }

        return $user->activeRoleIs('HR') && $user->canAccessCity($employee->city_id);
    }

    /** May $user see $employee's colleague records (R)? */
    public static function canSeeRecords(User $user, Employee $employee): bool
    {
        if (! self::canSeeDirectory($user, $employee)) {
            return false;
        }

        if (self::isSelf($user, $employee) || $user->activeRoleIs('general-manager', 'super-admin')) {
            return true;
        }

        if ($user->activeRoleIs('HR')) {
            return $user->canAccessCity($employee->city_id);
        }

        if ($user->activeRoleIs('manager')) {
            return self::isDirectReport($user, $employee);
        }

        return false;
    }

    /**
     * Restrict an employee query to the rows $user may see at the Directory
     * tier. The query form of canSeeDirectory(); keep the two in step.
     */
    public static function constrain(Builder $employees, ?User $user): Builder
    {
        if (! $user) {
            return $employees->whereRaw('1 = 0');
        }

        if (self::seesFormerEverywhere($user)) {
            return $employees;
        }

        $formerCityIds = $user->activeRoleIs('HR') ? ($user->activeCityIds() ?? []) : [];

        return $employees->where(function (Builder $visible) use ($user, $formerCityIds): void {
            $visible->where($visible->qualifyColumn('is_active'), true);

            if ($formerCityIds !== []) {
                $visible->orWhereIn($visible->qualifyColumn('city_id'), $formerCityIds);
            }

            if ($user->employee_id) {
                $visible->orWhere($visible->qualifyColumn('id'), $user->employee_id);
            }
        });
    }

    /**
     * Eager-load constraint for a position's holders (activeContracts): only
     * the contracts whose employee $user may see at the Directory tier, so an
     * org-chart card, a drawer's manager or a report never names a former
     * employee to someone who may not know they existed.
     */
    public static function holders(?User $user): Closure
    {
        return fn (Relation|Builder $contracts) => $contracts->whereHas(
            'employee',
            fn (Builder $employee) => self::constrain($employee, $user),
        );
    }

    /**
     * Eager-load definition for a holder's own record: Directory columns
     * plus the photo.
     */
    public static function holderEmployee(): Closure
    {
        return fn (Relation|Builder $employee) => $employee->select(self::COLUMNS)->with('media');
    }

    /**
     * Does $employee currently report directly to $user?
     *
     * Same notion of "their positions" as Contract::scopeVisibleTo(): the
     * positions on the manager's own contracts running today, so someone who
     * has left a managerial post stops seeing that team. Unlike that scope it
     * follows the direct line only, because the technical manager no longer
     * exists (access-policy.md §9, DEC-05 revision). The report must also hold
     * a contract running today on a position reporting to one of them.
     */
    public static function isDirectReport(User $user, Employee $employee): bool
    {
        if (! $user->employee_id) {
            return false;
        }

        $managedPositionIds = Contract::query()
            ->activeNow()
            ->where('employee_id', $user->employee_id)
            ->pluck('position_id')
            ->all();

        if ($managedPositionIds === []) {
            return false;
        }

        return Contract::query()
            ->activeNow()
            ->where('employee_id', $employee->getKey())
            ->whereHas('position', fn (Builder $position) => $position->whereIn('direct_manager_id', $managedPositionIds))
            ->exists();
    }

    /** Roles that see former employees in every city: GM, HR-SYR, super-admin. */
    public static function seesFormerEverywhere(User $user): bool
    {
        if ($user->activeRoleIs('general-manager', 'super-admin')) {
            return true;
        }

        return $user->activeRoleIs('HR') && $user->activeCityIds() === null;
    }

    private static function isSelf(User $user, Employee $employee): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $employee->getKey();
    }
}
