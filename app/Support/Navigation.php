<?php

namespace App\Support;

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentJobOffer;
use App\Models\User;
use App\Services\HolidayProjects;
use App\Services\RecruitmentApproverEligibility;
use App\Services\WorkInbox;
use App\Support\Reports\ReportCatalog;
use Throwable;

/**
 * The sidebar's structure (C0, docs/ux/interaction-contract.md §4): the work
 * inbox on top, then My space, My team, HR operations, Settings and, last,
 * Organisation (reference pages).
 *
 * Every item is shown under the same permission check the old sidebar used;
 * the areas only decide *where* an item sits for the active role. Nothing
 * here grants or removes access: every route keeps its own middleware and
 * policies (C0-Q4). The breadcrumbs read the same map, so a page's first
 * crumb is the area it sits in for that role.
 *
 * An item is ['id', 'label', 'route', 'active' (routeIs patterns), 'icon',
 * 'tour'?, 'counter'?] or a group ['id', 'label', 'icon', 'children'].
 */
final class Navigation
{
    /**
     * @return array{inbox: array{count: int, route: string}|null, areas: list<array{id: string, label: string, items: list<array<string, mixed>>}>}
     */
    public static function for(User $user, bool $isDeliverResponsible = false, bool $countInbox = true): array
    {
        $can = fn (string ...$permissions): bool => collect($permissions)->contains(fn ($p) => $user->hasActivePermission($p));
        $isManager = $user->activeRoleIs('manager');

        // The menu is grouped by what people come to do, not by who they are:
        // People, Leave and time, Recruitment, Reports, Administration, and the
        // person's own pages last (My account). The dashboard stands alone on
        // top. A page sits in one place for every role; the role only decides
        // which pages of a group it has, and a group with none is not shown.
        // Reference pages (programs, centers, positions, basic settings, news)
        // sit in Administration for whoever edits them and in People for those
        // who only read them (access-control.reference_pages_hidden_from).
        $opens = fn (string $page): bool => $user->canOpenReferencePage($page);

        $editsPrograms = $can('programs.create', 'programs.update');
        $editsPositions = $can('positions.create', 'positions.update');
        $editsCenters = $can('centers.create', 'centers.update');
        $editsBasics = $can('project-basics.create', 'project-basics.update', 'project-basics.delete');

        $basics = self::item('nav.basics', __('general.basic-settings'), 'basic-settings.index', [
            'basic-settings.*', 'cities.*', 'contract-types.*', 'leave-types.*', 'annual-leave-rules.*',
            'termination-reasons.*', 'basic-checklist-items.*', 'job-levels.*', 'departments.*', 'course-categories.*',
        ], 'home', 'nav.project-basics');

        $programs = self::item('nav.programs', trans_choice('general.program', 2), 'programs.index', ['programs.*'], 'program', 'nav.programs');
        $positions = self::item('nav.positions', __('general.positions'), 'positions.index', ['positions.*'], 'layers');
        $centers = self::item('nav.centers', trans_choice('general.center', 2), 'centers.index', ['centers.*'], 'center', 'nav.centers');
        $news = self::item('nav.news', __('general.news-management'), 'news.index', ['news.*'], 'newspaper', 'nav.news');

        // A program manager sets holidays for their projects (§6.1); national HR finds the page under Leave and time.
        $holidaysForProjects = ! $user->activeRoleIs(...config('access-control.administrator_roles', []))
            && app(HolidayProjects::class)->managesAnyProject($user);

        // The board's meetings are pinned right under the dashboard for the board (members, chair, secretary).
        $home = array_values(array_filter([
            self::item('nav.my-space.home', __('general.dashboard'), 'dashboard', ['dashboard', 'inbox'], 'dashboard', 'nav.dashboard'),
            app(\App\Services\Board\BoardAccess::class)->inMenu($user) ? self::item('nav.board.meetings', __('board.nav'), 'board.meetings.index', ['board.*'], 'calendar', null, app(\App\Services\Board\BoardAccess::class)->canManage($user) ? 'board.absence-counter' : null) : null,
        ]));

        $structure = array_values(array_filter([
            ($editsCenters && $can('centers.view')) || $opens('centers') ? $centers : null,
            ($editsPrograms && $can('programs.view')) || $opens('programs') ? $programs : null,
            ($editsPositions && $can('positions.view')) || $opens('positions') ? $positions : null,
        ]));
        $editsStructure = ($editsCenters || $editsPrograms || $editsPositions) && $user->activeRoleIs('HR', ...config('access-control.administrator_roles', []));
        $structureItem = count($structure) > 1
            ? self::group('nav.org.structure', __('general.nav.programs-centers'), 'center', $structure)
            : ($structure[0] ?? null);

        $showBasicsInAdmin = $editsBasics && $can('project-basics.view');
        $newsInAdmin = $can('news.view') && $can('news.create') && $user->activeRoleIs('HR', ...config('access-control.administrator_roles', []));

        $people = array_values(array_filter([
            $can('employees.view') ? self::item('nav.hr.employees', trans_choice('general.employee', 2), 'employees.index', ['employees.*'], 'users', 'nav.employees') : null,
            ! $isManager && $can('contracts.view') ? self::item('nav.hr.contracts', trans_choice('general.contracts', 2), 'contracts.index', ['contracts.*'], 'contracts') : null,
            $isManager && $can('contracts.view') ? self::item('nav.my-team.contracts', __('general.nav.team-contracts'), 'contracts.index', ['contracts.*'], 'contracts') : null,
            $can('badges.view') ? self::item('nav.hr.badges', __('general.all-badges'), 'badges.index', ['badges.*'], 'badges', 'nav.badges') : null,
            $can('positions.view') ? self::item('nav.org.chart', __('general.orgn-chart'), 'org.chart', ['org.chart'], 'code-fork', 'nav.org-chart') : null,
            $isDeliverResponsible && $can('checklists.manage') ? self::item('nav.hr.done-requests', __('general.done_requests'), 'checklist-items.done-requests', ['checklist-items.done-requests'], 'check') : null,
            $isDeliverResponsible && $user->canHandOutItems() ? self::item('nav.my-team.hand-out', __('general.pending-delivers'), 'checklist-items.pending-delivers', ['checklist-items.pending-delivers'], 'items', 'nav.checklists', 'checklist-items.pending-delivers-counter') : null,
            $isDeliverResponsible && $can('checklists.receive') ? self::item('nav.my-team.take-back', __('general.pending_returns'), 'checklist-items.pending-returns', ['checklist-items.pending-returns'], 'items', null, 'checklist-items.pending-returns-counter') : null,
            ! $editsStructure ? $structureItem : null,
            ! $showBasicsInAdmin && $opens('project-basics') ? $basics : null,
            ! $newsInAdmin && $can('news.view') ? $news : null,
        ]));

        $time = array_values(array_filter([
            $can('leaves.view-own') ? self::item('nav.my-space.leaves', __('general.my-leaves'), 'leaves.index', ['leaves.index'], 'my-leaves', 'nav.my-leaves') : null,
            ! $isManager && $can('leaves.view-all') ? self::item('nav.hr.leaves', __('general.all-leaves'), 'leaves.all', ['leaves.all'], 'calendar', 'nav.leaves-management') : null,
            $isManager && $can('leaves.view-all') ? self::item('nav.my-team.leaves', __('general.nav.team-leaves'), 'leaves.all', ['leaves.all'], 'calendar', 'nav.leaves-management') : null,
            $can('leaves.manage-balances') ? self::item('nav.hr.balances', __('general.balance'), 'leaves.balance', ['leaves.balance'], 'calendar') : null,
            // An employee's own timesheet; managers and HR open the team's / everyone's.
            $can('attendance.view-own') && ! $can('attendance.view') ? self::item('nav.my-space.attendance', __('attendance.nav-own'), 'attendance.timesheets', ['attendance.*'], 'calendar') : null,
            ! $isManager && $can('attendance.view') ? self::item('nav.hr.attendance', __('attendance.nav'), 'attendance.timesheets', ['attendance.*'], 'calendar') : null,
            $isManager && $can('attendance.view') ? self::item('nav.my-team.attendance', __('attendance.nav-team'), 'attendance.timesheets', ['attendance.*'], 'calendar') : null,
            $holidaysForProjects ? self::item('nav.my-team.holidays', __('holidays.title'), 'holidays.index', ['holidays.*'], 'calendar') : null,
            $can('leaves.view-own') ? self::item('nav.my-space.team-calendar', __('team_calendar.nav'), 'team.calendar', ['team.calendar'], 'calendar') : null,
        ]));

        // Team 2: interview participants and offer viewers reach their pages
        // through the policies, without any recruitment permission.
        $recruitment = array_values(array_filter([
            $can('recruitment-requests.view') ? self::item('nav.recruitment.requests', __('recruitment.navigation_requests'), 'recruitment.requests.index', ['recruitment.requests.*'], 'recruitment-request') : null,
            app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($user) ? self::item('nav.recruitment.approvals', __('recruitment.navigation_pending_approvals'), 'recruitment.approvals.pending', ['recruitment.approvals.*'], 'recruitment-approval') : null,
            $can('recruitment-campaigns.view') ? self::item('nav.recruitment.campaigns', __('recruitment.navigation_campaigns'), 'recruitment.campaigns.index', ['recruitment.campaigns.*'], 'recruitment-campaign') : null,
            $user->can('viewAny', RecruitmentApplicant::class) ? self::item('nav.recruitment.applicants', __('recruitment.navigation_applicants'), 'recruitment.applicants.index', ['recruitment.applicants.*'], 'recruitment-applicant') : null,
            $user->can('viewAny', RecruitmentInterview::class) ? self::item('nav.recruitment.interviews', __('recruitment-applicant.interviews.title'), 'recruitment.interviews.index', ['recruitment.interviews.*'], 'recruitment-interview') : null,
            $user->can('viewAny', RecruitmentJobOffer::class) ? self::item('nav.recruitment.offers', __('recruitment-applicant.offers.title'), 'recruitment.offers.index', ['recruitment.offers.*'], 'recruitment-offer') : null,
        ]));

        $reports = array_values(array_filter([
            ReportCatalog::visibleTo($user) !== [] ? self::item('nav.settings.reports', __('Reports'), 'reports.index', ['reports.*'], 'activity-log') : null,
            $can('statistics.view') ? self::group('nav.hr.statistics', __('general.statistics'), 'statistics', [
                self::item('nav.hr.statistics.general', __('general.general-statistics'), 'statistics.general', ['statistics.general']),
                self::item('nav.hr.statistics.contracts', __('general.contract-statistics'), 'statistics.contract', ['statistics.contract']),
            ], 'nav.statistics') : null,
        ]));

        $admin = array_values(array_filter([
            $editsStructure ? $structureItem : null,
            $showBasicsInAdmin ? $basics : null,
            $can('entitlements.manage-library') && $user->activeRoleIs('HR', ...config('access-control.administrator_roles', [])) ? self::item('nav.settings.entitlements', __('entitlements.title'), 'entitlements.index', ['entitlements.*'], 'check-plus') : null,
            $can('checklists.manage') ? self::item('nav.hr.deliverables', trans_choice('general.default-deliverables', 2), 'default-deliverables.index', ['default-deliverables.*'], 'checklist') : null,
            $can('recruitment-indicators.manage') ? self::item('nav.recruitment.indicator-rules', __('recruitment-indicator-rules.navigation'), 'recruitment.indicator-rules', ['recruitment.indicator-rules'], 'recruitment-rules') : null,
            $can('message-templates.manage') ? self::item('nav.hr.message-templates', __('messages.title'), 'message-templates.index', ['message-templates.*'], 'clipboard-list') : null,
            $newsInAdmin ? $news : null,
            $can('access-management.view') ? self::item('nav.settings.access', __('access.title'), 'access-management.index', ['access-management.*'], 'cog', 'nav.access') : null,
            $can('activity-log.view') ? self::item('nav.settings.activity', __('general.activity-log'), 'activity-log.index', ['activity-log.*'], 'activity-log', 'nav.activity-log') : null,
            // D3 / DEC-08: the IT team only; not a role permission (User::canReadFeedback()).
            $user->canReadFeedback() ? self::item('nav.settings.feedback', __('feedback.inbox.title'), 'feedback.index', ['feedback.*'], 'activity-log') : null,
        ]));

        $account = array_values(array_filter([
            // A new volunteer's own journey, while it is open (and two weeks after); staff who never had one never see it.
            \App\Livewire\Onboarding\MyFirstDays::inMenuFor($user) ? self::item('nav.my-space.first-days', __('onboarding.first-days.title'), 'onboarding.my-first-days', ['onboarding.my-first-days', 'onboarding.policies'], 'clipboard-list') : null,
            $can('checklists.view-own') ? self::item('nav.my-space.items', __('general.my-items'), 'checklist-items.index', ['checklist-items.index'], 'clipboard-list', 'nav.my-items') : null,
            // Every employee reads the mailing lists and emails one; no permission to see it.
            // An account with no employee record (a system account) is not an employee.
            $user?->employee_id ? self::item('nav.my-space.mail-lists', __('mail_lists.title'), 'mail-lists.index', ['mail-lists.*'], 'envelope') : null,
            // Every signed-in employee reads the policies (and searches what they say); no permission.
            self::item('nav.my-space.policies', __('policies.title'), 'policies.index', ['policies.*'], 'guide-book'),
            // One page for both: "My folders" / "Shared with me" is a toggle on it (?scope=).
            $can('archives.view') ? self::item('nav.my-space.files', __('general.folders'), 'folders.index', ['folders.*'], 'archived', 'nav.archives') : null,
            // The AI assistant is not a menu item: it opens from the floating
            // button on every page (<x-assistant.widget>).
        ]));

        // The dashboard has no heading ('bare'); 'my-space' stays the id of the
        // person's own group, which is the one the icon rail keeps.
        $areas = array_values(array_filter([
            ['id' => 'home', 'label' => __('general.dashboard'), 'bare' => true, 'items' => $home],
            $people !== [] ? ['id' => 'people', 'label' => __('general.nav.area.people'), 'items' => $people] : null,
            $time !== [] ? ['id' => 'time', 'label' => __('general.nav.area.time'), 'items' => $time] : null,
            $recruitment !== [] ? ['id' => 'recruitment', 'label' => __('recruitment.navigation_section'), 'items' => $recruitment] : null,
            $reports !== [] ? ['id' => 'reports', 'label' => __('general.nav.area.reports'), 'items' => $reports] : null,
            $admin !== [] ? ['id' => 'admin', 'label' => __('general.nav.area.admin'), 'items' => $admin] : null,
            $account !== [] ? ['id' => 'my-space', 'label' => __('general.nav.area.my-space'), 'items' => $account] : null,
        ]));

        return ['inbox' => self::inbox($user, $can, $countInbox), 'areas' => $areas];
    }

    /**
     * The area label for a route, as the active role's menu places it: the
     * first crumb of the page. Null when the route is not in the menu.
     */
    public static function areaFor(?string $routeName, User $user, bool $isDeliverResponsible = false): ?string
    {
        if ($routeName === null) {
            return null;
        }

        $nav = self::for($user, $isDeliverResponsible);
        $matches = fn (array $item): bool => collect($item['active'] ?? [])->contains(fn ($pattern) => \Illuminate\Support\Str::is($pattern, $routeName));
        $contains = function (array $items) use (&$contains, $matches): bool {
            foreach ($items as $item) {
                if (isset($item['children']) ? $contains($item['children']) : $matches($item)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($nav['areas'] as $area) {
            if ($contains($area['items'])) {
                return $area['label'];
            }
        }

        return null;
    }

    /**
     * Every page of the active role's menu, flattened, with its name in both
     * languages: what the header search offers as pages (C2, C0-Q6). A page
     * that is not in the role's menu is not offered.
     *
     * @return list<array{route: string, area: string, label: string, label_en: string, label_ar: string}>
     */
    public static function pages(User $user, bool $isDeliverResponsible = false): array
    {
        $byLocale = [];
        $current = app()->getLocale();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $byLocale[$locale] = self::flatten(self::for($user, $isDeliverResponsible, countInbox: false));
        }

        app()->setLocale($current);

        $pages = [];
        foreach ($byLocale[$current] as $key => $page) {
            $pages[] = $page + [
                'label_en' => $byLocale['en'][$key]['label'] ?? $page['label'],
                'label_ar' => $byLocale['ar'][$key]['label'] ?? $page['label'],
            ];
        }

        return $pages;
    }

    /**
     * Every page of the active role's menu that may sit on the dashboard as a
     * shortcut tile (DashboardLayout), keyed by item id, with its icon and
     * counter. The same menu, so a page the role cannot open is never offered;
     * the dashboard itself is left out (it is the page the tiles sit on).
     *
     * @return array<string, array{route: string, label: string, icon: ?string, counter: ?string}>
     */
    public static function shortcuts(User $user, bool $isDeliverResponsible = false): array
    {
        return self::shortcutsFrom(self::for($user, $isDeliverResponsible, countInbox: false));
    }

    /**
     * shortcuts() for a menu already built (the sidebar has one), so the
     * permission checks are not run twice on one render.
     *
     * @return array<string, array{route: string, label: string, icon: ?string, counter: ?string}>
     */
    public static function shortcutsFrom(array $nav): array
    {
        // The inbox is part of the dashboard now, so it is no shortcut of its own.
        $pages = [];

        foreach ($nav['areas'] as $area) {
            foreach ($area['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $page) {
                    if ($page['route'] === 'dashboard') {
                        continue;
                    }

                    $pages[$page['id']] = [
                        'route' => $page['route'],
                        'label' => $page['label'],
                        'icon' => $page['icon'] ?? $item['icon'] ?? null,
                        'counter' => $page['counter'] ?? null,
                    ];
                }
            }
        }

        return $pages;
    }

    /**
     * The menu item a route belongs to (its `active` patterns), or null when
     * the route is not in this menu: what a visit is recorded as in recents.
     */
    public static function pageFor(array $nav, ?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        if ($nav['inbox'] && $routeName === $nav['inbox']['route']) {
            return 'nav.inbox';
        }

        foreach ($nav['areas'] as $area) {
            foreach ($area['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $page) {
                    if (\Illuminate\Support\Str::is($page['active'], $routeName)) {
                        return $page['id'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * What the header's New button may start for the active role (C2, C0-Q3):
     * the same permission each create screen checks on the server, which
     * checks it again when used. Contract also needs pay visibility: a contract
     * is its pay clause, and the salary-blind super-admin cannot fill it in.
     * `returns`: the screen sends Cancel back to where New was pressed, so the
     * link carries ?from=; the leave dialogs close in place and do not need it.
     *
     * @return list<array{id: string, label: string, route: string, params: array<string, mixed>, returns: bool}>
     */
    public static function newActions(User $user): array
    {
        $can = fn (string $permission): bool => $user->hasActivePermission($permission);

        return array_values(array_filter([
            $can('leaves.create') && $can('leaves.view-own')
                ? ['id' => 'new.leave', 'label' => __('general.new-menu.leave'), 'route' => 'leaves.index', 'params' => ['new' => 1], 'returns' => false] : null,
            // HR files a request for an employee from All leaves (the same rule as its Add button).
            $can('leaves.create') && $can('leaves.view-all') && $user->activeRoleIs('HR', 'super-admin')
                ? ['id' => 'new.leave-for-employee', 'label' => __('general.new-menu.leave-for-employee'), 'route' => 'leaves.all', 'params' => ['new' => 1], 'returns' => false] : null,
            $can('employees.create')
                ? ['id' => 'new.employee', 'label' => __('general.new-menu.employee'), 'route' => 'employees.create', 'params' => [], 'returns' => true] : null,
            $can('contracts.create') && $user->canViewSalary()
                ? ['id' => 'new.contract', 'label' => __('general.new-menu.contract'), 'route' => 'contracts.create', 'params' => [], 'returns' => true] : null,
            $can('news.create')
                ? ['id' => 'new.news', 'label' => __('general.new-menu.news'), 'route' => 'news.create', 'params' => [], 'returns' => true] : null,
        ]));
    }

    /**
     * Route names a return link may point to (SafeReturn): the pages of the
     * active role's menu, plus the record pages people start work from. Each
     * of those pages still authorizes the visit itself.
     *
     * @return list<string>
     */
    public static function returnRoutes(User $user, bool $isDeliverResponsible = false): array
    {
        $menu = array_column(self::flatten(self::for($user, $isDeliverResponsible, countInbox: false)), 'route');

        return array_values(array_unique([
            ...$menu,
            'dashboard', 'my-profile', 'notifications.index',
            'employees.show', 'contracts.show', 'programs.show', 'centers.show',
        ]));
    }

    /**
     * Staff of IT, CT and HR hand out and take back checklist items (the rule
     * the sidebar has always used).
     */
    public static function isDeliverResponsible(User $user): bool
    {
        $department = $user->profile?->contracts()->with('position.department')->first()?->position?->department?->code;

        return in_array($department, ['IT', 'CT', 'HR'], true);
    }

    /** @return array<string, array{route: string, area: string, label: string}> keyed by item id */
    private static function flatten(array $nav): array
    {
        $pages = [];

        if ($nav['inbox']) {
            $pages['nav.inbox'] = ['route' => $nav['inbox']['route'], 'area' => '', 'label' => __('general.nav.work-inbox')];
        }

        foreach ($nav['areas'] as $area) {
            foreach ($area['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $page) {
                    $pages[$page['id']] = ['route' => $page['route'], 'area' => $area['label'], 'label' => $page['label']];
                }
            }
        }

        return $pages;
    }

    /**
     * The work inbox (C0-Q2, C3): shown to roles that have an inbox source,
     * with the count of the very rows the inbox page lists (WorkInbox), never
     * an unscoped count (approval-source-contract.md §5).
     *
     * @return array{count: int, route: string}|null
     */
    private static function inbox(User $user, callable $can, bool $count = true): ?array
    {
        $inbox = app(WorkInbox::class);

        if (! $inbox->hasSources($user)) {
            return null;
        }

        if (! $count) {
            return ['count' => 0, 'route' => 'inbox'];
        }

        try {
            $count = $inbox->shared($user)['counts']['all'];
        } catch (Throwable $e) {
            // A badge must not take every page down with it.
            report($e);
            $count = 0;
        }

        return ['count' => $count, 'route' => 'inbox'];
    }

    /** @return array<string, mixed> */
    private static function item(string $id, string $label, string $route, array $active, ?string $icon = null, ?string $tour = null, ?string $counter = null): array
    {
        return compact('id', 'label', 'route', 'active', 'icon', 'tour', 'counter');
    }

    /** @return array<string, mixed> */
    private static function group(string $id, string $label, string $icon, array $children, ?string $tour = null): array
    {
        return compact('id', 'label', 'icon', 'children', 'tour');
    }
}
