<?php

namespace Tests\Feature\Livewire;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\Fixtures\StateTestTable;
use Tests\TestCase;

/** B4 shared table state: AC-B4-02 isolation, stale-page clamp, invalid filter drop, Clear all. */
class RemembersTableStateTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_state_is_not_restored_across_role_or_city_switch(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $this->makeCity('LAT', '002');
        $user = $this->makeUser($dms, 'HR-DMS');
        $user = $this->assignInactiveRole($user, 'HR-LAT');

        Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('globalSearch', 'zed')
            ->set('perPage', 25);

        // Same role again: restored.
        Livewire::actingAs($user)->test(StateTestTable::class)
            ->assertSet('globalSearch', 'zed')->assertSet('perPage', 25);

        // Switch active role (different city scope): nothing of the other key comes back.
        $user = $this->activate($user, 'HR-LAT');
        Livewire::actingAs($user)->test(StateTestTable::class)
            ->assertSet('globalSearch', '')->assertSet('perPage', 15);

        // And switching back still finds the original state, untouched.
        $user = $this->activate($user, 'HR-DMS');
        Livewire::actingAs($user)->test(StateTestTable::class)
            ->assertSet('globalSearch', 'zed');
    }

    public function test_state_is_not_shared_between_users(): void
    {
        $city = $this->makeCity('DMS', '003');
        $a = $this->makeUser($city, 'HR-DMS');
        $b = $this->makeUser($city, 'HR-DMS');

        Livewire::actingAs($a)->test(StateTestTable::class)->set('globalSearch', 'only-a');
        Livewire::actingAs($b)->test(StateTestTable::class)->assertSet('globalSearch', '');
    }

    public function test_url_wins_over_session(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');
        Livewire::actingAs($user)->test(StateTestTable::class)->set('globalSearch', 'saved');

        Livewire::actingAs($user)->withQueryParams(['globalSearch' => 'fromurl'])->test(StateTestTable::class)
            ->assertSet('globalSearch', 'fromurl');
    }

    public function test_page_past_the_end_is_clamped_to_the_last_page(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS');
        foreach (range(1, 20) as $i) {
            $this->makeEmployee($city, "emp-$i");
        } // 21 rows with the user's own employee -> 2 pages of 15

        Livewire::actingAs($user)->test(StateTestTable::class)->call('gotoPage', 9)
            ->assertSet('paginators.page', 2);

        // Also when restored from the session after rows disappeared.
        Livewire::actingAs($user)->test(StateTestTable::class)->call('gotoPage', 2);
        Employee::query()->where('username', 'like', 'emp-%')->delete();
        Livewire::actingAs($user)->test(StateTestTable::class)->assertSet('paginators.page', 1);
    }

    public function test_a_no_longer_allowed_filter_is_dropped_with_a_notice(): void
    {
        $dms = $this->makeCity('DMS', '003');
        $lat = $this->makeCity('LAT', '002');
        $user = $this->makeUser($dms, 'HR-DMS');
        $user = $this->assignInactiveRole($user, 'HR-LAT');

        // Allowed in the DMS scope.
        Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('search.city_id', (string) $dms->id)
            ->set('filters.is_active', '1')
            ->assertSet('stateReset', false)
            ->assertDontSee(__('general.state-notice-filter-cleared'));

        // Hand-built URL with a city outside the scope: dropped, valid filter kept.
        Livewire::actingAs($user)
            ->withQueryParams(['search' => ['city_id' => (string) $lat->id], 'filters' => ['is_active' => '1']])
            ->test(StateTestTable::class)
            ->assertSet('search', [])
            ->assertSet('filters', ['is_active' => '1'])
            ->assertSet('stateReset', true)
            ->assertSee(__('general.state-notice-filter-cleared'))
            ->call('dismissStateNotice')->assertSet('stateReset', false);
    }

    public function test_columns_the_role_may_not_see_are_never_restored(): void
    {
        $city = $this->makeCity('DMS', '003');
        $dmsUser = $this->makeUser($city, 'HR-DMS');
        $component = Livewire::actingAs($dmsUser)->test(StateTestTable::class);
        $key = (fn () => $this->sessionKey())->call($component->instance());

        // A stored list that still names the HR-SYR-only column is filtered by visibility.
        session()->put($key, ['columns' => ['username', 'is_active'], 'columnOrder' => ['is_active', 'username']]);

        Livewire::actingAs($dmsUser)->test(StateTestTable::class)->assertSet('columns', ['username']);
    }

    public function test_clear_all_resets_search_filters_sort_and_page_but_keeps_columns(): void
    {
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS');
        foreach (range(1, 20) as $i) {
            $this->makeEmployee($city, "emp-$i");
        }

        Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('columns', ['username'])
            ->set('globalSearch', 'e')
            ->set('filters.is_active', '1')
            ->call('sort', 'username')
            ->call('gotoPage', 2)
            ->assertSee(__('general.state-clear-all'))
            ->call('clearAll')
            ->assertSet('globalSearch', '')->assertSet('filters', [])->assertSet('search', [])
            ->assertSet('sortColumn', '')->assertSet('paginators.page', 1)
            ->assertSet('columns', ['username'])
            ->assertDontSee(__('general.state-clear-all'));

        // The cleared state is what comes back next time.
        Livewire::actingAs($user)->test(StateTestTable::class)
            ->assertSet('globalSearch', '')->assertSet('columns', ['username']);
    }

    public function test_chips_are_removable_and_card_layout_renders(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(StateTestTable::class)
            ->set('globalSearch', 'user-')
            ->set('filters.is_active', '1')
            ->assertSee('data-card', false)
            ->assertSee('aria-label="'.__('general.state-remove-filter', ['filter' => 'Active: Active']).'"', false)
            ->call('removeChip', 'filters', 'is_active')->assertSet('filters', [])
            ->call('removeChip', 'globalSearch')->assertSet('globalSearch', '')
            ->call('removeChip', 'bogus', 'x')->assertSet('filters', []);
    }

    private function activate($user, string $roleName)
    {
        foreach ($user->roles()->get() as $role) {
            $user->roles()->updateExistingPivot($role->id, ['is_active' => $role->name === $roleName]);
        }

        return $user->unsetRelation('roles')->fresh();
    }
}
