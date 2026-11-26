<?php

namespace App\Support\News;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Models\Contract;
use App\Models\News;
use App\Models\User;

/**
 * Who may manage news, in one place (it was copied into NewsCreate and
 * NewsTable). HR, super-admin and general-manager manage every item; a
 * manager creates items and manages their own. Reading an item is
 * canView(): managers of it always, anyone else only when it is released
 * and its audience includes them.
 */
final class NewsAccess
{
    public static function canManageAll(?User $user): bool
    {
        return (bool) $user?->activeRoleIs('HR', 'super-admin', 'general-manager');
    }

    public static function canCreate(?User $user): bool
    {
        return self::canManageAll($user) || (bool) $user?->activeRoleIs('manager');
    }

    public static function canManage(?User $user, News $news): bool
    {
        return self::canManageAll($user)
            || ((bool) $user?->activeRoleIs('manager') && (int) $news->created_by === (int) $user->getAuthIdentifier());
    }

    /**
     * Who publishes without asking anyone (HR-040): the roles that manage every
     * item. A manager publishes to their own team only; anything wider waits
     * for HR (needsApproval()).
     */
    public static function publishesDirectly(?User $user): bool
    {
        return self::canManageAll($user);
    }

    /**
     * A manager's team, as employee ids: the people on positions that report
     * to the manager's current positions (Contract::scopeVisibleTo, the same
     * rule as the attendance Roster), plus the manager themself.
     *
     * @return list<int>
     */
    public static function teamEmployeeIds(?User $user): array
    {
        if ($user === null || ! $user->activeRoleIs('manager')) {
            return [];
        }

        return $user->remember('news:team', function () use ($user): array {
            $ids = Contract::query()->visibleTo($user)->activeNow()->pluck('employee_id');

            if ($user->employee_id) {
                $ids->push($user->employee_id);
            }

            return $ids->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        });
    }

    /**
     * Would publishing to this audience need HR's approval? Never for the roles
     * that publish directly; for anyone else, unless the audience is a list of
     * people who are all in the author's team.
     *
     * @param  array<int|string>  $ids
     */
    public static function needsApproval(?User $user, AudienceType $audience, array $ids): bool
    {
        if (self::publishesDirectly($user)) {
            return false;
        }

        if ($audience !== AudienceType::Employees) {
            return true;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === [] || array_diff($ids, self::teamEmployeeIds($user)) !== [];
    }

    /**
     * May $user approve or reject $news? HR of the author's city (HR Syria sees
     * every city) and the administrators; only while it is pending.
     */
    public static function canApprove(?User $user, News $news): bool
    {
        if ($user === null || $news->status !== NewsStatus::Pending) {
            return false;
        }

        if ($user->activeRoleIs(...config('access-control.administrator_roles', []))) {
            return true;
        }

        return $user->activeRoleIs('HR')
            && $user->canAccessCity($news->creator?->profile?->city_id);
    }

    /** May $user open the published details page of $news? */
    public static function canView(?User $user, News $news): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::canManage($user, $news)) {
            return true;
        }

        return News::query()->whereKey($news->getKey())->released()->visibleTo($user)->exists();
    }
}
