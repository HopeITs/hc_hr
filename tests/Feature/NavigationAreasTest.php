<?php

namespace Tests\Feature;

use App\Livewire\Layout\Sidebar;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * C1: the sidebar grouped by whose work it is (C0, interaction-contract.md §4).
 * The menu decides where a page sits, never whether it may be opened.
 */
class NavigationAreasTest extends TestCase
{
    use RefreshDatabase;

    private const BASICS = [
        'cities.index', 'contract-types.index', 'leave-types.index', 'annual-leave-rules.index',
        'termination-reasons.index', 'basic-checklist-items.index', 'job-levels.index',
        'departments.index', 'course-categories.index',
    ];

    // C5 added the team calendar to everyone's My space.
    private const MY_SPACE = ['dashboard', 'leaves.index', 'team.calendar', 'checklist-items.index', 'my-folders.index', 'shared-folders.index'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * AC-C1-02: every page the old sidebar offered a role is still in its menu,
     * and nothing new appears (the routes are the old sidebar's, per role).
     */
    public function test_each_role_keeps_exactly_the_pages_it_had(): void
    {
        $reference = ['org.chart', 'centers.index', 'programs.index', 'positions.index'];
        $hrWork = ['employees.index', 'contracts.index', 'leaves.all', 'default-deliverables.index', 'badges.index', 'news.index', 'statistics.general', 'statistics.contract'];

        // Recruitment (Team 2): the pages the old sidebar's recruitment group showed each role.
        $recruiting = ['recruitment.requests.index', 'recruitment.campaigns.index', 'recruitment.applicants.index', 'recruitment.interviews.index'];
        $hrRecruiting = [...$recruiting, 'recruitment.approvals.pending', 'recruitment.offers.index'];

        $expected = [
            // C0-Q4 (answer 2): an employee keeps only the org chart; a manager also centers and programs.
            'employee' => [...self::MY_SPACE, 'org.chart'],
            'manager' => [...self::MY_SPACE, 'org.chart', 'centers.index', 'programs.index', 'leaves.all', 'contracts.index', 'news.index', 'inbox', ...$recruiting],
            'general-manager' => [...self::MY_SPACE, ...$reference, ...self::BASICS, ...$hrWork, 'inbox', ...$recruiting],
            'HR-ALP' => [...self::MY_SPACE, ...$reference, ...self::BASICS, ...$hrWork, 'leaves.balance', 'inbox', ...$hrRecruiting],
            'HR-SYR' => [...self::MY_SPACE, ...$reference, ...self::BASICS, 'holidays.index', ...$hrWork, 'leaves.balance', 'access-management.index', 'activity-log.index', 'inbox', ...$hrRecruiting, 'recruitment.indicator-rules'],
            // The D3 feedback list follows an IT contract, not a role (InAppFeedbackTest).
            'super-admin' => [...self::MY_SPACE, ...$reference, ...self::BASICS, 'holidays.index', ...$hrWork, 'leaves.balance', 'access-management.index', 'activity-log.index', 'inbox', ...$recruiting, 'recruitment.offers.index', 'recruitment.indicator-rules'],
        ];

        foreach ($expected as $role => $routes) {
            $html = $this->sidebarHtml($this->userWithRole($role));
            $shown = $this->menuRoutes($html);

            sort($routes);
            $this->assertSame(array_values(array_unique($routes)), $shown, "Menu of {$role}");
        }
    }

    /** AC-C1-01: every link in a role's menu opens for that role. */
    public function test_every_menu_link_opens_for_the_role_that_sees_it(): void
    {
        foreach (['employee', 'manager', 'general-manager', 'HR-ALP', 'HR-SYR', 'super-admin'] as $role) {
            $user = $this->userWithRole($role);

            foreach ($this->menuHrefs($this->sidebarHtml($user)) as $href) {
                $status = $this->actingAs($user)->get($href)->getStatusCode();

                $this->assertNotContains($status, [403, 404], "{$role} → {$href} returned {$status}");
            }
        }
    }

    /** The areas follow the active role: who sees which area, and where shared pages sit. */
    public function test_areas_and_placement_follow_the_active_role(): void
    {
        $employee = $this->sidebarHtml($this->userWithRole('employee'));
        $this->assertStringContainsString('data-nav-area="my-space"', $employee);
        $this->assertStringNotContainsString('data-nav-area="my-team"', $employee);
        $this->assertStringNotContainsString('data-nav-area="hr"', $employee);
        $this->assertStringNotContainsString('data-nav="nav.inbox"', $employee);
        // C0-Q4 (answer 2): an employee keeps the org chart only.
        $this->assertStringContainsString('data-nav="nav.org.chart"', $employee);
        $this->assertStringNotContainsString('data-nav="nav.basics.cities"', $employee);
        $this->assertStringNotContainsString('data-nav="nav.centers"', $employee);

        $manager = $this->sidebarHtml($this->userWithRole('manager'));
        $this->assertStringContainsString('data-nav="nav.my-team.leaves"', $manager);
        $this->assertStringContainsString('data-nav="nav.my-team.contracts"', $manager);
        $this->assertStringNotContainsString('data-nav-area="hr"', $manager);
        $this->assertStringContainsString('data-nav="nav.inbox"', $manager);
        // A manager keeps centers and programs for the team; positions and basics leave the menu.
        $this->assertStringContainsString('data-nav="nav.centers"', $manager);
        $this->assertStringContainsString('data-nav="nav.programs"', $manager);
        $this->assertStringNotContainsString('data-nav="nav.positions"', $manager);
        $this->assertStringNotContainsString('data-nav="nav.basics.cities"', $manager);

        $hr = $this->sidebarHtml($this->userWithRole('HR-ALP'));
        $this->assertStringContainsString('data-nav="nav.hr.contracts"', $hr);
        $this->assertStringContainsString('data-nav="nav.hr.leaves"', $hr);
        $this->assertStringNotContainsString('data-nav-area="my-team"', $hr);
        // HR edits the basics, so they sit in Settings, not under Organisation.
        $this->assertMatchesRegularExpression('/data-nav-area="settings".*data-nav="nav\.basics\.cities"/s', $hr);
        $this->assertStringNotContainsString('data-nav="nav.settings.access"', $hr);

        $admin = $this->sidebarHtml($this->userWithRole('super-admin'));
        $this->assertStringContainsString('data-nav="nav.settings.access"', $admin);
        $this->assertStringContainsString('data-nav="nav.settings.activity"', $admin);
    }

    /**
     * G-B-13 (answer 2): a reference page left out of a role's menu is closed
     * by URL too, from the same list; the org chart stays open to everyone,
     * and a manager keeps centers and programs.
     */
    public function test_reference_pages_left_out_of_the_menu_are_refused_by_url(): void
    {
        $cases = [
            'employee' => [
                'refused' => [...self::BASICS, 'centers.index', 'programs.index', 'positions.index'],
                'open' => ['org.chart'],
            ],
            'manager' => [
                'refused' => [...self::BASICS, 'positions.index'],
                'open' => ['org.chart', 'centers.index', 'programs.index'],
            ],
        ];

        foreach ($cases as $role => $routes) {
            $user = $this->userWithRole($role);
            // The read permission is kept: the list refuses the page, not the permission.
            $this->assertTrue($user->hasActivePermission('positions.view'));

            foreach ($routes['refused'] as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden();
            }

            foreach ($routes['open'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
        }
    }

    /** Switching the active role changes the menu on the next render. */
    public function test_switching_the_active_role_changes_the_menu(): void
    {
        $user = $this->userWithRole('employee');
        $hrRole = Role::findOrCreate('HR-ALP', 'web');
        $user->assignRole($hrRole);

        $before = $this->sidebarHtml($user->fresh());
        $this->assertStringNotContainsString('data-nav-area="hr"', $before);

        $user->fresh()->setActiveRole($hrRole->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $this->sidebarHtml($user->fresh());
        $this->assertStringContainsString('data-nav-area="hr"', $after);
        $this->assertStringContainsString(e(role_label('HR-ALP')), $after);
    }

    /** The inbox shows a count for roles that decide or review, from the scoped leave query. */
    public function test_the_inbox_shows_a_count_only_for_roles_with_a_source(): void
    {
        $this->assertStringNotContainsString('data-testid="nav-badge"', $this->sidebarHtml($this->userWithRole('employee')));

        $html = $this->sidebarHtml($this->userWithRole('manager'));
        $this->assertMatchesRegularExpression('/data-testid="nav-badge">0</', $html);
    }

    /** The first crumb is the page's area for the active role. */
    public function test_breadcrumbs_start_with_the_area_of_the_active_role(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->userWithRole('manager'))
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee(__('general.nav.area.my-team'));

        $this->actingAs($this->userWithRole('HR-ALP'))
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee(__('general.nav.area.hr'));
    }

    /** The current page stays highlighted when the drawer re-renders through Livewire's update route. */
    public function test_the_current_page_stays_highlighted_after_the_drawer_opens(): void
    {
        $user = $this->userWithRole('employee');

        $this->actingAs($user)->get(route('leaves.index'))
            ->assertOk()
            ->assertSee('leaves.index', false);

        $html = Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->set('currentRoute', 'leaves.index')
            ->call('toggle')
            ->html();

        // Prompt 9: the current page is marked by aria-current (ink text and a red bar), not a red fill.
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-nav="nav\.my-space\.leaves"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-nav="nav\.my-space\.home"/s', $html);
    }

    private function sidebarHtml(User $user): string
    {
        return Livewire::actingAs($user)->test(Sidebar::class)->html();
    }

    /** @return list<string> the sorted route names linked from the menu */
    private function menuRoutes(string $html): array
    {
        $names = [];

        foreach ($this->menuHrefs($html) as $href) {
            $names[] = app('router')->getRoutes()->match(Request::create($href))->getName() ?? $href;
        }

        // C3: the work inbox is its own page (route `inbox`) for roles with a source.
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> hrefs of the links that carry a stable menu id */
    private function menuHrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bdata-nav="[^"]+"[^>]*>/', $html, $tags);
        $hrefs = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/href="([^"]+)"/', $tag, $m)) {
                $hrefs[] = html_entity_decode($m[1]);
            }
        }

        return array_values(array_unique($hrefs));
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->where('slug', 'ALP')->first()
            ?? City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Nav', 'first_name_ar' => 'تنقل',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار',
            'username' => uniqid('nav.', true),
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => uniqid('nav-', true).'@example.test',
            'password' => 'password',
        ]);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
