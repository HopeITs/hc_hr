<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Employee\EmployeeDrawer;
use App\Livewire\Employee\EmployeeModule;
use App\Livewire\Employee\EmployeeTable;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * B4 employees slice: compact defaults, named views, read-only drawer, row actions,
 * phone cards, export wording. Maps to AC-B4-01 (return to list), AC-B4-02 (no state
 * across a role switch) and AC-B4-03 (task-focused list with phone cards).
 */
class EmployeeTableB4Test extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const HR_PERMS = ['employees.view', 'employees.update', 'employees.delete'];

    // ------------------------------------------------------------ AC-B4-03 defaults

    public function test_default_columns_are_compact_and_city_is_for_nationwide_roles_only(): void
    {
        $city = $this->makeCity('DMS', '003');
        $nationwide = $this->makeUser($city, 'HR-SYR', ['employees.view']);
        $regional = $this->makeUser($city, 'HR-DMS', ['employees.view']);

        Livewire::actingAs($nationwide)->test(EmployeeTable::class)
            ->assertSet('columns', fn (array $c) => array_slice($c, 0, 5) === ['employee', 'direct_manager', 'work_email', 'is_active', 'city_name_en']
                && ! array_intersect(['id', 'account', 'years_of_service_formatted', 'joined_at', 'photo'], $c));

        Livewire::actingAs($regional)->test(EmployeeTable::class)
            ->assertSet('columns', fn (array $c) => ! in_array('city_name_en', $c, true) && in_array('employee', $c, true));
    }

    public function test_identity_cell_shows_name_position_and_the_internal_avatar_url(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $employee = $this->makeEmployee($city, 'mira', ['first_name_en' => 'Mira', 'last_name_en' => 'Najjar']);

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertSee('Mira Najjar')
            ->assertSeeHtml('src="'.e($employee->avatar_url).'"')
            ->assertSeeHtml('aria-label="'.e(__('general.emp-open-details', ['name' => 'Mira Najjar'])).'"');
    }

    public function test_phone_cards_show_name_email_status_and_reachable_actions(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', self::HR_PERMS);
        $employee = $this->makeEmployee($city, 'mira', ['first_name_en' => 'Mira', 'last_name_en' => 'Najjar']);

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertSeeHtml('data-card="'.$employee->id.'"')
            ->assertSeeHtml('md:hidden')
            ->assertSee(__('general.active'))
            ->assertSeeHtml('aria-label="'.e(__('general.emp-more-actions', ['name' => 'Mira Najjar'])).'"')
            ->assertSeeHtml('aria-label="'.e(__('general.emp-view-profile-of', ['name' => 'Mira Najjar'])).'"');
    }

    public function test_row_click_opens_the_drawer_not_the_editor(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', self::HR_PERMS);
        $employee = $this->makeEmployee($city, 'mira');

        $component = Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertSeeHtml('employee-drawer')
            ->assertDontSee('Livewire.navigate', false);

        $this->assertSame("\$dispatch('employee-drawer', { id: {$employee->id} })", $component->instance()->rowClickAction($employee));
        $this->assertNull((fn () => $this->link($employee))->call($component->instance()));
    }

    // ------------------------------------------------------------ views (presets)

    public function test_active_is_the_default_view_and_inactive_is_available_to_hr_and_gm(): void
    {
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $gm = $this->makeUser($city, 'general-manager', ['employees.view']);
        $this->makeEmployee($city, 'working', ['first_name_en' => 'Zebulon']);
        $this->makeEmployee($city, 'former', ['first_name_en' => 'Quentin', 'is_active' => false]);

        Livewire::actingAs($hr)->test(EmployeeTable::class)
            ->assertSeeHtml('Open details of Zebulon Person')->assertDontSeeHtml('Open details of Quentin Person');

        foreach ([$hr, $gm] as $user) {
            Livewire::actingAs($user)->withQueryParams(['view' => 'inactive'])->test(EmployeeTable::class)
                ->assertSeeHtml('Open details of Quentin Person')->assertDontSeeHtml('Open details of Zebulon Person');
        }
    }

    public function test_inactive_view_is_refused_for_other_roles_even_by_url_or_event(): void
    {
        $city = $this->makeCity('DMS', '003');
        $manager = $this->makeUser($city, 'manager', ['employees.view']);
        $this->makeEmployee($city, 'working', ['first_name_en' => 'Zebulon']);
        $this->makeEmployee($city, 'former', ['first_name_en' => 'Quentin', 'is_active' => false]);

        Livewire::actingAs($manager)->withQueryParams(['view' => 'inactive'])->test(EmployeeTable::class)
            ->assertSeeHtml('Open details of Zebulon Person')->assertDontSeeHtml('Open details of Quentin Person')
            ->dispatch('employee-set-view', view: 'inactive')
            ->assertSet('preset', 'active')
            ->assertSeeHtml('Open details of Zebulon Person')->assertDontSeeHtml('Open details of Quentin Person');
    }

    public function test_view_only_narrows_the_city_scope_and_clear_all_restores_active(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $lat = $this->makeCity('LAT', '002');
        $hr = $this->makeUser($dms, 'HR-DMS', ['employees.view']);
        $this->makeEmployee($dms, 'former-in', ['first_name_en' => 'Quentin', 'is_active' => false]);
        $this->makeEmployee($lat, 'former-out', ['first_name_en' => 'Ottoline', 'is_active' => false]);

        Livewire::actingAs($hr)->test(EmployeeTable::class)
            ->dispatch('employee-set-view', view: 'inactive')
            ->assertSet('preset', 'inactive')
            ->assertDispatched('employee-view-changed')
            ->assertSeeHtml('Open details of Quentin Person')->assertDontSeeHtml('Open details of Ottoline Person')
            ->call('clearAll')
            ->assertSet('preset', 'active')
            ->assertDontSeeHtml('Open details of Quentin Person');
    }

    public function test_unknown_view_value_falls_back_to_active(): void
    {
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $this->makeEmployee($city, 'former', ['first_name_en' => 'Quentin', 'is_active' => false]);

        Livewire::actingAs($hr)->withQueryParams(['view' => 'everything'])->test(EmployeeTable::class)
            ->assertDontSeeHtml('Open details of Quentin Person')
            ->dispatch('employee-set-view', view: 'everything')->assertSet('preset', 'active');
    }

    // ------------------------------------------------------------ AC-B4-01 / AC-B4-02

    public function test_opening_the_drawer_leaves_search_filters_sort_and_page_untouched(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', self::HR_PERMS);
        foreach (range(1, 20) as $i) {
            $this->makeEmployee($city, "emp-$i", ['first_name_en' => "Zed$i"]);
        }
        $target = Employee::query()->where('username', 'emp-3')->first();

        $table = Livewire::actingAs($user)->test(EmployeeTable::class)
            ->set('globalSearch', 'Zed')->call('sort', 'employee')->call('gotoPage', 2);
        $state = [$table->get('globalSearch'), $table->get('sortColumn'), $table->get('sortDirection'), $table->get('paginators.page')];

        Livewire::actingAs($user)->test(EmployeeDrawer::class)
            ->call('open', $target->id)->assertSet('employeeId', $target->id)
            ->call('close')->assertSet('employeeId', null);

        // Same table again (return to the list): everything is where it was.
        $again = Livewire::actingAs($user)->test(EmployeeTable::class);
        $this->assertSame($state, [$again->get('globalSearch'), $again->get('sortColumn'), $again->get('sortDirection'), $again->get('paginators.page')]);
    }

    public function test_search_is_not_carried_across_a_role_switch(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $this->makeCity('LAT', '002');
        $user = $this->makeUser($dms, 'HR-DMS', ['employees.view']);
        $user = $this->assignInactiveRole($user, 'HR-LAT', ['employees.view']);

        Livewire::actingAs($user)->test(EmployeeTable::class)->set('globalSearch', 'secret-name');
        Livewire::actingAs($user)->test(EmployeeTable::class)->assertSet('globalSearch', 'secret-name');

        foreach ($user->roles()->get() as $role) {
            $user->roles()->updateExistingPivot($role->id, ['is_active' => $role->name === 'HR-LAT']);
        }
        $user = $user->unsetRelation('roles')->fresh();

        Livewire::actingAs($user)->test(EmployeeTable::class)->assertSet('globalSearch', '');
    }

    // ------------------------------------------------------------ drawer

    public function test_drawer_is_a_named_read_only_dialog_without_pay(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $employee = $this->makeEmployee($city, 'mira', ['first_name_en' => 'Mira', 'last_name_en' => 'Najjar']);

        Livewire::actingAs($user)->test(EmployeeDrawer::class)
            ->assertDontSeeHtml('role="dialog"')
            ->call('open', $employee->id)
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSeeHtml('x-on:keydown.escape.stop="close()"')
            ->assertSeeHtml('aria-label="'.e(__('general.emp-drawer-close')).'"')
            ->assertSee('Mira Najjar')
            ->assertDontSee('salary', false)
            ->call('close')
            ->assertDontSeeHtml('role="dialog"');
    }

    public function test_drawer_refuses_unauthorized_roles_and_records(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $lat = $this->makeCity('LAT', '002');
        $noPermission = $this->makeUser($dms, 'employee');
        $hr = $this->makeUser($dms, 'HR-DMS', ['employees.view']);
        $manager = $this->makeUser($dms, 'manager', ['employees.view']);
        $inCity = $this->makeEmployee($dms, 'in');
        $otherCity = $this->makeEmployee($lat, 'out');
        $former = $this->makeEmployee($dms, 'former', ['is_active' => false]);

        Livewire::actingAs($noPermission)->test(EmployeeDrawer::class)
            ->call('open', $inCity->id)->assertForbidden();

        // Outside the active city scope: indistinguishable from a missing record.
        Livewire::actingAs($hr)->test(EmployeeDrawer::class)
            ->call('open', $otherCity->id)->assertNotFound();

        // A former employee is HR / general manager only.
        Livewire::actingAs($manager)->test(EmployeeDrawer::class)
            ->call('open', $former->id)->assertNotFound();
        Livewire::actingAs($hr)->test(EmployeeDrawer::class)
            ->call('open', $former->id)->assertSet('employeeId', $former->id);
    }

    // ------------------------------------------------------------ actions

    public function test_row_actions_are_authorized_on_the_server(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $lat = $this->makeCity('LAT', '002');
        $viewer = $this->makeUser($dms, 'manager', ['employees.view']);
        $editor = $this->makeUser($dms, 'HR-DMS', self::HR_PERMS);
        $target = $this->makeEmployee($dms, 'target');
        $outside = $this->makeEmployee($lat, 'outside');

        // A role without update/delete cannot run the actions (nor see the menu items).
        Livewire::actingAs($viewer)->test(EmployeeTable::class)
            ->assertDontSee(__('general.emp-deactivate'))
            ->assertDontSee(__('general.delete'))
            ->call('confirmToggleItemStatus', $target->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(EmployeeTable::class)->call('confirmDelete', $target->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(EmployeeTable::class)->call('delete', ['id' => $target->id])->assertForbidden();
        Livewire::actingAs($viewer)->test(EmployeeTable::class)->call('toggleItemStatusConfirmed', ['id' => $target->id])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertNotNull(Employee::find($target->id));

        // The editor sees the menu and acts inside the city scope only.
        Livewire::actingAs($editor)->test(EmployeeTable::class)
            ->assertSee(__('general.emp-deactivate'))
            ->assertSee(__('general.edit'))
            ->call('toggleItemStatusConfirmed', ['id' => $outside->id])->assertNotFound();
        $this->assertTrue($outside->fresh()->is_active);

        Livewire::actingAs($editor)->test(EmployeeTable::class)->call('toggleItemStatusConfirmed', ['id' => $target->id]);
        $this->assertFalse($target->fresh()->is_active);
    }

    // ------------------------------------------------------------ export + page

    public function test_page_says_the_export_follows_the_columns_shown_and_gates_the_inactive_view(): void
    {
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS', ['employees.view']);
        $manager = $this->makeUser($city, 'manager', ['employees.view']);

        Livewire::actingAs($hr)->test(EmployeeModule::class)
            ->assertSee(__('general.emp-export-note'))
            ->assertSeeHtml('data-employee-view="inactive"');

        Livewire::actingAs($manager)->test(EmployeeModule::class)
            ->assertDontSeeHtml('data-employee-view="inactive"');
    }

    public function test_export_note_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('general.emp-export-note', __('general.emp-export-note'));
        }
        $this->assertSame('Exports the columns shown', __('general.emp-export-note', [], 'en'));
    }
}
