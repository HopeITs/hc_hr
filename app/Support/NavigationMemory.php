<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\NavigationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Favourites and recents (navigation_preferences): the pages a person starred
 * in the sidebar, and the pages and profiles they opened last; and (prompt 10)
 * the shortcuts they chose for the phone bar (BottomBar).
 *
 * What is stored is only a list of ids. Every read filters it again: pages
 * through the active role's menu (Navigation::shortcuts, the same leaves the
 * sidebar shows), people through DirectoryAccess::constrain, the query form
 * of the check the profile page itself runs. So a page the role lost, or a
 * colleague who left and is now outside the viewer's cell, simply drops out
 * of the list, and comes back if access does.
 */
final class NavigationMemory
{
    public const MAX_FAVOURITES = 8;

    public const MAX_RECENT_PAGES = 8;

    public const MAX_RECENT_PEOPLE = 5;

    /**
     * Starred pages the active role can open, in the person's order.
     *
     * @param  array<string, mixed>  $allowed  Navigation::shortcuts() for the active role
     * @return list<string>
     */
    public static function favourites(User $user, array $allowed): array
    {
        return self::allowedOnly(self::row($user)?->favourites, $allowed);
    }

    /**
     * Star or unstar a page. Only a page of the active role's menu may be
     * starred; a ninth is refused. Returns whether the page is now starred.
     *
     * @param  array<string, mixed>  $allowed
     */
    public static function toggleFavourite(User $user, string $id, array $allowed): bool
    {
        $row = self::row($user, create: true);
        $stored = array_values(array_filter((array) $row->favourites, 'is_string'));

        if (in_array($id, $stored, true)) {
            $row->update(['favourites' => array_values(array_diff($stored, [$id]))]);

            return false;
        }

        // Counted against what the role can see now, so a page lost to a
        // permission change does not hold one of the eight places.
        if (! isset($allowed[$id]) || count(self::allowedOnly($stored, $allowed)) >= self::MAX_FAVOURITES) {
            return false;
        }

        $row->update(['favourites' => [...self::allowedOnly($stored, $allowed), $id]]);

        return true;
    }

    /**
     * Move a starred page to $position (0-based) among the visible ones.
     * Positions out of range are clamped, so a stale drag cannot fail.
     *
     * @param  array<string, mixed>  $allowed
     */
    public static function moveFavourite(User $user, string $id, int $position, array $allowed): void
    {
        $row = self::row($user);
        $visible = self::allowedOnly($row?->favourites, $allowed);
        $from = array_search($id, $visible, true);

        if ($from === false) {
            return;
        }

        array_splice($visible, $from, 1);
        array_splice($visible, max(0, min($position, count($visible))), 0, [$id]);

        $row->update(['favourites' => $visible]);
    }

    /**
     * A menu page was opened. The dashboard is not recorded: it is always
     * one click away (the logo, Home) and would fill the list.
     */
    public static function recordPage(User $user, string $id): void
    {
        $row = self::row($user, create: true);
        $pages = array_values(array_filter((array) $row->recent_pages, 'is_string'));

        if (($pages[0] ?? null) === $id) {
            return; // A reload: nothing to write.
        }

        $pages = array_slice([$id, ...array_diff($pages, [$id])], 0, self::MAX_RECENT_PAGES * 2);
        $row->update(['recent_pages' => array_values($pages)]);
    }

    /** A colleague's profile was opened (after the profile page authorized it). */
    public static function recordPerson(User $user, int $employeeId): void
    {
        $row = self::row($user, create: true);
        $people = array_values(array_filter((array) $row->recent_people, 'is_int'));

        if (($people[0] ?? null) === $employeeId) {
            return;
        }

        $people = array_slice([$employeeId, ...array_diff($people, [$employeeId])], 0, self::MAX_RECENT_PEOPLE * 2);
        $row->update(['recent_people' => array_values($people)]);
    }

    /**
     * Recently opened pages the active role can still open, most recent first.
     * A few more ids than shown are kept, so one lost page does not leave a gap.
     *
     * @param  array<string, mixed>  $allowed
     * @return list<string>
     */
    public static function recentPages(User $user, array $allowed): array
    {
        return array_slice(self::allowedOnly(self::row($user)?->recent_pages, $allowed), 0, self::MAX_RECENT_PAGES);
    }

    /**
     * Recently opened profiles the viewer may still see, most recent first.
     * $query is the caller's Directory-tier select (columns, eager loads);
     * the scope is applied here, on every read, never trusted from the write.
     *
     * @return Collection<int, Employee>
     */
    public static function recentPeople(User $user, ?Builder $query = null): Collection
    {
        $ids = array_values(array_filter((array) self::row($user)?->recent_people, 'is_int'));

        if ($ids === []) {
            return new Collection;
        }

        $query ??= Employee::query()->select(DirectoryAccess::COLUMNS);

        return DirectoryAccess::constrain($query, $user)
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Employee $employee) => array_search($employee->id, $ids, true))
            ->take(self::MAX_RECENT_PEOPLE)
            ->values();
    }

    /**
     * The phone bar the person chose, in their order, kept to what they may
     * still use; null when they never chose one (the bar shows its default).
     * Working out what is allowed costs the whole menu, so it is a callable,
     * called only when something is stored.
     *
     * @param  callable(): array<string, mixed>  $allowed  BottomBar::options() for the active role
     * @return list<string>|null
     */
    public static function bottomBar(User $user, callable $allowed): ?array
    {
        $stored = self::row($user)?->bottom_bar;

        return is_array($stored) ? self::allowedOnly($stored, $allowed()) : null;
    }

    /**
     * Keep these shortcuts for the phone bar, in this order. Exactly $slots
     * different ids, each one the person may use; anything else is refused
     * and nothing is written. Returns whether it was kept.
     *
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $allowed
     */
    public static function setBottomBar(User $user, array $ids, array $allowed, int $slots): bool
    {
        $ids = array_values($ids);

        foreach ($ids as $id) {
            if (! is_string($id) || ! isset($allowed[$id])) {
                return false;
            }
        }

        if (count($ids) !== $slots || count(array_unique($ids)) !== $slots) {
            return false;
        }

        self::row($user, create: true)->update(['bottom_bar' => $ids]);

        return true;
    }

    /** Back to the default phone bar. */
    public static function resetBottomBar(User $user): void
    {
        self::row($user)?->update(['bottom_bar' => null]);
    }

    /** @return list<string> */
    private static function allowedOnly(?array $ids, array $allowed): array
    {
        return array_values(array_unique(array_filter((array) $ids, fn ($id) => is_string($id) && isset($allowed[$id]))));
    }

    private static function row(User $user, bool $create = false): ?NavigationPreference
    {
        return $create
            ? NavigationPreference::query()->firstOrCreate(['user_id' => $user->id])
            : NavigationPreference::query()->where('user_id', $user->id)->first();
    }
}
