<?php

namespace App\Support\Dashboard;

use App\Livewire\Dashboard\BadgesCard;
use App\Livewire\Dashboard\BirthdaysCard;
use App\Livewire\Dashboard\CalendarCard;
use App\Livewire\Dashboard\ChecklistCard;
use App\Livewire\Dashboard\ClockCard;
use App\Livewire\Dashboard\ContractTypeEmployeesStatistics;
use App\Livewire\Dashboard\DecisionsCard;
use App\Livewire\Dashboard\DepartmentEmployeeStatistics;
use App\Livewire\Dashboard\EmployeeNotesCard;
use App\Livewire\Dashboard\JobLevelEmployeeStatistics;
use App\Livewire\Dashboard\LateCard;
use App\Livewire\Dashboard\LeaveBalanceCard;
use App\Livewire\Dashboard\MyLeavesCard;
use App\Livewire\Dashboard\NewsCard;
use App\Livewire\Dashboard\NextHolidayCard;
use App\Livewire\Dashboard\NoLeavesEmployeesStatistics;
use App\Livewire\Dashboard\PendingRequestsCard;
use App\Livewire\Dashboard\SalaryPerEmployeeStatistics;
use App\Livewire\Dashboard\SoonEndingContractsCard;
use App\Livewire\Dashboard\TeamCard;
use App\Livewire\Dashboard\UpcomingEventsCard;
use App\Livewire\Dashboard\UpcomingLeavesCard;
use App\Livewire\Dashboard\WhoIsOutCard;
use App\Livewire\News\TodayEventsCard;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * The dashboard as a home screen (ported from media-request): a grid of cells
 * holding widgets (notes, calendar, team...) and shortcuts (an icon that opens
 * a page).
 *
 * A size is "CxR": columns x rows of grid cells (12 columns on a wide screen,
 * 8 on a computer, 4 on a phone; a tile wider than the grid takes its full
 * width). Each widget may be any size from one cell up to its own maximum:
 * below the smallest size it can show itself at ("card" in bounds()) it is
 * drawn as an icon that opens it in a side panel. A shortcut is 1x1 up to
 * 4x4: made big (2x2 and up) it becomes a small card with its data (AppCard).
 *
 * A layout is an ordered list of {key, size, pinned, hidden, style?, fixed?}
 * (fixed: sized by hand, shown at exactly that size, never grown into gaps). Whatever
 * is stored is cleaned against this catalogue every time it is read, so a tile
 * that was removed, a size out of bounds, or a tile the person may not use can
 * never come back from a stale or edited layout.
 *
 * Shortcuts are not a list kept here: they are the pages of the active role's
 * menu (Navigation::shortcuts) and its New actions (Navigation::newActions),
 * so a page the role cannot open is never a tile, and a page added to the menu
 * is a tile without touching this class.
 */
class DashboardLayout
{
    public const MAX_ITEMS = 60;

    /** Widgets: key => [icon, min [cols, rows], max [cols, rows], default [cols, rows], shown by default] */
    public const WIDGETS = [
        // Were fixed above the grid (prompt 7); tiles now, to move, resize, pin or hide (media-request b183c0c).
        'late' => ['clock', [3, 1], [12, 3], [12, 1], true],
        // The work inbox: the decisions, then the rest of the inbox (it took over "For you now").
        'decide' => ['inbox', [3, 2], [12, 6], [6, 3], true],
        'my-leaves' => ['my-leaves', [3, 1], [12, 2], [5, 1], true],
        'notes' => ['edit', [2, 2], [12, 8], [5, 3], true],
        'team' => ['users', [3, 2], [12, 8], [3, 4], true],
        'news' => ['newspaper', [3, 3], [12, 8], [3, 6], true],
        'calendar' => ['calendar', [3, 3], [12, 8], [5, 3], true],
        'upcoming-leaves' => ['calendar', [3, 2], [12, 8], [5, 3], true],
        'today-events' => ['cake', [3, 2], [12, 8], [4, 3], true],
        'upcoming-events' => ['calendar', [3, 2], [12, 8], [4, 3], true],
        'pending-requests' => ['clipboard-list', [3, 2], [12, 8], [4, 3], true],
        'checklist' => ['checklist', [3, 2], [12, 8], [4, 3], true],
        'badges' => ['badges', [3, 2], [12, 8], [4, 3], true],
        // From the general statistics page: not on today's dashboard, so offered in the gallery only.
        'soon-ending-contracts' => ['contracts', [3, 2], [12, 8], [4, 3], false],
        'stats-department' => ['statistics', [3, 2], [12, 6], [5, 3], false],
        'stats-job-level' => ['statistics', [3, 2], [12, 6], [5, 3], false],
        'stats-contract-type' => ['statistics', [3, 2], [12, 6], [3, 3], false],
        'stats-no-leaves' => ['statistics', [3, 2], [12, 8], [3, 3], false],
        'stats-salary' => ['statistics', [3, 2], [12, 6], [3, 3], false],
        // Prompt 8. Shown by default for some role families only (FAMILY_WIDGETS), else in the gallery.
        'who-is-out' => ['users', [2, 1], [12, 6], [3, 2], false],
        'next-holiday' => ['calendar', [2, 1], [6, 3], [2, 2], false],
        'leave-balance' => ['my-leaves', [2, 2], [6, 4], [2, 2], false],
        'birthdays' => ['cake', [2, 2], [12, 6], [3, 2], false],
        // Damascus time and the viewer's working day: for everyone, in the gallery (not on today's dashboard).
        'clock' => ['clock', [2, 1], [12, 6], [2, 2], false],
    ];

    /**
     * The Livewire component that draws each widget: the one place a key meets
     * its component (index.blade.php mounts it lazily inside the tile). A
     * widget is a WIDGETS entry, a line here, and its component, which uses
     * IsDashboardTile for its loading placeholder. A widget without a line
     * here is kept in the layout but never drawn and never offered in Add.
     *
     * @var array<string, class-string<Component>>
     */
    public const COMPONENTS = [
        'late' => LateCard::class,
        'decide' => DecisionsCard::class,
        'my-leaves' => MyLeavesCard::class,
        'notes' => EmployeeNotesCard::class,
        'team' => TeamCard::class,
        'news' => NewsCard::class,
        'calendar' => CalendarCard::class,
        'upcoming-leaves' => UpcomingLeavesCard::class,
        'today-events' => TodayEventsCard::class,
        'upcoming-events' => UpcomingEventsCard::class,
        'pending-requests' => PendingRequestsCard::class,
        'checklist' => ChecklistCard::class,
        'badges' => BadgesCard::class,
        'soon-ending-contracts' => SoonEndingContractsCard::class,
        'stats-department' => DepartmentEmployeeStatistics::class,
        'stats-job-level' => JobLevelEmployeeStatistics::class,
        'stats-contract-type' => ContractTypeEmployeesStatistics::class,
        'stats-no-leaves' => NoLeavesEmployeesStatistics::class,
        'stats-salary' => SalaryPerEmployeeStatistics::class,
        'who-is-out' => WhoIsOutCard::class,
        'next-holiday' => NextHolidayCard::class,
        'leave-balance' => LeaveBalanceCard::class,
        'birthdays' => BirthdaysCard::class,
        'clock' => ClockCard::class,
    ];

    /**
     * Tiles a phone does not draw, to keep its home screen light (media-request
     * 88a5e75): the today's-events slideshow and the statistics charts. Only
     * what is shown changes; the saved layout keeps them for a larger screen.
     */
    public const PHONE_HIDDEN = ['today-events', 'stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'stats-salary'];

    /** Tiles that come in more than one look: key => allowed looks, the first is the default. */
    public const LOOKS = ['clock' => ['digital', 'analog']];

    /** Prefix of every shortcut key: "app.<navigation item id>", e.g. app.nav.hr.employees, app.new.leave. */
    public const APP_PREFIX = 'app.';

    /** Were fixed above the grid: a layout saved before they were tiles gets them first. */
    private const ALERTS = ['late', 'decide'];

    /**
     * The page a widget opens on double-click: menu entries (Navigation ids),
     * the first one the role's menu has wins. Going through apps() keeps it to
     * the pages this person may open; a widget not here has no page.
     */
    private const PAGES = [
        'late' => ['nav.inbox', 'nav.hr.contracts', 'nav.my-team.contracts'],
        'decide' => ['nav.inbox'],
        'pending-requests' => ['nav.my-team.hand-out', 'nav.inbox'],
        'my-leaves' => ['nav.my-space.leaves'],
        'leave-balance' => ['nav.my-space.leaves'],
        'calendar' => ['nav.my-space.team-calendar'],
        'upcoming-leaves' => ['nav.my-space.team-calendar', 'nav.my-team.leaves', 'nav.hr.leaves'],
        'who-is-out' => ['nav.my-space.team-calendar', 'nav.my-team.leaves', 'nav.hr.leaves'],
        'checklist' => ['nav.my-space.items'],
        'badges' => ['nav.hr.badges'],
        'news' => ['nav.news'],
        'soon-ending-contracts' => ['nav.hr.contracts', 'nav.my-team.contracts'],
        'stats-department' => ['nav.hr.statistics.general'],
        'stats-job-level' => ['nav.hr.statistics.general'],
        'stats-contract-type' => ['nav.hr.statistics.general'],
        'stats-no-leaves' => ['nav.hr.statistics.general'],
        'stats-salary' => ['nav.hr.statistics.general'],
        'next-holiday' => ['nav.my-team.holidays', 'nav.basics.holidays'],
    ];

    /**
     * Shortcuts shown by default, per role family, after today's cards (so the
     * top of the page is what everyone already knows). Any other shortcut the
     * role may open is in the gallery, hidden.
     */
    private const DEFAULT_APPS = [
        'employee' => ['nav.my-space.leaves', 'nav.my-space.team-calendar', 'nav.my-space.items'],
        'manager' => ['nav.inbox', 'nav.my-team.leaves', 'nav.my-team.contracts', 'nav.my-space.leaves'],
        'hr-city' => ['nav.inbox', 'nav.hr.employees', 'nav.hr.contracts', 'nav.hr.leaves'],
        'hr-national' => ['nav.inbox', 'nav.hr.employees', 'nav.hr.contracts', 'nav.hr.leaves', 'nav.hr.statistics.general'],
        'leadership' => ['nav.inbox', 'nav.hr.employees', 'nav.hr.statistics.general', 'nav.org.chart'],
    ];

    /**
     * Widgets shown by default for these role families only (their WIDGETS
     * flag is false, so every other family finds them in the gallery). Who is
     * out is a team lead's question; the next holiday and the week's
     * birthdays are a team member's. "Leave balance" is in no list: "My
     * leaves" already shows the same balance on everyone's default dashboard.
     */
    private const FAMILY_WIDGETS = [
        'who-is-out' => ['manager', 'hr-city', 'hr-national', 'leadership'],
        'next-holiday' => ['employee', 'manager'],
        'birthdays' => ['employee', 'manager'],
    ];

    /** @var array<string, array<string, array<string, mixed>>> shortcuts per user and role, for this request */
    private array $apps = [];

    /** @var array<string, array{decides: bool, late: bool}> what may wait on each user, for this request */
    private array $waiting = [];

    /** The role a layout is stored under: the active role as named, HR cities kept apart. */
    public static function roleKey(User $user): string
    {
        return trim($user->myRole() ?? '') ?: 'none';
    }

    /** employee | manager | hr-city | hr-national | leadership */
    public function family(User $user): string
    {
        return match (true) {
            $user->activeRoleIs('super-admin', 'general-manager') => 'leadership',
            $user->activeRoleIs('HR-SYR') => 'hr-national',
            $user->activeRoleIs('HR') => 'hr-city',
            $user->activeRoleIs('manager') => 'manager',
            default => 'employee',
        };
    }

    /**
     * The shortcuts this person may use, keyed by tile key.
     *
     * @return array<string, array{id: string, label: string, route: string, params: array<string, mixed>, icon: string, counter: ?string}>
     */
    public function apps(User $user): array
    {
        $memo = $user->getKey().'|'.self::roleKey($user);

        if (isset($this->apps[$memo])) {
            return $this->apps[$memo];
        }

        $apps = [];

        foreach (Navigation::shortcuts($user, Navigation::isDeliverResponsible($user)) as $id => $page) {
            $apps[self::APP_PREFIX.$id] = ['id' => $id, 'label' => $page['label'], 'route' => $page['route'], 'params' => [], 'icon' => $page['icon'] ?? 'file', 'counter' => $page['counter']];
        }

        foreach (Navigation::newActions($user) as $action) {
            $apps[self::APP_PREFIX.$action['id']] = ['id' => $action['id'], 'label' => $action['label'], 'route' => $action['route'], 'params' => $action['params'], 'icon' => 'plus', 'counter' => null];
        }

        return $this->apps[$memo] = array_filter($apps, fn (array $app): bool => Route::has($app['route']));
    }

    /** @return array<int, string> keys this person may use, in default order */
    public function available(User $user): array
    {
        $widgets = array_values(array_filter(array_keys(self::WIDGETS), fn (string $key): bool => $this->allowed($user, $key)));

        return [...$widgets, ...array_keys($this->apps($user))];
    }

    /**
     * May this person have this tile? Each widget keeps the rule its card has
     * today: the dashboard's cards are open to every signed-in user (each card
     * scopes its own rows to the viewer); the statistics cards come from a
     * page behind statistics.view; the salary card also needs pay visibility,
     * which super-admin never has. A shortcut is allowed while its page is in
     * the role's menu.
     */
    public function allowed(User $user, string $key): bool
    {
        if ($this->isApp($key)) {
            return isset($this->apps($user)[$key]);
        }

        return match ($key) {
            // The card reads the viewer's own leave balance (Index shows it only when there is one).
            'my-leaves' => $user->employee_id !== null,
            'soon-ending-contracts', 'stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves' => $user->hasActivePermission('statistics.view'),
            'stats-salary' => $user->hasActivePermission('statistics.view') && $user->canViewSalary(),
            // Absences of other people: managers, HR and leadership, each in the team
            // calendar's own scope (TeamCoverage); staff see the calendar instead.
            'who-is-out' => $this->family($user) !== 'employee',
            // Both read the viewer's own contract (its holidays, its annual balance).
            'next-holiday', 'leave-balance' => $user->employee_id !== null,
            // Only for someone who can have a decision, or something late, to see.
            'decide' => $this->waitingOn($user)['decides'],
            'late' => $this->waitingOn($user)['late'],
            default => isset(self::WIDGETS[$key]),
        };
    }

    public function isApp(string $key): bool
    {
        return str_starts_with($key, self::APP_PREFIX);
    }

    public function isWidget(string $key): bool
    {
        return isset(self::WIDGETS[$key]);
    }

    /** The widget's Livewire component, or null for a shortcut or a widget not built yet. */
    public function component(string $key): ?string
    {
        return self::COMPONENTS[$key] ?? null;
    }

    /** The widget a tile component draws (its loading placeholder needs the name). */
    public static function keyOf(string $component): ?string
    {
        $key = array_search($component, self::COMPONENTS, true);

        return is_string($key) ? $key : null;
    }

    public function icon(string $key, ?User $user = null): string
    {
        if ($this->isApp($key)) {
            return ($user ? $this->apps($user)[$key]['icon'] ?? null : null) ?? 'file';
        }

        return self::WIDGETS[$key][0] ?? 'file';
    }

    /** The tile's name, in the current language. */
    public function label(string $key, ?User $user = null): string
    {
        if ($this->isApp($key)) {
            return ($user ? $this->apps($user)[$key]['label'] ?? null : null) ?? $key;
        }

        return __('dashboard.tiles.'.$key);
    }

    /**
     * Any widget goes down to one cell: below `card` (the smallest size it can
     * show itself at) it is an icon that opens it in a side panel. `full` is
     * its usual size. A shortcut grows up to 4x4: from `card` up it becomes a
     * card with its data (AppCard).
     *
     * @return array{min: array{0: int, 1: int}, max: array{0: int, 1: int}, card: array{0: int, 1: int}, full?: array{0: int, 1: int}}
     */
    public function bounds(string $key): array
    {
        if ($this->isApp($key)) {
            return ['min' => [1, 1], 'max' => [4, 4], 'card' => [2, 2]];
        }

        return [
            'min' => [1, 1],
            'max' => self::WIDGETS[$key][2] ?? [1, 1],
            'card' => self::WIDGETS[$key][1] ?? [1, 1],
            'full' => self::WIDGETS[$key][3] ?? [2, 2],
        ];
    }

    /**
     * The page a widget opens on double-click, when the role's menu has one
     * for it; null otherwise. Shortcuts are links already, so not here.
     */
    public function page(User $user, string $key): ?string
    {
        if ($this->isApp($key) || ! $this->allowed($user, $key)) {
            return null;
        }

        $apps = $this->apps($user);

        foreach (self::PAGES[$key] ?? [] as $id) {
            if (isset($apps[self::APP_PREFIX.$id])) {
                $app = $apps[self::APP_PREFIX.$id];

                return route($app['route'], $app['params']);
            }
        }

        return null;
    }

    /** Does this shortcut show a card (AppCard) when big or in the side panel? A New action is one button: no. */
    public function hasCard(string $key): bool
    {
        return $this->isApp($key) && ! str_starts_with($key, self::APP_PREFIX.'new.');
    }

    public function canResize(string $key): bool
    {
        return $this->bounds($key)['min'] !== $this->bounds($key)['max'];
    }

    /** "CxR" kept inside the tile's bounds; anything unreadable becomes the default. */
    public function size(string $key, mixed $size): string
    {
        $b = $this->bounds($key);

        if (! is_string($size) || ! preg_match('/^(\d{1,2})x(\d{1,2})$/', $size, $m)) {
            return $this->defaultSize($key);
        }

        $c = max($b['min'][0], min($b['max'][0], (int) $m[1]));
        $r = max($b['min'][1], min($b['max'][1], (int) $m[2]));

        return $c.'x'.$r;
    }

    /**
     * Today's dashboard, in today's order (late, work inbox, my leaves, notes, team,
     * news, calendar, upcoming leaves, today's and upcoming events, pending
     * requests, checklist, badges); then the role family's shortcuts; then,
     * hidden, everything else the person may add.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool}>
     */
    public function defaults(User $user): array
    {
        $family = $this->family($user);
        $shown = array_map(fn (string $id): string => self::APP_PREFIX.$id, self::DEFAULT_APPS[$family]);
        $available = $this->available($user);

        $order = [
            ...array_filter($available, fn (string $key): bool => $this->isWidget($key) && $this->shownByDefault($family, $key)),
            ...array_values(array_intersect($shown, $available)),
        ];
        $order = [...$order, ...array_diff($available, $order)];

        $items = array_map(fn (string $key): array => [
            'key' => $key,
            'size' => $this->defaultSize($key),
            'pinned' => false,
            'hidden' => $this->isApp($key) ? ! in_array($key, $shown, true) : ! $this->shownByDefault($family, $key),
        ] + $this->look($key, null), array_values($order));

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * Keep only known, allowed items with sizes inside their bounds, once each;
     * add any new item at the end (hidden if it is not shown by default);
     * pinned items first, keeping their relative order; at most MAX_ITEMS.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool}>
     */
    public function clean(User $user, ?array $stored): array
    {
        if ($stored === null) {
            return $this->defaults($user);
        }

        $available = array_flip($this->available($user));
        $out = [];

        foreach (array_slice($stored, 0, self::MAX_ITEMS * 2) as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;

            if (! is_string($key) || ! isset($available[$key]) || isset($out[$key])) {
                continue;
            }

            $out[$key] = [
                'key' => $key,
                'size' => $this->size($key, $item['size'] ?? null),
                'pinned' => (bool) ($item['pinned'] ?? false),
                'hidden' => (bool) ($item['hidden'] ?? false),
            ]
                // Sized by hand: shown at exactly that size, never grown (what Customise shows is what is saved).
                + (! empty($item['fixed']) ? ['fixed' => true] : [])
                + $this->look($key, $item['style'] ?? null);
        }

        $first = [];
        foreach ($this->defaults($user) as $item) {
            // The alerts were above the grid before they were tiles: someone who never had them gets them first.
            if (! isset($out[$item['key']]) && in_array($item['key'], self::ALERTS, true)) {
                $first[$item['key']] = $item;

                continue;
            }
            $out[$item['key']] ??= $item;
        }

        $out = array_values($first + $out);
        usort($out, fn (array $a, array $b): int => (int) $b['pinned'] <=> (int) $a['pinned']);

        return array_slice($out, 0, self::MAX_ITEMS);
    }

    /** @return array{decides: bool, late: bool} */
    private function waitingOn(User $user): array
    {
        $memo = $user->getKey().'|'.self::roleKey($user);

        if (! isset($this->waiting[$memo])) {
            $waiting = app(WaitingOnYou::class);
            $this->waiting[$memo] = ['decides' => $waiting->decides($user), 'late' => $waiting->watchesLate($user)];
        }

        return $this->waiting[$memo];
    }

    /** Shown on the role family's default dashboard: everyone's widgets, then FAMILY_WIDGETS. */
    private function shownByDefault(string $family, string $key): bool
    {
        return self::WIDGETS[$key][4] || in_array($family, self::FAMILY_WIDGETS[$key] ?? [], true);
    }

    /** @return array{style?: string} */
    private function look(string $key, mixed $style): array
    {
        if (! isset(self::LOOKS[$key])) {
            return [];
        }

        return ['style' => in_array($style, self::LOOKS[$key], true) ? $style : self::LOOKS[$key][0]];
    }

    private function defaultSize(string $key): string
    {
        $d = $this->isApp($key) ? [1, 1] : (self::WIDGETS[$key][3] ?? [2, 2]);

        return $d[0].'x'.$d[1];
    }
}
