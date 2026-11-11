<?php

namespace App\Support\Dashboard;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Dashboard\AttentionCard;
use App\Livewire\Dashboard\BadgesCard;
use App\Livewire\Dashboard\BirthdaysCard;
use App\Livewire\Dashboard\CalendarCard;
use App\Livewire\Dashboard\ChecklistCard;
use App\Livewire\Dashboard\ClockCard;
use App\Livewire\Dashboard\ContractStatsCard;
use App\Livewire\Dashboard\ContractTypeEmployeesStatistics;
use App\Livewire\Dashboard\DecisionsCard;
use App\Livewire\Dashboard\DepartmentEmployeeStatistics;
use App\Livewire\Dashboard\EmployeeChartStatistics;
use App\Livewire\Dashboard\EmployeeContactCard;
use App\Livewire\Dashboard\EmployeeContractsCard;
use App\Livewire\Dashboard\EmployeeNotesCard;
use App\Livewire\Dashboard\EmployeesCard;
use App\Livewire\Dashboard\EmployeeSectionCard;
use App\Livewire\Dashboard\GlanceCard;
use App\Livewire\Dashboard\ItemCard;
use App\Livewire\Dashboard\JobLevelEmployeeStatistics;
use App\Livewire\Dashboard\LateCard;
use App\Livewire\Dashboard\LeaveBalanceCard;
use App\Livewire\Dashboard\MeCard;
use App\Livewire\Dashboard\MyLeavesCard;
use App\Livewire\Dashboard\MyRequestsCard;
use App\Livewire\Dashboard\NewsCard;
use App\Livewire\Dashboard\NextHolidayCard;
use App\Livewire\Dashboard\NoLeavesEmployeesStatistics;
use App\Livewire\Dashboard\PendingRequestsCard;
use App\Livewire\Dashboard\RequestLeaveCard;
use App\Livewire\Dashboard\SalaryPerEmployeeStatistics;
use App\Livewire\Dashboard\SoonEndingContractsCard;
use App\Livewire\Dashboard\TeamCard;
use App\Livewire\Dashboard\UpcomingEventsCard;
use App\Livewire\Dashboard\UpcomingLeavesCard;
use App\Livewire\Dashboard\WhoIsOutCard;
use App\Livewire\News\TodayEventsCard;
use App\Models\Center;
use App\Models\City;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\InAppFeedback;
use App\Models\Program;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
 * A layout is an ordered list of {key, size, pinned, hidden, style?, fixed?, min?}
 * (fixed: sized by hand, shown at exactly that size, never grown into gaps; min:
 * minimised, a hidden tile kept in the strip under the grid to come back in place). Whatever
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
    /** Widgets, the role's shortcuts and the cards added from pages (record cards), hidden ones included. */
    public const MAX_ITEMS = 120;

    /** Widgets: key => [icon, min [cols, rows], max [cols, rows], default [cols, rows], shown by default] */
    public const WIDGETS = [
        // Were fixed above the grid (prompt 7); tiles now, to move, resize, pin or hide (media-request b183c0c).
        'late' => ['clock', [3, 1], [12, 3], [12, 1], true],
        // The work inbox: the decisions, then the rest of the inbox (it took over "For you now").
        'decide' => ['inbox', [3, 2], [12, 6], [12, 2], true],
        // The viewer's own record: what runs out or waits on it (FAMILY_WIDGETS), right under the alerts.
        'attention' => ['alert', [3, 1], [12, 4], [6, 2], false],
        'my-leaves' => ['my-leaves', [3, 1], [12, 2], [4, 1], true],
        'news' => ['newspaper', [3, 3], [12, 8], [4, 3], true],
        // A first dashboard stays short: the rest wait in the gallery (Customise > Add).
        'notes' => ['edit', [2, 2], [12, 8], [5, 3], false],
        'team' => ['users', [3, 2], [12, 8], [3, 4], false],
        'calendar' => ['calendar', [3, 3], [12, 8], [5, 3], false],
        'upcoming-leaves' => ['calendar', [3, 2], [12, 8], [5, 3], false],
        'today-events' => ['cake', [3, 2], [12, 8], [4, 3], false],
        'upcoming-events' => ['calendar', [3, 2], [12, 8], [4, 3], false],
        'pending-requests' => ['clipboard-list', [3, 2], [12, 8], [4, 3], false],
        'checklist' => ['checklist', [3, 2], [12, 8], [4, 3], false],
        'badges' => ['badges', [3, 2], [12, 8], [4, 3], false],
        // From the general statistics page: offered in the gallery, or by family (FAMILY_WIDGETS).
        'soon-ending-contracts' => ['contracts', [3, 2], [12, 8], [4, 3], false],
        'stats-department' => ['statistics', [3, 2], [12, 6], [5, 3], false],
        'stats-job-level' => ['statistics', [3, 2], [12, 6], [5, 3], false],
        'stats-contract-type' => ['statistics', [3, 2], [12, 6], [3, 3], false],
        'stats-no-leaves' => ['statistics', [3, 2], [12, 8], [3, 3], false],
        'stats-salary' => ['statistics', [3, 2], [12, 6], [3, 3], false],
        // Hires, ends and terminations over time (the statistics page's personnel card).
        'stats-personnel' => ['statistics', [3, 2], [12, 6], [4, 3], false],
        // Prompt 8. Shown by default for some role families only (FAMILY_WIDGETS), else in the gallery.
        'who-is-out' => ['users', [2, 1], [12, 6], [4, 3], false],
        'next-holiday' => ['calendar', [2, 1], [6, 3], [2, 2], false],
        'leave-balance' => ['my-leaves', [2, 2], [6, 4], [2, 2], false],
        'birthdays' => ['cake', [2, 2], [12, 6], [3, 2], false],
        // Damascus time and the viewer's working day: for everyone, in the gallery (not on today's dashboard).
        'clock' => ['clock', [2, 1], [12, 6], [2, 2], false],
        // The weather in the viewer's work city, a clock, the date and the time, in one card:
        // on everyone's dashboard, first (LEADS), so it sits in the start corner.
        'glance' => ['clock', [4, 2], [4, 2], [4, 2], true],
        // The viewer's own record: the leave form as a card, and who they are here.
        'request-leave' => ['plus', [3, 3], [8, 6], [4, 4], false],
        'me' => ['user', [3, 3], [8, 6], [4, 3], false],
        // Live versions of pages, added from the page itself (Add to dashboard) or from Add.
        'my-requests' => ['my-leaves', [3, 2], [12, 6], [4, 3], false],
        'employees' => ['users', [3, 3], [12, 8], [4, 4], false],
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
        'stats-personnel' => EmployeeChartStatistics::class,
        'who-is-out' => WhoIsOutCard::class,
        'next-holiday' => NextHolidayCard::class,
        'leave-balance' => LeaveBalanceCard::class,
        'birthdays' => BirthdaysCard::class,
        'clock' => ClockCard::class,
        'glance' => GlanceCard::class,
        'attention' => AttentionCard::class,
        'request-leave' => RequestLeaveCard::class,
        'me' => MeCard::class,
        'my-requests' => MyRequestsCard::class,
        'employees' => EmployeesCard::class,
    ];

    /**
     * Cards about one record, added from a section of its page (Add to
     * dashboard): key "rec.<type>.<employee id>". type => [icon, min, max,
     * default, component, the EmployeePolicy ability the page's section needs].
     * Never offered in Add or shown by default; allowed() asks the policy again
     * on every read, so a card whose record the person may no longer open is
     * dropped from the layout.
     */
    public const RECORDS = [
        'contracts' => ['contracts', [3, 2], [12, 8], [4, 3], EmployeeContractsCard::class, 'viewRecords'],
        'contact' => ['contact', [3, 2], [8, 6], [3, 3], EmployeeContactCard::class, 'viewDirectory'],
        // The rest of the profile's sections, one generic card (EmployeeSectionCard, mounted with
        // the section), each behind the same rule the profile uses to draw that section.
        'overview' => ['user', [3, 2], [8, 6], [4, 3], EmployeeSectionCard::class, 'viewDirectory'],
        'timeline' => ['clock', [3, 2], [8, 8], [4, 4], EmployeeSectionCard::class, 'viewRecords'],
        'education' => ['education', [3, 2], [8, 6], [3, 3], EmployeeSectionCard::class, 'viewRecords'],
        'experience' => ['experience', [3, 2], [8, 6], [3, 3], EmployeeSectionCard::class, 'viewRecords'],
        'skills' => ['statistics', [2, 2], [8, 6], [3, 2], EmployeeSectionCard::class, 'viewRecords'],
        'courses' => ['courses', [3, 2], [8, 6], [3, 3], EmployeeSectionCard::class, 'viewRecords'],
        'badges' => ['badges', [3, 2], [8, 6], [3, 3], EmployeeSectionCard::class, 'viewProfileBadges'],
        'personal' => ['contact', [3, 2], [8, 6], [4, 3], EmployeeSectionCard::class, 'viewPrivate'],
        'personal-contact' => ['phone', [3, 2], [8, 6], [3, 2], EmployeeSectionCard::class, 'viewPrivate'],
        'family' => ['relatives', [3, 2], [8, 6], [3, 3], EmployeeSectionCard::class, 'viewPrivate'],
    ];

    /** Prefix of every record card key: "rec.<type>.<id>". */
    public const RECORD_PREFIX = 'rec.';

    /**
     * One card of the contract statistics page, added from that card:
     * "cstat.<program id>.<center id>.<city id>", 0 for none (center 0 = the
     * program's own card, city 0 = every city). [icon, min, max, default].
     * Like a record card, never in Add or a default layout; allowed() asks
     * statistics.view and the city scope again on every read.
     */
    public const CONTRACT_STAT_PREFIX = 'cstat.';

    /**
     * Tiles a phone does not draw, to keep its home screen light (media-request
     * 88a5e75): the today's-events slideshow and the statistics charts. Only
     * what is shown changes; the saved layout keeps them for a larger screen.
     */
    public const PHONE_HIDDEN = ['today-events', 'stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'stats-salary', 'stats-personnel'];

    /** Tiles that come in more than one look: key => allowed looks, the first is the default. */
    public const LOOKS = ['clock' => ['digital', 'analog']];

    /** Prefix of every shortcut key: "app.<navigation item id>", e.g. app.nav.hr.employees, app.new.leave. */
    public const APP_PREFIX = 'app.';

    /**
     * One item of a page's card grid, added from its card: "item.<type>.<id>".
     * type => [icon, min, max, default, hub group or null]. Drawn by ItemCard;
     * like a record card, never in Add or a default layout, and allowed() asks
     * the page's own rule again on every read (itemAllowed()).
     */
    public const ITEMS = [
        // My folders and Shared folders: a folder (its folders and files) and one file.
        'folder' => ['folder', [2, 2], [8, 6], [3, 3], null],
        'file' => ['document-text', [2, 2], [6, 6], [2, 2], null],
        // The programs page and a program's centres.
        'program' => ['program', [3, 2], [8, 6], [4, 3], 'structure'],
        'center' => ['homepage', [3, 2], [8, 6], [3, 3], 'structure'],
        // The interview board: one interview (never its meeting link, as on the board).
        'interview' => ['calendar', [3, 2], [8, 6], [3, 3], 'recruitment'],
        // The feedback inbox: one message.
        'feedback' => ['inbox', [3, 2], [8, 6], [4, 3], null],
    ];

    public const ITEM_PREFIX = 'item.';

    private const CONTRACT_STAT = ['contracts', [3, 3], [8, 6], [4, 3]];

    /** Were fixed above the grid: a layout saved before they were tiles gets them first. */
    private const ALERTS = ['late', 'decide'];

    /**
     * First on the page, before the alerts, in the start corner (top left in
     * English, top right in Arabic). A layout saved before it existed gets it
     * first too, as with the alerts; after that it stays where the person puts it.
     */
    private const LEADS = ['glance'];

    /**
     * One size only, their default (fixed): never grown into a gap, and never
     * resized, by the grip or in Customise (bounds() gives min = max).
     */
    private const SIZED = ['glance'];

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
        'stats-personnel' => ['nav.hr.statistics.general'],
        'next-holiday' => ['nav.my-team.holidays', 'nav.basics.holidays'],
        'request-leave' => ['nav.my-space.leaves'],
        'my-requests' => ['nav.my-space.leaves'],
        'employees' => ['nav.hr.employees'],
    ];

    /**
     * Shortcuts shown by default, per role family, after today's cards (so the
     * top of the page is what everyone already knows). Any other shortcut the
     * role may open is in the gallery, hidden.
     */
    private const DEFAULT_APPS = [
        // The hub above the grid (HubGroups) reaches every page, so none is shown by default.
        'employee' => [],
        'manager' => [],
        'hr-city' => [],
        'hr-national' => [],
        'leadership' => [],
    ];

    /**
     * Widgets shown by default for these role families only (their WIDGETS
     * flag is false, so every other family finds them in the gallery). Who is
     * out is a team lead's question; contracts running out are HR's and
     * leadership's; the next holiday is a team member's. "Leave balance" is in
     * no list: "My leaves" already shows the same balance on everyone's default
     * dashboard.
     */
    private const FAMILY_WIDGETS = [
        'who-is-out' => ['manager', 'hr-city', 'hr-national', 'leadership'],
        'soon-ending-contracts' => ['hr-city', 'hr-national', 'leadership'],
        'team' => ['manager'],
        'next-holiday' => ['employee', 'manager'],
        // Folds to one line when nothing is running out or waiting on the viewer.
        'attention' => ['employee', 'manager'],
    ];

    /**
     * Ready-made layouts a person may pick while customising: id => the widgets
     * shown, in order. A widget the role may not have is simply left out, and
     * "essentials" is the role family's own default.
     */
    private const PRESETS = [
        'essentials' => [],
        'personal' => ['late', 'decide', 'my-leaves', 'next-holiday', 'calendar', 'checklist', 'notes', 'news'],
        'team' => ['late', 'decide', 'who-is-out', 'upcoming-leaves', 'team', 'calendar'],
        'numbers' => ['late', 'decide', 'soon-ending-contracts', 'stats-department', 'stats-contract-type', 'stats-job-level', 'stats-no-leaves'],
    ];

    /**
     * The cards each hub group (HubGroups) shows when it filters the grid; a
     * shortcut goes with the group its page is in. The alerts (late, decide)
     * are in every group: what waits on the viewer is never filtered away.
     */
    private const GROUP_WIDGETS = [
        'people' => ['attention', 'me', 'employees', 'soon-ending-contracts', 'team', 'who-is-out', 'birthdays', 'today-events', 'upcoming-events', 'pending-requests', 'checklist', 'badges', 'notes'],
        'leaves' => ['attention', 'my-leaves', 'my-requests', 'leave-balance', 'request-leave', 'calendar', 'upcoming-leaves', 'who-is-out', 'next-holiday'],
        'recruitment' => [],
        'structure' => ['team', 'stats-department', 'stats-job-level'],
        'reports' => ['soon-ending-contracts', 'stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'stats-salary', 'stats-personnel', 'news'],
        'settings' => [],
    ];

    /** @var array<string, array<string, array<string, mixed>>> shortcuts per user and role, for this request */
    private array $apps = [];

    /** @var array<int, ?Employee> the employees record cards are about, for this request */
    private array $records = [];

    /** @var array<string, array{0: ?Program, 1: ?Center, 2: ?City}> what contract statistics cards are about, for this request */
    private array $contractStats = [];

    /** @var array<string, mixed> what item cards are about, for this request */
    private array $itemModels = [];

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

        if ($this->isContractStat($key)) {
            return $this->contractStatAllowed($user, $key);
        }

        if ($this->isItem($key)) {
            return $this->itemAllowed($user, $key);
        }

        if ($this->isRecord($key)) {
            $employee = $this->recordEmployee($key);
            $ability = self::RECORDS[$this->recordType($key)][5] ?? null;

            return $employee !== null && $ability !== null && Gate::forUser($user)->allows($ability, $employee);
        }

        return match ($key) {
            // The card reads the viewer's own leave balance (Index shows it only when there is one).
            'my-leaves' => $user->employee_id !== null,
            // Also the contracts page's own card: the same rows, in Contract::visibleTo().
            'soon-ending-contracts' => $user->hasActivePermission('statistics.view') || $user->hasActivePermission('contracts.view'),
            'stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'stats-personnel' => $user->hasActivePermission('statistics.view'),
            'stats-salary' => $user->hasActivePermission('statistics.view') && $user->canViewSalary(),
            // Absences of other people: managers, HR and leadership, each in the team
            // calendar's own scope (TeamCoverage); staff see the calendar instead.
            'who-is-out' => $this->family($user) !== 'employee',
            // Both read the viewer's own contract (its holidays, its annual balance).
            'next-holiday', 'leave-balance' => $user->employee_id !== null,
            // The viewer's own record (attention: their contract and leave; me: their profile).
            'attention', 'me' => $user->employee_id !== null,
            // The leave form's own rule (the New menu's): leaves.create and leaves.view-own.
            'request-leave' => $user->employee_id !== null && $user->hasActivePermission('leaves.create') && $user->hasActivePermission('leaves.view-own'),
            // The leaves page's own rule; the card reads the viewer's own requests only.
            'my-requests' => $user->employee_id !== null && $user->hasActivePermission('leaves.view-own'),
            // The employees page's own rule, and its city scope (Employee::inActiveCities).
            'employees' => $user->hasActivePermission('employees.view'),
            // Only for someone who can have a decision, or something late, to see.
            'decide' => $this->waitingOn($user)['decides'],
            'late' => $this->waitingOn($user)['late'],
            default => isset(self::WIDGETS[$key]),
        };
    }

    /**
     * What each of this person's hub groups shows when it filters the grid:
     * group id => tile keys (its widgets this person may have, the shortcuts
     * to its pages, and the alerts), plus the record cards among $records
     * (layout keys) in People. Only the view changes, never the layout.
     *
     * @return array<string, list<string>>
     */
    public function groupTiles(User $user, array $records = []): array
    {
        $groups = [];

        foreach (HubGroups::for($user) as $group) {
            $keys = [...self::ALERTS, ...(self::GROUP_WIDGETS[$group['id']] ?? [])];
            $keys = array_values(array_filter($keys, fn (string $key): bool => $this->allowed($user, $key)));

            foreach ($group['pages'] as $page) {
                if (isset($this->apps($user)[self::APP_PREFIX.$page['id']])) {
                    $keys[] = self::APP_PREFIX.$page['id'];
                }
            }

            $groups[$group['id']] = $keys;
        }

        // An item card goes with its page's group (programs and centres: Structure).
        foreach ($records as $key) {
            $group = ($item = $this->item($key)) !== null ? self::ITEMS[$item[0]][4] : null;
            if ($group !== null && isset($groups[$group]) && $this->allowed($user, $key)) {
                $groups[$group][] = $key;
            }
        }

        // A contract statistics card goes with Reports.
        if (isset($groups['reports'])) {
            foreach ($records as $key) {
                if ($this->isContractStat($key) && $this->allowed($user, $key)) {
                    $groups['reports'][] = $key;
                }
            }
        }

        // A card about one person goes with People.
        if (isset($groups['people'])) {
            foreach ($records as $key) {
                if ($this->isRecord($key) && $this->allowed($user, $key)) {
                    $groups['people'][] = $key;
                }
            }
        }

        return $groups;
    }

    public function isApp(string $key): bool
    {
        return str_starts_with($key, self::APP_PREFIX);
    }

    public function isRecord(string $key): bool
    {
        return str_starts_with($key, self::RECORD_PREFIX);
    }

    /** Added from a page with "Add to dashboard" (a record or a contract statistics card): never in the catalogue. */
    public function isAddedCard(string $key): bool
    {
        return $this->isRecord($key) || $this->isContractStat($key) || $this->isItem($key);
    }

    public function isItem(string $key): bool
    {
        return str_starts_with($key, self::ITEM_PREFIX);
    }

    /** The item card's key: item.<type>.<id>. */
    public static function itemKey(string $type, int $id): string
    {
        return self::ITEM_PREFIX.$type.'.'.$id;
    }

    /**
     * [type, id] of a well-formed item key of a known type, or null.
     *
     * @return array{0: string, 1: int}|null
     */
    public function item(string $key): ?array
    {
        return preg_match('/^item\.([a-z]+)\.([1-9]\d{0,9})$/', $key, $m) && isset(self::ITEMS[$m[1]])
            ? [$m[1], (int) $m[2]]
            : null;
    }

    /** The model an item card is about (read once per request), or null. */
    public function itemModel(string $key): mixed
    {
        if (($item = $this->item($key)) === null) {
            return null;
        }

        [$type, $id] = $item;

        return $this->itemModels[$key] ??= match ($type) {
            'folder' => Folder::query()->find($id),
            'file' => Media::query()->where('model_type', Folder::class)->find($id),
            'program' => Program::query()->find($id),
            'center' => Center::query()->find($id),
            'interview' => RecruitmentInterview::query()->find($id),
            'feedback' => InAppFeedback::query()->find($id),
        };
    }

    public function isContractStat(string $key): bool
    {
        return str_starts_with($key, self::CONTRACT_STAT_PREFIX);
    }

    /** The contract statistics card's key: cstat.<program>.<center>.<city>, 0 for none. */
    public static function contractStatKey(int $programId, int $centerId = 0, int $cityId = 0): string
    {
        return self::CONTRACT_STAT_PREFIX.$programId.'.'.$centerId.'.'.$cityId;
    }

    /**
     * [program id, center id, city id] of a well-formed contract statistics key, or null.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function contractStat(string $key): ?array
    {
        return preg_match('/^cstat\.([1-9]\d{0,9})\.(0|[1-9]\d{0,9})\.(0|[1-9]\d{0,9})$/', $key, $m)
            ? [(int) $m[1], (int) $m[2], (int) $m[3]]
            : null;
    }

    /**
     * The program, center and city a contract statistics card is about (read once per request).
     *
     * @return array{0: ?Program, 1: ?Center, 2: ?City}
     */
    public function contractStatModels(string $key): array
    {
        [$programId, $centerId, $cityId] = $this->contractStat($key) ?? [0, 0, 0];

        return $this->contractStats[$key] ??= [
            $programId ? Program::query()->find($programId) : null,
            $centerId ? Center::query()->find($centerId) : null,
            $cityId ? City::query()->find($cityId) : null,
        ];
    }

    /** The record card's key for a type and an employee: rec.contracts.42. */
    public static function recordKey(string $type, int $employeeId): string
    {
        return self::RECORD_PREFIX.$type.'.'.$employeeId;
    }

    /** "contracts" for rec.contracts.42, or null for any other key. */
    public function recordType(string $key): ?string
    {
        return preg_match('/^rec\.([a-z-]+)\.(\d{1,10})$/', $key, $m) && isset(self::RECORDS[$m[1]]) ? $m[1] : null;
    }

    /** The employee a record card is about (read once per request), or null. */
    public function recordEmployee(string $key): ?Employee
    {
        if ($this->recordType($key) === null) {
            return null;
        }

        $id = (int) substr($key, strrpos($key, '.') + 1);

        return $this->records[$id] ??= Employee::query()->find($id);
    }

    /**
     * What the tile's component is mounted with: a record card gets its
     * employee's id (the component checks the policy again itself).
     *
     * @return array<string, mixed>
     */
    public function componentParams(string $key): array
    {
        if (($stat = $this->contractStat($key)) !== null) {
            return ['programId' => $stat[0], 'centerId' => $stat[1], 'cityId' => $stat[2]];
        }

        if (($item = $this->item($key)) !== null) {
            return ['type' => $item[0], 'itemId' => $item[1]];
        }

        if (($type = $this->recordType($key)) === null) {
            return [];
        }

        $params = ['employeeId' => (int) substr($key, strrpos($key, '.') + 1)];

        // The generic profile section card is told which section it draws.
        return self::RECORDS[$type][4] === EmployeeSectionCard::class ? $params + ['section' => $type] : $params;
    }

    public function isWidget(string $key): bool
    {
        return isset(self::WIDGETS[$key]);
    }

    /** The widget's Livewire component, or null for a shortcut or a widget not built yet. */
    public function component(string $key): ?string
    {
        if ($this->contractStat($key) !== null) {
            return ContractStatsCard::class;
        }

        if ($this->item($key) !== null) {
            return ItemCard::class;
        }

        if (($type = $this->recordType($key)) !== null) {
            return self::RECORDS[$type][4];
        }

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

        if (($type = $this->recordType($key)) !== null) {
            return self::RECORDS[$type][0];
        }

        if ($this->contractStat($key) !== null) {
            return self::CONTRACT_STAT[0];
        }

        if (($item = $this->item($key)) !== null) {
            return self::ITEMS[$item[0]][0];
        }

        return self::WIDGETS[$key][0] ?? 'file';
    }

    /** The tile's name, in the current language. */
    public function label(string $key, ?User $user = null): string
    {
        if ($this->isApp($key)) {
            return ($user ? $this->apps($user)[$key]['label'] ?? null : null) ?? $key;
        }

        if (($type = $this->recordType($key)) !== null) {
            return __('dashboard.tiles.rec-'.$type, ['name' => (string) ($this->recordEmployee($key)?->name ?? '')]);
        }

        if ($this->contractStat($key) !== null) {
            [$program, $center, $city] = $this->contractStatModels($key);

            return __('dashboard.tiles.cstat', ['name' => collect([$center?->name, $program?->name, $city?->name])->filter()->implode(' · ')]);
        }

        if (($item = $this->item($key)) !== null) {
            return __('dashboard.tiles.item-'.$item[0], ['name' => $this->itemName($key)]);
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
        // A page from the menu (a shortcut) is a card too (2026-11-09): from 3x3 it shows the page
        // itself, live (AppCard); smaller, it waits in the strip like any other card.
        if ($this->isApp($key)) {
            return ['min' => [1, 1], 'max' => [12, 8], 'card' => [3, 3]];
        }

        $spec = match (true) {
            ($type = $this->recordType($key)) !== null => self::RECORDS[$type],
            $this->contractStat($key) !== null => self::CONTRACT_STAT,
            ($item = $this->item($key)) !== null => self::ITEMS[$item[0]],
            default => self::WIDGETS[$key] ?? [],
        };

        if (in_array($key, self::SIZED, true)) {
            return ['min' => $spec[3], 'max' => $spec[3], 'card' => $spec[3], 'full' => $spec[3]];
        }

        return [
            'min' => [1, 1],
            'max' => $spec[2] ?? [1, 1],
            'card' => $spec[1] ?? [1, 1],
            'full' => $spec[3] ?? [2, 2],
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

        if ($this->isRecord($key)) {
            return route('employees.show', $this->recordEmployee($key)->username);
        }

        if ($this->isContractStat($key)) {
            return route('statistics.contract');
        }

        if (($item = $this->item($key)) !== null) {
            return $this->itemPage($user, $key);
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

    /** The page an item card opens: its folder, the folder a file is in, a program's or a centre's page. */
    public function itemPage(User $user, string $key): ?string
    {
        $model = $this->itemModel($key);

        if ($model === null) {
            return null;
        }

        return match ($this->item($key)[0]) {
            'folder' => $this->folderRoute($user, $model),
            'file' => ($folder = Folder::query()->find($model->model_id)) ? $this->folderRoute($user, $folder) : null,
            'program' => route('programs.show', $model->slug),
            'center' => route('centers.show', $model->id),
            'interview' => route('recruitment.interviews.show', ['interviewId' => $model->id]),
            'feedback' => route('feedback.index'),
        };
    }

    /** A page from the menu has a card (AppCard): its summary, or the page itself, live. */
    public function hasCard(string $key): bool
    {
        return $this->isApp($key);
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
     * A short first dashboard: what may be waiting (late, work inbox), my leaves,
     * news and the role family's widgets; then the role family's shortcuts; then,
     * hidden, everything else the person may add. A layout someone already saved
     * keeps what it shows (clean()).
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool, min?: bool}>
     */
    public function defaults(User $user): array
    {
        $family = $this->family($user);
        $shown = array_map(fn (string $id): string => self::APP_PREFIX.$id, self::DEFAULT_APPS[$family]);
        $available = $this->available($user);

        // Shortcuts sit right after the alerts: the cards under them load late and may
        // close when empty, so a shortcut above them never moves under someone's finger.
        $widgets = array_filter($available, fn (string $key): bool => $this->isWidget($key) && $this->shownByDefault($family, $key));
        $order = [
            ...array_intersect($widgets, self::LEADS),
            ...array_intersect($widgets, self::ALERTS),
            ...array_values(array_intersect($shown, $available)),
            ...array_diff($widgets, self::ALERTS, self::LEADS),
        ];
        $order = [...$order, ...array_diff($available, $order)];

        $items = array_map(fn (string $key): array => [
            'key' => $key,
            'size' => $this->defaultSize($key),
            'pinned' => false,
            'hidden' => $this->isApp($key) ? ! in_array($key, $shown, true) : ! $this->shownByDefault($family, $key),
        ] + (in_array($key, self::SIZED, true) ? ['fixed' => true] : []) + $this->look($key, null), array_values($order));

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * The presets this role can fill: a list that would leave nothing but the
     * alerts is not offered (an employee has no statistics to show).
     *
     * @return array<string, list<string>> id => the widgets shown, in order
     */
    public function presets(User $user): array
    {
        $family = $this->family($user);
        $presets = [];

        foreach (self::PRESETS as $id => $keys) {
            $keys = $id === 'essentials'
                ? array_keys(array_filter(self::WIDGETS, fn (array $widget, string $key): bool => $this->shownByDefault($family, $key), ARRAY_FILTER_USE_BOTH))
                : $keys;
            $keys = array_values(array_filter($keys, fn (string $key): bool => $this->allowed($user, $key)));

            if (array_diff($keys, self::ALERTS) !== []) {
                $presets[$id] = $keys;
            }
        }

        return $presets;
    }

    /**
     * The whole layout for a preset: its widgets shown in its order, everything
     * else hidden and still offered in Add. Null for one this role is not offered.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string}>|null
     */
    public function presetLayout(User $user, string $id): ?array
    {
        $keys = $this->presets($user)[$id] ?? null;

        if ($keys === null) {
            return null;
        }

        $all = array_column($this->defaults($user), null, 'key');
        $layout = [];

        foreach ($keys as $key) {
            $layout[] = ['hidden' => false] + $all[$key];
        }

        foreach (array_diff_key($all, array_flip($keys)) as $item) {
            $layout[] = ['hidden' => true] + $item;
        }

        return $layout;
    }

    /**
     * Keep only known, allowed items with sizes inside their bounds, once each;
     * add any new item at the end (hidden if it is not shown by default);
     * pinned items first, keeping their relative order (each at its cell, `at`); at most MAX_ITEMS.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool, min?: bool, at?: array{0: int, 1: int}}>
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

            // A record card is never in the catalogue: it is kept while its record may still be opened.
            if (! is_string($key) || isset($out[$key]) || ! (isset($available[$key]) || ($this->isAddedCard($key) && $this->allowed($user, $key)))) {
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
                // Minimised only means something for a hidden tile.
                + (! empty($item['min']) && ! empty($item['hidden']) ? ['min' => true] : [])
                // At a cell: its place on the grid (x, y), for a shown tile that is pinned or was
                // resized from a top or start corner (home-screen.js setCellsFrom).
                + (empty($item['hidden']) && ($at = $this->anchor($item['at'] ?? null)) !== null ? ['at' => $at] : [])
                + $this->look($key, $item['style'] ?? null);
        }

        $first = [];
        foreach ($this->defaults($user) as $item) {
            // The alerts were above the grid before they were tiles, and the leads open the page:
            // someone who never had them gets them first.
            if (! isset($out[$item['key']]) && in_array($item['key'], [...self::LEADS, ...self::ALERTS], true)) {
                $first[$item['key']] = $item;

                continue;
            }
            $out[$item['key']] ??= $item;
        }

        $out = array_values($first + $out);
        // Pinned first in the list (a pinned tile is placed by its cell when it has one, `at`).
        usort($out, fn (array $a, array $b): int => (int) $b['pinned'] <=> (int) $a['pinned']);

        return array_slice($out, 0, self::MAX_ITEMS);
    }

    public function defaultSize(string $key): string
    {
        $d = match (true) {
            $this->isApp($key) => [4, 4],
            ($type = $this->recordType($key)) !== null => self::RECORDS[$type][3],
            $this->contractStat($key) !== null => self::CONTRACT_STAT[3],
            ($item = $this->item($key)) !== null => self::ITEMS[$item[0]][3],
            default => self::WIDGETS[$key][3] ?? [2, 2],
        };

        return $d[0].'x'.$d[1];
    }

    /** The name an item card is titled with. */
    public function itemName(string $key): string
    {
        $model = $this->itemModel($key);

        return match ($this->item($key)[0] ?? null) {
            'interview' => (string) ($model?->application?->applicant?->full_name ?? ''),
            'feedback' => $model?->category ? __('feedback.categories.'.$model->category) : (string) ($model?->created_at?->format('Y-m-d') ?? ''),
            default => (string) ($model?->name ?? ''),
        };
    }

    /**
     * The page's own rule for the item: a folder or a file in it with
     * archives.view, for an administrator or someone the folder is shared
     * with (ArchivedFilesModule); a file only of a type that page shows; a
     * program or a centre while its reference page may be opened.
     */
    private function itemAllowed(User $user, string $key): bool
    {
        $item = $this->item($key);
        $model = $this->itemModel($key);

        if ($item === null || $model === null) {
            return false;
        }

        return match ($item[0]) {
            'folder' => $this->folderAllowed($user, $model),
            'file' => in_array(strtolower((string) $model->extension), ArchivedFilesModule::ALLOWED_UPLOAD_EXTENSIONS, true)
                && ($folder = Folder::query()->find($model->model_id)) !== null
                && $this->folderAllowed($user, $folder),
            'program' => $user->canOpenReferencePage('programs'),
            'center' => $user->canOpenReferencePage('centers'),
            // The interview page's rule: within RecruitmentInterview::visibleTo() and its policy's view.
            'interview' => RecruitmentInterview::query()->visibleTo($user)->whereKey($model->getKey())->exists()
                && Gate::forUser($user)->allows('view', $model),
            // The feedback inbox's rule (User::canReadFeedback).
            'feedback' => Gate::forUser($user)->allows('feedback.read'),
        };
    }

    private function folderAllowed(User $user, Folder $folder): bool
    {
        return $user->hasActivePermission('archives.view')
            && ($user->isActiveAdministrator() || $folder->users()->whereKey($user->getKey())->exists());
    }

    /** My folders for the viewer's own unshared folder, Shared folders otherwise (as the pages list them). */
    private function folderRoute(User $user, Folder $folder): string
    {
        $mine = ! $folder->is_shared && (int) $folder->user_id === (int) $user->getKey();

        return route($mine ? 'my-folders.index' : 'shared-folders.index', ['folderId' => $folder->id]);
    }

    /**
     * The page's own rule: statistics.view, and a city the active role may
     * read. A regionally scoped role (activeCityIds()) has a card for one of
     * its own cities only, never "every city"; a center card's center must be
     * in that city. Inactive or unknown programs, centers and cities are refused.
     */
    private function contractStatAllowed(User $user, string $key): bool
    {
        $parts = $this->contractStat($key);

        if ($parts === null || ! $user->hasActivePermission('statistics.view')) {
            return false;
        }

        [, $centerId, $cityId] = $parts;
        $scope = $user->activeCityIds();

        if ($scope !== null && ! in_array($cityId, $scope, true)) {
            return false;
        }

        [$program, $center, $city] = $this->contractStatModels($key);

        if ($program === null || ! $program->is_active || ($cityId !== 0 && ($city === null || ! $city->is_active))) {
            return false;
        }

        return $centerId === 0 || ($center !== null && $center->is_active && ($cityId === 0 || (int) $center->city_id === $cityId));
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

    /**
     * A pinned tile's cell: [column, row] from the top start corner, inside the
     * widest grid (12 columns) and a sane depth; anything else is dropped.
     *
     * @return array{0: int, 1: int}|null
     */
    private function anchor(mixed $at): ?array
    {
        if (! is_array($at) || count($at) !== 2 || ! array_is_list($at)) {
            return null;
        }

        [$x, $y] = $at;

        return is_int($x) && is_int($y) && $x >= 0 && $x < 12 && $y >= 0 && $y < 200 ? [$x, $y] : null;
    }

    /** @return array{style?: string} */
    private function look(string $key, mixed $style): array
    {
        if (! isset(self::LOOKS[$key])) {
            return [];
        }

        return ['style' => in_array($style, self::LOOKS[$key], true) ? $style : self::LOOKS[$key][0]];
    }
}
