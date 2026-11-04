<?php

namespace App\Support\News;

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
