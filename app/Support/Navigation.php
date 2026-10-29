<?php

namespace App\Support;

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentJobOffer;
use App\Models\User;
use App\Services\HolidayProjects;
use App\Services\RecruitmentApproverEligibility;
use App\Services\WorkInbox;
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

        // A role that may change reference data finds it with its work
        // (HR operations / Settings); one that only reads it, under Organisation.
        $editsPrograms = $can('programs.create', 'programs.update');
        $editsPositions = $can('positions.create', 'positions.update');
        $editsCenters = $can('centers.create', 'centers.update');
        $editsBasics = $can('project-basics.create', 'project-basics.update', 'project-basics.delete');

        $mySpace = array_values(array_filter([
            self::item('nav.my-space.home', __('general.dashboard'), 'dashboard', ['dashboard', 'employee.dashboard'], 'dashboard', 'nav.dashboard'),
            $can('leaves.view-own') ? self::item('nav.my-space.leaves', __('general.my-leaves'), 'leaves.index', ['leaves.index'], 'my-leaves', 'nav.my-leaves') : null,
            $can('leaves.view-own') ? self::item('nav.my-space.team-calendar', __('team_calendar.nav'), 'team.calendar', ['team.calendar'], 'calendar') : null,
            $can('checklists.view-own') ? self::item('nav.my-space.items', __('general.my-items'), 'checklist-items.index', ['checklist-items.index'], 'clipboard-list', 'nav.my-items') : null,
            $can('archives.view') ? self::item('nav.my-space.files', __('general.my-folders'), 'my-folders.index', ['my-folders.*'], 'archived', 'nav.archives') : null,
            $can('archives.view') ? self::item('nav.my-space.shared-files', __('general.shared-folders'), 'shared-folders.index', ['shared-folders.*'], 'folder') : null,
        ]));

        $basics = self::group('nav.basics', __('general.project-basics'), 'home', array_values(array_filter([
            self::item('nav.basics.cities', trans_choice('general.city', 2), 'cities.index', ['cities.*']),
            self::item('nav.basics.contract-types', trans_choice('general.contract-type', 2), 'contract-types.index', ['contract-types.*']),
            self::item('nav.basics.leave-types', trans_choice('general.leave-type', 2), 'leave-types.index', ['leave-types.*']),
            self::item('nav.basics.annual-leave-rules', __('general.annual_leave_rules'), 'annual-leave-rules.index', ['annual-leave-rules.*']),
            self::item('nav.basics.termination-reasons', __('general.termination_reasons'), 'termination-reasons.index', ['termination-reasons.*']),
            self::item('nav.basics.checklist-items', trans_choice('general.checklist-items', 2), 'basic-checklist-items.index', ['basic-checklist-items.*']),
            self::item('nav.basics.job-levels', trans_choice('general.job-level', 2), 'job-levels.index', ['job-levels.*']),
            self::item('nav.basics.departments', trans_choice('general.department', 2), 'departments.index', ['departments.*']),
            self::item('nav.basics.course-categories', trans_choice('general.course-category', 2), 'course-categories.index', ['course-categories.*']),
            // Set by national HR only (D-4 refinement); others never see the link.
            $user->activeRoleIs(...config('access-control.administrator_roles', []))
                ? self::item('nav.basics.holidays', __('holidays.title'), 'holidays.index', ['holidays.*'])
                : null,
        ])), 'nav.project-basics');

        $programs = self::item('nav.programs', trans_choice('general.program', 2), 'programs.index', ['programs.*'], 'program', 'nav.programs');
        $positions = self::item('nav.positions', __('general.positions'), 'positions.index', ['positions.*'], 'layers');
        $centers = self::item('nav.centers', trans_choice('general.center', 2), 'centers.index', ['centers.*'], 'center', 'nav.centers');
        $news = self::item('nav.news', __('general.news-management'), 'news.index', ['news.*'], 'newspaper', 'nav.news');

        $hrOps = array_values(array_filter([
            $can('employees.view') ? self::item('nav.hr.employees', trans_choice('general.employee', 2), 'employees.index', ['employees.*'], 'users', 'nav.employees') : null,
            ! $isManager && $can('contracts.view') ? self::item('nav.hr.contracts', trans_choice('general.contracts', 2), 'contracts.index', ['contracts.*'], 'contracts') : null,
            ! $isManager && $can('leaves.view-all') ? self::item('nav.hr.leaves', __('general.all-leaves'), 'leaves.all', ['leaves.all'], 'calendar', 'nav.leaves-management') : null,
            $can('leaves.manage-balances') ? self::item('nav.hr.balances', __('general.balance'), 'leaves.balance', ['leaves.balance'], 'calendar') : null,
            $can('checklists.manage') ? self::item('nav.hr.deliverables', trans_choice('general.default-deliverables', 2), 'default-deliverables.index', ['default-deliverables.*'], 'checklist') : null,
            $isDeliverResponsible && $can('checklists.manage') ? self::item('nav.hr.done-requests', __('general.done_requests'), 'checklist-items.done-requests', ['checklist-items.done-requests'], 'check') : null,
            $editsPrograms && $can('programs.view') ? $programs : null,
            $editsPositions && $can('positions.view') ? $positions : null,
            $editsCenters && $can('centers.view') ? $centers : null,
            $can('badges.view') ? self::item('nav.hr.badges', __('general.all-badges'), 'badges.index', ['badges.*'], 'badges', 'nav.badges') : null,
            $can('statistics.view') ? self::group('nav.hr.statistics', __('general.statistics'), 'statistics', [
                self::item('nav.hr.statistics.general', __('general.general-statistics'), 'statistics.general', ['statistics.general']),
                self::item('nav.hr.statistics.contracts', __('general.contract-statistics'), 'statistics.contract', ['statistics.contract']),
            ], 'nav.statistics') : null,
        ]));

        // News sits with HR's work when there is any; a manager, who has no
        // HR operations, finds it under Organisation instead.
        $newsInHr = $can('news.view') && $hrOps !== [];
        if ($newsInHr) {
            $hrOps[] = $news;
        }

        // C0-Q4 and G-B-13 (answer 2 both): the directory and org chart for
        // everyone; the other reference pages are closed to `employee`, and to
        // `manager` except centers and programs (a manager needs them for the
        // team). One list (access-control.reference_pages_hidden_from) drives
        // both this menu and the pages' own route check.
        $opens = fn (string $page): bool => $user->canOpenReferencePage($page);

        $organisation = array_values(array_filter([
            $can('positions.view') ? self::item('nav.org.chart', __('general.orgn-chart'), 'org.chart', ['org.chart'], 'code-fork', 'nav.org-chart') : null,
            ! $editsCenters && $opens('centers') ? $centers : null,
            ! $editsPrograms && $opens('programs') ? $programs : null,
            ! $editsPositions && $opens('positions') ? $positions : null,
            ! $editsBasics && $opens('project-basics') ? $basics : null,
            ! $newsInHr && $can('news.view') ? $news : null,
        ]));

        // A program manager sets holidays for their projects (§6.1); national HR finds the page under Settings.
        $holidaysForProjects = ! $user->activeRoleIs(...config('access-control.administrator_roles', []))
            && app(HolidayProjects::class)->managesAnyProject($user);

        $myTeam = array_values(array_filter([
            $holidaysForProjects ? self::item('nav.my-team.holidays', __('holidays.title'), 'holidays.index', ['holidays.*'], 'calendar') : null,
            $isManager && $can('leaves.view-all') ? self::item('nav.my-team.leaves', __('general.nav.team-leaves'), 'leaves.all', ['leaves.all'], 'calendar', 'nav.leaves-management') : null,
            $isManager && $can('contracts.view') ? self::item('nav.my-team.contracts', __('general.nav.team-contracts'), 'contracts.index', ['contracts.*'], 'contracts') : null,
            $isDeliverResponsible && $can('checklists.deliver') ? self::item('nav.my-team.hand-out', __('general.pending-delivers'), 'checklist-items.pending-delivers', ['checklist-items.pending-delivers'], 'items', 'nav.checklists', 'checklist-items.pending-delivers-counter') : null,
            $isDeliverResponsible && $can('checklists.receive') ? self::item('nav.my-team.take-back', __('general.pending_returns'), 'checklist-items.pending-returns', ['checklist-items.pending-returns'], 'items', null, 'checklist-items.pending-returns-counter') : null,
        ]));

        // Team 2: interview participants and offer viewers reach their pages
        // through the policies, without any recruitment permission.
        $recruitment = array_values(array_filter([
            $can('recruitment-requests.view') ? self::item('nav.recruitment.requests', __('recruitment.navigation_requests'), 'recruitment.requests.index', ['recruitment.requests.*'], 'clipboard-list') : null,
            app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($user) ? self::item('nav.recruitment.approvals', __('recruitment.navigation_pending_approvals'), 'recruitment.approvals.pending', ['recruitment.approvals.*'], 'check') : null,
            $can('recruitment-campaigns.view') ? self::item('nav.recruitment.campaigns', __('recruitment.navigation_campaigns'), 'recruitment.campaigns.index', ['recruitment.campaigns.*'], 'newspaper') : null,
            $can('recruitment-applicants.view') ? self::item('nav.recruitment.applicants', __('recruitment.navigation_applicants'), 'recruitment.applicants.index', ['recruitment.applicants.*'], 'users') : null,
            $can('recruitment-indicators.manage') ? self::item('nav.recruitment.indicator-rules', __('recruitment-indicator-rules.navigation'), 'recruitment.indicator-rules', ['recruitment.indicator-rules'], 'check') : null,
            $user->can('viewAny', RecruitmentInterview::class) ? self::item('nav.recruitment.interviews', __('recruitment-applicant.interviews.title'), 'recruitment.interviews.index', ['recruitment.interviews.*'], 'calendar') : null,
            $user->can('viewAny', RecruitmentJobOffer::class) ? self::item('nav.recruitment.offers', __('recruitment-applicant.offers.title'), 'recruitment.offers.index', ['recruitment.offers.*'], 'clipboard-list') : null,
        ]));

        $settings = array_values(array_filter([
            $editsBasics && $can('project-basics.view') ? $basics : null,
            $can('access-management.view') ? self::item('nav.settings.access', __('access.title'), 'access-management.index', ['access-management.*'], 'cog', 'nav.access') : null,
            $can('activity-log.view') ? self::item('nav.settings.activity', __('general.activity-log'), 'activity-log.index', ['activity-log.*'], 'activity-log', 'nav.activity-log') : null,
            // D3 / DEC-08: the IT team only; not a role permission (User::canReadFeedback()).
            $user->canReadFeedback() ? self::item('nav.settings.feedback', __('feedback.inbox.title'), 'feedback.index', ['feedback.*'], 'activity-log') : null,
        ]));

        // Organisation (reference pages, C0-Q4) comes last: C0 put it under My
        // space, but there its long list pushed a manager's My team below the
        // fold. Frequent work first is C1's objective (c1-implementation.md §2).
        $areas = array_values(array_filter([
            ['id' => 'my-space', 'label' => __('general.nav.area.my-space'), 'items' => $mySpace],
            $myTeam !== [] ? ['id' => 'my-team', 'label' => __('general.nav.area.my-team'), 'items' => $myTeam] : null,
            $hrOps !== [] ? ['id' => 'hr', 'label' => __('general.nav.area.hr'), 'items' => $hrOps] : null,
            $recruitment !== [] ? ['id' => 'recruitment', 'label' => __('recruitment.navigation_section'), 'items' => $recruitment] : null,
            $settings !== [] ? ['id' => 'settings', 'label' => __('general.nav.area.settings'), 'items' => $settings] : null,
            $organisation !== [] ? ['id' => 'organisation', 'label' => __('general.nav.organisation'), 'items' => $organisation] : null,
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
     * What the header's New button may start for the active role (C2, C0-Q3):
     * the same permission each create screen checks on the server, which
     * checks it again when used. Contract also needs pay visibility: a contract
     * is its pay clause, and the salary-blind super-admin cannot fill it in.
     *
     * @return list<array{id: string, label: string, route: string, params: array<string, mixed>}>
     */
    public static function newActions(User $user): array
    {
        $can = fn (string $permission): bool => $user->hasActivePermission($permission);

        return array_values(array_filter([
            $can('leaves.create') && $can('leaves.view-own')
                ? ['id' => 'new.leave', 'label' => __('general.new-menu.leave'), 'route' => 'leaves.index', 'params' => ['new' => 1]] : null,
            // HR files a request for an employee from All leaves (the same rule as its Add button).
            $can('leaves.create') && $can('leaves.view-all') && $user->activeRoleIs('HR', 'super-admin')
                ? ['id' => 'new.leave-for-employee', 'label' => __('general.new-menu.leave-for-employee'), 'route' => 'leaves.all', 'params' => ['new' => 1]] : null,
            $can('employees.create')
                ? ['id' => 'new.employee', 'label' => __('general.new-menu.employee'), 'route' => 'employees.create', 'params' => []] : null,
            $can('contracts.create') && $user->canViewSalary()
                ? ['id' => 'new.contract', 'label' => __('general.new-menu.contract'), 'route' => 'contracts.create', 'params' => []] : null,
            $can('news.create')
                ? ['id' => 'new.news', 'label' => __('general.new-menu.news'), 'route' => 'news.create', 'params' => []] : null,
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
            $count = $inbox->count($user);
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
