<?php

namespace Tests\Feature\Livewire;

use App\Models\SavedTableView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesHrFixtures;
use Tests\Fixtures\StateTestTable;
use Tests\TestCase;

/** B4-D section 5.1: personal saved table views. */
class SavedViewsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_a_view_is_saved_listed_applied_renamed_and_deleted_by_its_owner(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        $component = Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('globalSearch', 'zed')->set('filters.is_active', '1')->set('perPage', 25)
            ->call('saveView', 'My view')
            ->assertHasNoErrors();

        $view = SavedTableView::sole();
        $this->assertSame('hr-dms', $view->role_context);
        $this->assertSame('zed', $view->state['globalSearch']);

        $component->call('clearAll')->assertSet('globalSearch', '')
            ->call('applyView', $view->id)
            ->assertSet('globalSearch', 'zed')->assertSet('filters.is_active', '1')->assertSet('perPage', 25)
            ->assertSee('My view');

        $component->call('renameView', $view->id, 'Renamed')->assertHasNoErrors();
        $this->assertSame('Renamed', $view->fresh()->name);

        $component->call('deleteView', $view->id);
        $this->assertSame(0, SavedTableView::count());
    }

    public function test_views_are_private_to_their_owner(): void
    {
        $city = $this->makeCity('DMS', '003');
        $a = $this->makeUser($city, 'HR-DMS');
        $b = $this->makeUser($city, 'HR-DMS');

        Livewire::actingAs($a)->test(StateTestTable::class)->set('globalSearch', 'secret')->call('saveView', 'Mine');
        $id = SavedTableView::sole()->id;

        Livewire::actingAs($b)->test(StateTestTable::class)
            ->assertDontSee('Mine')
            ->call('applyView', $id)->assertNotFound();
        Livewire::actingAs($b)->test(StateTestTable::class)->call('renameView', $id, 'Hijack')->assertNotFound();
        Livewire::actingAs($b)->test(StateTestTable::class)->call('deleteView', $id)->assertNotFound();

        $this->assertSame('Mine', SavedTableView::sole()->name);
    }

    public function test_views_are_only_listed_and_usable_under_the_role_they_were_saved_in(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $this->makeCity('LAT', '002');
        $user = $this->makeUser($dms, 'HR-DMS');
        $user = $this->assignInactiveRole($user, 'HR-LAT');

        Livewire::actingAs($user)->test(StateTestTable::class)->set('globalSearch', 'x')->call('saveView', 'Dms only');
        $id = SavedTableView::sole()->id;

        $this->activate($user, 'HR-LAT');
        $latUser = User::find($user->id);

        Livewire::actingAs($latUser)->test(StateTestTable::class)
            ->assertDontSee('Dms only')
            ->call('applyView', $id)->assertNotFound();

        $this->activate($user, 'HR-DMS');
        Livewire::actingAs(User::find($user->id))->test(StateTestTable::class)->assertSee('Dms only');
    }

    public function test_at_most_ten_views_per_user_and_table(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');
        $component = Livewire::actingAs($user)->test(StateTestTable::class);

        foreach (range(1, 10) as $i) {
            $component->call('saveView', "View $i")->assertHasNoErrors();
        }

        $component->call('saveView', 'One too many')->assertHasErrors('savedViewName');
        $this->assertSame(10, SavedTableView::count());
    }

    public function test_name_is_required_and_unique_per_user(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');
        $component = Livewire::actingAs($user)->test(StateTestTable::class)
            ->call('saveView', '   ')->assertHasErrors('savedViewName')
            ->call('saveView', 'Same')->assertHasNoErrors()
            ->call('saveView', 'Same')->assertHasErrors('savedViewName');

        $component->call('saveView', str_repeat('a', 61))->assertHasErrors('savedViewName');
        $this->assertSame(1, SavedTableView::count());
    }

    public function test_stored_state_has_only_table_state_never_rows_or_pay(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS');
        $this->makeEmployee($city, 'visible-row-name');

        Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('globalSearch', 'abc')->call('saveView', 'State');

        $state = SavedTableView::sole()->state;
        $this->assertEqualsCanonicalizing(
            ['globalSearch', 'search', 'filters', 'sortColumn', 'sortDirection', 'perPage', 'columns', 'columnOrder'],
            array_keys($state),
        );
        $raw = json_encode($state);
        $this->assertStringNotContainsString('visible-row-name', $raw);
        $this->assertStringNotContainsString('salary', strtolower($raw));
    }

    public function test_applying_a_view_drops_a_filter_that_is_no_longer_allowed(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $user = $this->makeUser($dms, 'HR-DMS');

        // Hand-written state, as if the allowed city or the filter value changed since saving.
        SavedTableView::create([
            'user_id' => $user->id, 'table' => 'x', 'role_context' => 'hr-dms', 'name' => 'Stale',
            'state' => ['globalSearch' => 'keep', 'filters' => ['is_active' => '7'], 'search' => ['city_id' => '99999'], 'rows' => [1, 2], 'salary' => 5],
        ]);
        $view = SavedTableView::sole();
        $component = Livewire::actingAs($user)->test(StateTestTable::class);
        $view->update(['table' => $this->tableKey($component)]);

        $component->call('applyView', $view->id)
            ->assertSet('globalSearch', 'keep')
            ->assertSet('filters', [])
            ->assertSet('search', [])
            ->assertSet('stateReset', true);
    }

    public function test_deleting_the_user_deletes_their_views(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');
        Livewire::actingAs($user)->test(StateTestTable::class)->call('saveView', 'Gone');
        $this->assertSame(1, SavedTableView::count());

        $user->delete();

        $this->assertSame(0, SavedTableView::count());
    }

    public function test_a_guest_cannot_use_saved_views(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');
        $component = Livewire::actingAs($user)->test(StateTestTable::class);

        auth()->logout();

        $component->call('saveView', 'Nope')->assertForbidden();
        $this->assertSame(0, SavedTableView::count());
    }

    private function tableKey($component): string
    {
        return (new ReflectionMethod($component->instance(), 'savedViewTable'))->invoke($component->instance());
    }

    private function activate($user, string $roleName)
    {
        foreach ($user->roles()->get() as $role) {
            $user->roles()->updateExistingPivot($role->id, ['is_active' => $role->name === $roleName]);
        }

        return $user->unsetRelation('roles')->fresh();
    }
}
