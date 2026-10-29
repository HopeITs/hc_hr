<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Layout\GlobalSearch;
use App\Livewire\Layout\Sidebar;
use App\Models\City;
use App\Models\NavigationPreference;
use App\Models\User;
use App\Support\NavigationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Prompt 9: sidebar favourites and the recents shown in an empty Ctrl+K box.
 * Stored ids never grant anything: every read goes through the active
 * role's menu (pages) or the directory scope (people).
 */
class NavigationFavouritesTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $aleppo;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
    }

    public function test_a_page_of_the_menu_can_be_starred_and_shows_in_my_favourites(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own']);

        $html = Livewire::actingAs($user)->test(Sidebar::class)
            ->call('toggleFavourite', 'nav.my-space.leaves')
            ->html();

        $this->assertSame(['nav.my-space.leaves'], $this->stored($user));
        $this->assertStringContainsString('data-nav-favourite="nav.my-space.leaves"', $html);
        $this->assertStringContainsString(__('navigation.favourites.title'), $html);
        // The star and the keyboard handle are wired to the component, with real labels.
        $this->assertStringContainsString("wire:click=\"toggleFavourite('nav.my-space.leaves')\"", $html);
        $this->assertStringContainsString('data-favourite-handle="nav.my-space.leaves"', $html);
        $this->assertStringContainsString(e(__('navigation.favourites.remove', ['page' => __('general.my-leaves')])), $html);

        // Starring it again removes it.
        Livewire::actingAs($user)->test(Sidebar::class)->call('toggleFavourite', 'nav.my-space.leaves');
        $this->assertSame([], $this->stored($user));
    }

    public function test_a_page_outside_the_active_roles_menu_cannot_be_starred(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own']);

        $html = Livewire::actingAs($user)->test(Sidebar::class)
            ->call('toggleFavourite', 'nav.hr.employees')   // needs employees.view
            ->call('toggleFavourite', 'nav.settings.access') // needs access-management.view
            ->call('toggleFavourite', 'not.a.menu.item')
            ->html();

        $this->assertSame([], $this->stored($user));
        $this->assertStringNotContainsString('data-nav-favourite=', $html);
        // The dashboard is the page the menu starts from; it carries no star.
        $this->assertStringNotContainsString('data-favourite-toggle="nav.my-space.home"', $html);
    }

    public function test_a_favourite_disappears_when_the_role_loses_the_permission_and_returns_with_it(): void
    {
        $user = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'leaves.view-own']);
        Livewire::actingAs($user)->test(Sidebar::class)
            ->call('toggleFavourite', 'nav.hr.employees')
            ->call('toggleFavourite', 'nav.my-space.leaves');

        $role = Role::findByName('HR-ALP', 'web');
        $role->revokePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $html = Livewire::actingAs($user->fresh())->test(Sidebar::class)->html();
        $this->assertStringNotContainsString('data-nav-favourite="nav.hr.employees"', $html);
        $this->assertStringContainsString('data-nav-favourite="nav.my-space.leaves"', $html);

        // The id is kept, so the favourite comes back with the permission.
        $role->givePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertStringContainsString(
            'data-nav-favourite="nav.hr.employees"',
            Livewire::actingAs($user->fresh())->test(Sidebar::class)->html(),
        );
    }

    public function test_at_most_eight_favourites_and_a_ninth_is_refused(): void
    {
        $user = $this->makeUser($this->aleppo, 'HR-ALP', [
            'leaves.view-own', 'checklists.view-own', 'archives.view', 'employees.view', 'contracts.view',
            'leaves.view-all', 'leaves.manage-balances', 'checklists.manage', 'badges.view', 'positions.view',
        ]);
        $ids = ['nav.my-space.leaves', 'nav.my-space.team-calendar', 'nav.my-space.items', 'nav.my-space.files',
            'nav.my-space.shared-files', 'nav.hr.employees', 'nav.hr.contracts', 'nav.hr.leaves', 'nav.hr.balances'];

        $component = Livewire::actingAs($user)->test(Sidebar::class);
        foreach ($ids as $id) {
            $component->call('toggleFavourite', $id);
        }

        $this->assertSame(array_slice($ids, 0, NavigationMemory::MAX_FAVOURITES), $this->stored($user));
        // An unstarred page now says the list is full instead of starring.
        $this->assertMatchesRegularExpression('/aria-disabled="true"[^>]*data-favourite-toggle="nav\.hr\.balances"/s', $component->html());
    }

    public function test_favourites_are_reordered_by_position_and_a_stale_position_is_clamped(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own', 'checklists.view-own', 'archives.view']);
        $component = Livewire::actingAs($user)->test(Sidebar::class)
            ->call('toggleFavourite', 'nav.my-space.leaves')
            ->call('toggleFavourite', 'nav.my-space.items')
            ->call('toggleFavourite', 'nav.my-space.files');

        $component->call('moveFavourite', 'nav.my-space.files', 0);
        $this->assertSame(['nav.my-space.files', 'nav.my-space.leaves', 'nav.my-space.items'], $this->stored($user));

        // Keyboard "down" from the last place, and a drag from a stale list.
        $component->call('moveFavourite', 'nav.my-space.leaves', 99);
        $this->assertSame(['nav.my-space.files', 'nav.my-space.items', 'nav.my-space.leaves'], $this->stored($user));

        // A page that is not a favourite is not inserted by a move.
        $component->call('moveFavourite', 'nav.my-space.shared-files', 0);
        $this->assertSame(['nav.my-space.files', 'nav.my-space.items', 'nav.my-space.leaves'], $this->stored($user));
    }

    public function test_opening_a_menu_page_and_a_profile_records_them_as_recents(): void
    {
        $colleague = $this->makeEmployee($this->homs, 'rami.recent', ['first_name_en' => 'Rami', 'last_name_en' => 'Saliba']);
        $user = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own']);

        $this->actingAs($user)->get(route('leaves.index'))->assertOk();
        Livewire::actingAs($user)->test(EmployeeProfile::class, ['employee' => $colleague]);
        // Opening one's own profile is not a "recent person".
        Livewire::actingAs($user)->test(EmployeeProfile::class, ['employee' => $user->profile]);

        $row = NavigationPreference::query()->where('user_id', $user->id)->first();
        $this->assertSame(['nav.my-space.leaves'], $row->recent_pages);
        $this->assertSame([$colleague->id], $row->recent_people);

        $html = Livewire::actingAs($user)->test(GlobalSearch::class)->call('showRecents')->html();
        $this->assertStringContainsString('data-testid="recent-page"', $html);
        $this->assertStringContainsString('data-testid="recent-person"', $html);
        $this->assertStringContainsString('Rami Saliba', $html);
        $this->assertStringContainsString(__('navigation.recent.people'), $html);
    }

    public function test_recents_are_only_loaded_once_the_empty_box_is_opened(): void
    {
        $user = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own']);
        NavigationMemory::recordPage($user, 'nav.my-space.leaves');

        $this->assertStringNotContainsString('data-testid="recent-page"', Livewire::actingAs($user)->test(GlobalSearch::class)->html());
    }

    public function test_recents_never_show_an_out_of_scope_employee(): void
    {
        $active = $this->makeEmployee($this->homs, 'sara.active', ['first_name_en' => 'Sara', 'last_name_en' => 'Haddad']);
        $former = $this->makeEmployee($this->homs, 'omar.former', ['first_name_en' => 'Omar', 'last_name_en' => 'Khoury']);
        $viewer = $this->makeUser($this->aleppo, 'employee', ['leaves.view-own']);
        $regionalHr = $this->makeUser($this->aleppo, 'HR-ALP', ['leaves.view-own']);
        $homsHr = $this->makeUser($this->homs, 'HR-HMS', ['leaves.view-own']);

        // Both were opened while Omar was still active; he has left since.
        foreach ([$viewer, $regionalHr, $homsHr] as $user) {
            NavigationMemory::recordPerson($user, $former->id);
            NavigationMemory::recordPerson($user, $active->id);
        }
        $former->forceFill(['is_active' => false])->save();

        foreach ([$viewer, $regionalHr] as $user) {
            $html = Livewire::actingAs($user)->test(GlobalSearch::class)->call('showRecents')->html();
            $this->assertStringContainsString('Sara Haddad', $html);
            $this->assertStringNotContainsString('Omar Khoury', $html);
        }

        // The regional HR of his own city still may.
        $this->assertStringContainsString(
            'Omar Khoury',
            Livewire::actingAs($homsHr)->test(GlobalSearch::class)->call('showRecents')->html(),
        );
    }

    public function test_a_recent_page_the_role_lost_is_not_offered(): void
    {
        $user = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'leaves.view-own']);
        NavigationMemory::recordPage($user, 'nav.hr.employees');

        $this->assertStringContainsString(
            'data-testid="recent-page"',
            Livewire::actingAs($user)->test(GlobalSearch::class)->call('showRecents')->html(),
        );

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('employees.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertStringNotContainsString(
            'data-testid="recent-page"',
            Livewire::actingAs($user->fresh())->test(GlobalSearch::class)->call('showRecents')->html(),
        );
    }

    /** @return list<string> */
    private function stored(User $user): array
    {
        return NavigationPreference::query()->where('user_id', $user->id)->first()?->favourites ?? [];
    }
}
