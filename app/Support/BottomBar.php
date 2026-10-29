<?php

namespace App\Support;

use App\Models\User;
use App\Services\WorkInbox;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Support\Facades\View;

/**
 * The phone bar (D2, prompt 10), app style: four shortcuts, BEFORE_NEW of
 * them before the raised + (the New sheet) and the rest after it, then More
 * (the whole menu in a sheet); see Livewire\Layout\BottomNav.
 *
 * What may sit on it: Home, My profile and the dashboard's shortcuts
 * (DashboardLayout::apps(): the pages of the active role's menu and what its
 * New menu may start), keyed like the dashboard's tiles (app.nav.hr.employees,
 * app.new.leave). A person may choose their four in the dashboard's customise
 * mode on a phone (<livewire:layout.bottom-bar-picker>, NavigationMemory). A
 * place whose page the role cannot open is filled from the default bar, the
 * one D2 has always shown: Home, My leaves, Work inbox, Team calendar, My
 * profile, the first four the role has.
 *
 * A shortcut, never the permission: every page keeps its own server check.
 */
final class BottomBar
{
    /** Places a person chooses; + and More are always there besides them. */
    public const SLOTS = 4;

    /** How many of the places sit before the raised + (the rest sit after it). */
    public const BEFORE_NEW = 2;

    public const HOME = 'home';

    public const PROFILE = 'profile';

    /** Icons the menu names that the icon set (components/icons) calls differently. */
    private const ICONS = ['inbox' => 'notification', 'file' => 'document-text'];

    /**
     * The places as the bar shows them: the person's choice still allowed,
     * then the default bar, then (for a role with few defaults) the other
     * shortcuts in menu order; at most SLOTS. The whole catalogue (options())
     * is only worked out when the default bar is not enough on its own.
     *
     * @param  array<string, array<string, mixed>>|null  $options  options() if the caller already has it
     * @return list<array{key: string, label: string, href: string, route: string, icon: string, active: list<string>}>
     */
    public static function slots(User $user, ?array $options = null): array
    {
        $defaults = self::defaults($user);
        $picks = NavigationMemory::bottomBar($user, function () use ($user, &$options): array {
            return $options ??= self::options($user);
        });

        if ($picks === null && count($defaults) >= self::SLOTS) {
            return array_slice(array_values($defaults), 0, self::SLOTS);
        }

        $options ??= self::options($user);
        $keys = array_slice(array_values(array_unique([...($picks ?? []), ...array_keys($defaults), ...array_keys($options)])), 0, self::SLOTS);

        return array_map(fn (string $key) => $options[$key] ?? $defaults[$key], $keys);
    }

    /**
     * Everything this person may put on the bar, keyed: Home, My profile, then
     * the dashboard's shortcuts in menu order (pages, then New actions).
     *
     * @return array<string, array{key: string, label: string, href: string, route: string, icon: string, active: list<string>}>
     */
    public static function options(User $user): array
    {
        $options = [self::HOME => self::home()];

        if ($user->profile) {
            $options[self::PROFILE] = self::profile();
        }

        foreach (app(DashboardLayout::class)->apps($user) as $key => $app) {
            // A New action opens its screen with a dialog or a form; it is never "the page you are on".
            $options[$key] = self::entry($key, $app['label'], $app['route'], $app['icon'], $app['params'] === [] ? [$app['route']] : [], $app['params']);
        }

        return $options;
    }

    /** Is this key a New action (app.new.*) rather than a page? */
    public static function isAction(string $key): bool
    {
        return str_starts_with($key, DashboardLayout::APP_PREFIX.'new.');
    }

    /**
     * The default bar, keyed. Each place carries the check of the menu item it
     * stands for in Navigation::for() (leaves.view-own; an inbox source), so
     * this short list needs no menu of its own; BottomNavTest keeps the two in
     * step.
     *
     * @return array<string, array{key: string, label: string, href: string, route: string, icon: string, active: list<string>}>
     */
    private static function defaults(User $user): array
    {
        $leaves = $user->hasActivePermission('leaves.view-own');
        $p = DashboardLayout::APP_PREFIX;

        return array_filter([
            self::HOME => self::home(),
            $p.'nav.my-space.leaves' => $leaves ? self::entry($p.'nav.my-space.leaves', __('general.my-leaves'), 'leaves.index', 'my-leaves') : null,
            $p.'nav.inbox' => app(WorkInbox::class)->hasSources($user) ? self::entry($p.'nav.inbox', __('general.nav.work-inbox'), 'inbox', 'inbox') : null,
            $p.'nav.my-space.team-calendar' => $leaves ? self::entry($p.'nav.my-space.team-calendar', __('team_calendar.nav'), 'team.calendar', 'calendar') : null,
            self::PROFILE => $user->profile ? self::profile() : null,
        ]);
    }

    private static function home(): array
    {
        return self::entry(self::HOME, __('general.dashboard'), 'dashboard', 'home', ['dashboard', 'employee.dashboard']);
    }

    private static function profile(): array
    {
        return self::entry(self::PROFILE, __('general.my-profile'), 'my-profile', 'user');
    }

    /**
     * @param  list<string>|null  $active  route names on which the place is marked current; defaults to its own route
     * @return array{key: string, label: string, href: string, route: string, icon: string, active: list<string>}
     */
    private static function entry(string $key, string $label, string $route, ?string $icon, ?array $active = null, array $params = []): array
    {
        $icon = self::ICONS[$icon] ?? $icon;

        return [
            'key' => $key,
            'label' => $label,
            'href' => route($route, $params),
            'route' => $route,
            'icon' => $icon && View::exists("components.icons.{$icon}") ? $icon : 'document-text',
            'active' => $active ?? [$route],
        ];
    }
}
