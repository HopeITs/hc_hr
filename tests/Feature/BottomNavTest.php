<?php

namespace Tests\Feature;

use App\Livewire\Layout\BottomBarPicker;
use App\Livewire\Layout\BottomNav;
use App\Models\City;
use App\Models\Employee;
use App\Models\NavigationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkInbox;
use App\Support\BottomBar;
use App\Support\Navigation;
use App\Support\NavigationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as BaseRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * D2 phone bottom navigation: permission-aware, every link opens for the role
 * that sees it, the inbox count is the menu's, and the bar steps aside so it
 * never covers a focused field or a sticky save row (AC-D2-01, AC-D2-02).
 *
 * Prompt 10, app style: four places, two on each side of a raised + (the New
 * sheet: the role's New menu), and, always last, More (the whole menu in a
 * sheet). A person may
 * choose their four (BottomBarPicker); a choice only ever holds shortcuts
 * the active role may use, and a place the role lost falls back to the
 * default bar.
 */
class BottomNavTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const LEAVES = 'app.nav.my-space.leaves';

    private const INBOX = 'app.nav.inbox';

    private const CALENDAR = 'app.nav.my-space.team-calendar';

    public function test_each_role_gets_the_destinations_its_permissions_open(): void
    {
        $this->assertSame(['home', self::LEAVES, self::CALENDAR, 'profile'], $this->keys($this->userWithRole('employee')));
        // Four places: with the inbox, My profile stays in the avatar menu.
        $this->assertSame(['home', self::LEAVES, self::INBOX, self::CALENDAR], $this->keys($this->userWithRole('manager')));
        $this->assertSame(['home', self::LEAVES, self::INBOX, self::CALENDAR], $this->keys($this->userWithRole('HR-ALP')));

        // No leave permission, no employee record: only Home.
        $bare = User::query()->create(['email' => 'bare@example.test', 'password' => 'password']);
        $role = Role::findOrCreate('staff', 'web');
        $bare->assignRole($role);
        $bare->setActiveRole($role->id);
        $this->assertSame(['home'], $this->keys($bare->fresh()));
    }

    public function test_more_is_always_the_last_place_and_opens_the_more_sheet(): void
    {
        $bare = User::query()->create(['email' => 'bare-more@example.test', 'password' => 'password']);
        $role = Role::findOrCreate('staff', 'web');
        $bare->assignRole($role);
        $bare->setActiveRole($role->id);

        foreach ([$this->userWithRole('employee'), $this->userWithRole('HR-ALP'), $bare->fresh()] as $user) {
            $html = Livewire::actingAs($user)->test(BottomNav::class)->html();

            $this->assertSame(1, substr_count($html, 'data-testid="bottom-nav-more"'));
            // After every other place.
            $this->assertGreaterThan(strrpos($html, 'data-testid="bottom-nav-'.last($this->keys($user)).'"'), strpos($html, 'data-testid="bottom-nav-more"'));
            // It opens its own sheet, a real dialog: modal, focus trapped and given back, Escape.
            $this->assertStringContainsString('x-on:click="open(\'more\')"', $html);
            $this->assertStringContainsString('aria-controls="bottom-sheet-more"', $html);
            $this->assertStringContainsString(e(__('mobile.bar.more-label')), $html);
            $this->assertMatchesRegularExpression('/id="bottom-sheet-more" role="dialog" aria-modal="true" aria-labelledby="bottom-sheet-more-title"/', $html);
            $this->assertStringContainsString('x-trap.inert.noscroll="sheet === \'more\'"', $html);
            $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="close()"', $html);
            $this->assertStringContainsString('env(safe-area-inset-bottom', $html);
        }
    }

    /** The + opens the New sheet: exactly the header's New menu for the role, between places 2 and 3. */
    public function test_the_plus_sheet_offers_only_what_the_role_may_start(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);
        $hr = $this->makeUser($city, 'HR-ALP', ['leaves.view-own', 'leaves.create', 'leaves.view-all', 'employees.view', 'employees.create', 'contracts.view', 'contracts.create', 'news.view', 'news.create']);

        $cases = [
            [$employee, ['new.leave']],
            [$hr, array_column(Navigation::newActions($hr), 'id')],
        ];
        $this->assertContains('new.employee', $cases[1][1]);
        $this->assertContains('new.leave-for-employee', $cases[1][1]);
        $this->assertContains('new.news', $cases[1][1]);

        foreach ($cases as [$user, $expected]) {
            $this->assertSame($expected, array_column(Navigation::newActions($user), 'id'));

            $html = Livewire::actingAs($user)->test(BottomNav::class)->html();
            preg_match_all('/data-new="([^"]+)"/', $html, $offered);
            $this->assertSame($expected, $offered[1]);

            $this->assertSame(1, substr_count($html, 'data-testid="bottom-nav-new"'));
            $this->assertMatchesRegularExpression('/id="bottom-sheet-new" role="dialog" aria-modal="true"/', $html);
            $this->assertStringContainsString('x-trap.inert.noscroll="sheet === \'new\'"', $html);
            $this->assertStringContainsString(e(__('mobile.bar.new-label')), $html);

            // Two places before the +, two after it.
            $keys = $this->keys($user);
            $plus = strpos($html, 'data-testid="bottom-nav-new"');
            $this->assertLessThan($plus, strpos($html, 'data-testid="bottom-nav-'.$keys[1].'"'));
            $this->assertGreaterThan($plus, strpos($html, 'data-testid="bottom-nav-'.$keys[2].'"'));

            // Every action opens for the role (each screen checks the permission again).
            preg_match_all('/href="([^"]+)" data-new=/', $html, $links);
            $this->assertCount(count($expected), $links[1]);
            foreach ($links[1] as $href) {
                $this->assertNotContains($this->actingAs($user)->get(html_entity_decode($href))->getStatusCode(), [403, 404, 500], $href);
            }
        }

        // Contract needs pay visibility as well as contracts.create: the salary-blind super-admin is not offered it.
        $admin = $this->makeUser($city, 'super-admin', ['contracts.create']);
        $this->assertStringNotContainsString('data-new="new.contract"', Livewire::actingAs($admin)->test(BottomNav::class)->html());
    }

    public function test_a_role_that_may_start_nothing_has_no_plus(): void
    {
        $city = $this->makeCity('ALP', '001');
        $reader = $this->makeUser($city, 'viewer', ['leaves.view-own']);

        $html = Livewire::actingAs($reader)->test(BottomNav::class)->html();

        $this->assertStringNotContainsString('data-testid="bottom-nav-new"', $html);
        $this->assertStringNotContainsString('id="bottom-sheet-new"', $html);
        $this->assertStringContainsString('data-testid="bottom-nav-more"', $html);
    }

    /** The More sheet is the role's whole menu: every page of it, and nothing else. */
    public function test_the_more_sheet_holds_the_roles_whole_menu(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create', 'checklists.view-own']);
        $hr = $this->makeUser($city, 'HR-ALP', ['leaves.view-own', 'leaves.view-all', 'employees.view', 'contracts.view', 'badges.view', 'statistics.view']);

        foreach ([$employee, $hr] as $user) {
            $component = Livewire::actingAs($user)->test(BottomNav::class);
            $html = $component->html();

            $expected = array_values(array_unique(array_map(fn (array $page) => route($page['route']), Navigation::pages($user, Navigation::isDeliverResponsible($user)))));
            $shown = collect($component->viewData('sections'))->pluck('rows')->flatten(1)
                ->whereNotNull('href')->reject(fn (array $row) => $row['id'] === 'my-profile')->pluck('href')->unique()->values()->all();
            $this->assertEqualsCanonicalizing($expected, $shown);

            // My profile on top for a person with an employee record.
            $this->assertStringContainsString('data-testid="bottom-more-my-profile"', $html);
            // Each area of the menu is a labelled section.
            foreach (Navigation::for($user, Navigation::isDeliverResponsible($user), countInbox: false)['areas'] as $area) {
                $this->assertStringContainsString('aria-labelledby="bottom-more-'.$area['id'].'"', $html);
            }
        }

        $employeeHtml = Livewire::actingAs($employee)->test(BottomNav::class)->html();
        $hrHtml = Livewire::actingAs($hr)->test(BottomNav::class)->html();

        foreach (['nav.hr.employees', 'nav.hr.contracts', 'nav.hr.leaves', 'nav.hr.badges', 'nav.hr.statistics.general'] as $hrOnly) {
            $this->assertStringContainsString('data-testid="bottom-more-'.$hrOnly.'"', $hrHtml);
            $this->assertStringNotContainsString('data-testid="bottom-more-'.$hrOnly.'"', $employeeHtml);
        }
        $this->assertStringContainsString('data-testid="bottom-more-nav.my-space.items"', $employeeHtml);

        // The current page is marked in the sheet too.
        $this->assertMatchesRegularExpression('/data-testid="bottom-more-nav\.hr\.employees"\s+aria-current="page"/',
            Livewire::actingAs($hr)->test(BottomNav::class)->set('currentRoute', 'employees.index')->html());
    }

    /** The default bar repeats the menu's own checks for its pages: it must agree with the menu. */
    public function test_the_default_bar_agrees_with_the_roles_menu(): void
    {
        foreach (['employee', 'manager', 'HR-ALP'] as $roleName) {
            $user = $this->userWithRole($roleName);
            $options = BottomBar::options($user);

            foreach (BottomBar::slots($user) as $place) {
                $this->assertArrayHasKey($place['key'], $options, "{$roleName}: {$place['key']} is not a shortcut of the menu");
                $this->assertSame($options[$place['key']]['href'], $place['href'], "{$roleName}: {$place['key']}");
            }
        }
    }

    public function test_every_link_opens_for_the_role_that_sees_it_in_both_languages(): void
    {
        foreach (['employee', 'manager', 'HR-ALP'] as $roleName) {
            $user = $this->userWithRole($roleName);

            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $html = Livewire::actingAs($user)->test(BottomNav::class)->html();
                preg_match_all('/href="([^"]+)"/', $html, $links);

                $this->assertNotEmpty($links[1]);

                foreach ($links[1] as $href) {
                    $status = $this->actingAs($user)->get($href)->getStatusCode();
                    $this->assertNotContains($status, [403, 404, 500], "{$roleName} {$locale} → {$href}");
                }
            }
        }
    }

    public function test_the_inbox_count_is_the_menus_count_and_the_current_page_is_marked(): void
    {
        $manager = $this->userWithRole('manager');

        $component = Livewire::actingAs($manager)->test(BottomNav::class)->set('currentRoute', 'inbox');
        $items = collect($component->viewData('items'))->keyBy('key');

        $this->assertSame(app(WorkInbox::class)->count($manager), $items[self::INBOX]['count']);
        $component->assertSeeHtml('data-testid="bottom-nav-'.self::INBOX.'"')
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_a_chosen_bar_is_shown_in_its_order_and_every_place_opens(): void
    {
        $hr = $this->userWithRole('HR-ALP');
        $chosen = ['app.nav.hr.employees', 'app.new.leave', 'profile', 'home'];

        $this->assertTrue(NavigationMemory::setBottomBar($hr, $chosen, BottomBar::options($hr), BottomBar::SLOTS));
        $this->assertSame($chosen, $this->keys($hr));

        $component = Livewire::actingAs($hr)->test(BottomNav::class);
        preg_match_all('/href="([^"]+)" wire:navigate data-testid="bottom-nav-/', $component->html(), $links);
        $this->assertCount(4, $links[1]);
        foreach ($links[1] as $href) {
            $this->assertNotContains($this->actingAs($hr)->get(html_entity_decode($href))->getStatusCode(), [403, 404, 500], $href);
        }

        // No inbox on the bar: nothing is counted.
        foreach ($component->viewData('items') as $place) {
            $this->assertArrayNotHasKey('count', $place);
        }
    }

    public function test_a_place_whose_page_the_role_lost_falls_back_to_the_default_and_comes_back(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.view', 'leaves.view-own', 'contracts.view']);
        NavigationMemory::setBottomBar($hr, ['app.nav.hr.employees', 'app.nav.hr.contracts', 'home', 'profile'], BottomBar::options($hr), BottomBar::SLOTS);

        BaseRole::findByName('HR-ALP', 'web')->revokePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Employees is gone; the first default the bar does not already show takes its place.
        $this->assertSame(['app.nav.hr.contracts', 'home', 'profile', self::LEAVES], $this->keys($hr->fresh()));
        // The choice itself is kept, so it comes back with the permission.
        $this->assertContains('app.nav.hr.employees', NavigationPreference::query()->where('user_id', $hr->id)->value('bottom_bar'));

        BaseRole::findByName('HR-ALP', 'web')->givePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(['app.nav.hr.employees', 'app.nav.hr.contracts', 'home', 'profile'], $this->keys($hr->fresh()));
    }

    public function test_the_picker_offers_only_what_the_role_may_use(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);

        $picker = Livewire::actingAs($employee)->test(BottomBarPicker::class);
        $offered = array_merge(array_keys($picker->viewData('pages')), array_keys($picker->viewData('actions')));

        $this->assertEqualsCanonicalizing(array_keys(BottomBar::options($employee)), $offered);
        $this->assertContains('app.new.leave', $offered);
        foreach (['app.nav.hr.employees', 'app.new.employee', 'app.nav.settings.access', 'app.new.contract'] as $forbidden) {
            $this->assertNotContains($forbidden, $offered);
        }

        // A shortcut the role may not use, or a place that is not there, changes nothing.
        $picker->call('setSlot', 0, 'app.nav.hr.employees')
            ->call('setSlot', 0, 'app.new.employee')
            ->call('setSlot', 0, 'not.a.shortcut')
            ->call('setSlot', 4, 'home')
            ->call('setSlot', -1, 'home')
            ->assertNotDispatched('bottom-bar-changed');
        $this->assertNull(NavigationPreference::query()->where('user_id', $employee->id)->value('bottom_bar'));
    }

    public function test_picking_keeps_exactly_four_places_and_swaps_a_shortcut_already_on_the_bar(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create', 'checklists.view-own']);
        $this->assertSame(['home', self::LEAVES, self::CALENDAR, 'profile'], $this->keys($employee));

        $picker = Livewire::actingAs($employee)->test(BottomBarPicker::class)
            ->call('setSlot', 0, 'app.new.leave')
            ->assertDispatched('bottom-bar-changed')
            ->assertSee(__('navigation.bottom-bar.saved'));
        $this->assertSame(['app.new.leave', self::LEAVES, self::CALENDAR, 'profile'], $this->stored($employee));

        // Profile is already in place 4: the two swap, nothing is there twice.
        $picker->call('setSlot', 1, 'profile');
        $this->assertSame(['app.new.leave', 'profile', self::CALENDAR, self::LEAVES], $this->stored($employee));

        $picker->call('setSlot', 3, 'app.nav.my-space.items');
        $this->assertSame(['app.new.leave', 'profile', self::CALENDAR, 'app.nav.my-space.items'], $this->stored($employee));
        $this->assertSame($this->stored($employee), $this->keys($employee));

        // NavigationMemory itself refuses anything but four different allowed shortcuts.
        $options = BottomBar::options($employee);
        $this->assertFalse(NavigationMemory::setBottomBar($employee, ['home', 'profile', self::LEAVES], $options, BottomBar::SLOTS));
        $this->assertFalse(NavigationMemory::setBottomBar($employee, ['home', 'home', 'profile', self::LEAVES], $options, BottomBar::SLOTS));
        $this->assertFalse(NavigationMemory::setBottomBar($employee, ['home', 'profile', self::LEAVES, 'app.nav.hr.employees'], $options, BottomBar::SLOTS));
        $this->assertFalse(NavigationMemory::setBottomBar($employee, ['home', 'profile', self::LEAVES, 7], $options, BottomBar::SLOTS));
        $this->assertSame(['app.new.leave', 'profile', self::CALENDAR, 'app.nav.my-space.items'], $this->stored($employee));
    }

    public function test_reset_brings_the_default_bar_back(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);

        $picker = Livewire::actingAs($employee)->test(BottomBarPicker::class)->call('setSlot', 0, 'app.new.leave');
        $picker->assertSeeHtml('data-testid="bottom-bar-reset"');

        $picker->call('resetBar')
            ->assertDispatched('bottom-bar-changed')
            ->assertSee(__('navigation.bottom-bar.reset-done'))
            ->assertDontSeeHtml('data-testid="bottom-bar-reset"');
        $this->assertNull($this->stored($employee));
        $this->assertSame(['home', self::LEAVES, self::CALENDAR, 'profile'], $this->keys($employee));
    }

    public function test_the_picker_has_a_labelled_select_per_place_and_more_last_in_both_languages(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = Livewire::actingAs($employee)->test(BottomBarPicker::class)->html();

            for ($place = 0; $place < BottomBar::SLOTS; $place++) {
                $this->assertStringContainsString('<label for="bottom-bar-place-'.$place.'"', $html);
                $this->assertStringContainsString('<select id="bottom-bar-place-'.$place.'"', $html);
                $this->assertStringContainsString('wire:change="setSlot('.$place.', $event.target.value)"', $html);
            }
            $this->assertSame(BottomBar::SLOTS, substr_count($html, '<select '));
            $this->assertStringContainsString(e(__('mobile.bar.more-place')), $html);
            $this->assertStringContainsString(e(__('mobile.bar.lead')), $html);
            // Two places before the + and two after it.
            $this->assertStringContainsString(e(__('mobile.bar.place-before', ['number' => 2])), $html);
            $this->assertStringContainsString(e(__('mobile.bar.place-after', ['number' => 3])), $html);
            $this->assertStringContainsString('role="status" aria-live="polite"', $html);
            // The shortcut in each place is the selected option.
            $this->assertStringContainsString('<option value="home" selected', $html);
        }
    }

    public function test_the_picker_is_kept_per_person(): void
    {
        $city = $this->makeCity('ALP', '001');
        $first = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);
        $second = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);

        Livewire::actingAs($first)->test(BottomBarPicker::class)->call('setSlot', 0, 'app.new.leave');

        $this->assertSame('app.new.leave', $this->keys($first)[0]);
        $this->assertSame('home', $this->keys($second)[0]);
        $this->assertNull($this->stored($second));
    }

    public function test_the_shell_carries_the_bar_the_safe_areas_and_the_connection_notice(): void
    {
        $html = $this->actingAs($this->userWithRole('employee'))->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-testid="bottom-nav"', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('data-testid="offline-notice"', $html);
        // D2-PWA registers a worker that stores no private response (PwaInstallTest checks it).
    }

    /** AC-D2-02: the stylesheet makes the bar step aside for focused fields and sticky save rows. */
    public function test_the_bar_steps_aside_for_focused_fields_and_save_rows(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('html:has(.m1-action-bar) .app-bottom-nav', $css);
        $this->assertStringContainsString('html:has([data-tour="contract.save"]) .app-bottom-nav', $css);
        $this->assertMatchesRegularExpression('/body:has\(:is\(input[^)]*\), textarea, select[^)]*\):focus\) \.app-bottom-nav/', $css);
    }

    private function keys(User $user): array
    {
        return array_column(Livewire::actingAs($user)->test(BottomNav::class)->viewData('items'), 'key');
    }

    private function stored(User $user): ?array
    {
        return NavigationPreference::query()->where('user_id', $user->id)->first()?->bottom_bar;
    }

    private function userWithRole(string $roleName): User
    {
        $city = City::query()->where('slug', 'ALP')->first()
            ?? City::query()->forceCreate(['slug' => 'ALP', 'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'code' => '001', 'is_active' => true]);
        $employee = Employee::query()->create([
            'city_id' => $city->id, 'first_name_en' => 'Bar', 'first_name_ar' => 'شريط',
            'last_name_en' => 'Tester', 'last_name_ar' => 'اختبار', 'username' => uniqid('bar.', true), 'is_active' => true,
        ]);
        $user = User::query()->create(['employee_id' => $employee->id, 'email' => uniqid('bar-', true).'@example.test', 'password' => 'password']);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->setActiveRole($role->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
