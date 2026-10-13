<?php

namespace App\Support\Permissions;

/**
 * Counts how often Spatie's permission cache has been cleared.
 *
 * Spatie clears it on every grant, revocation, role assignment and removal.
 * User memoizes answers about its roles and permissions for the life of the
 * instance; a new generation tells it those answers may have gone stale.
 * AppServiceProvider bumps it when the cache key is forgotten.
 */
final class PermissionCacheGeneration
{
    private static int $generation = 0;

    public static function current(): int
    {
        return self::$generation;
    }

    public static function bump(): void
    {
        self::$generation++;
    }
}
