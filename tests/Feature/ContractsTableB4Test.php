<?php

namespace Tests\Feature;

use App\Exports\ContractExport;
use App\Livewire\Contract\ContractDrawer;
use App\Livewire\Contract\ContractsTable;
use App\Models\City;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * B4 contracts list: compact defaults, named views, read-only drawer, row
 * actions and phone cards (docs/ux/table-contract.md §3, §5, §6).
 *
 * AC-B4-01 state survives the drawer, AC-B4-02 no cross-scope restore,
 * AC-B4-03 every row/card control is named and authorized.
 */
class ContractsTableB4Test extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const SALARY = 8731;

    private const HR = ['contracts.view', 'contracts.create', 'contracts.update', 'contracts.delete', 'contracts.export'];

    private City $dms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dms = $this->makeCity('DMS', '003');
    }

    // ------------------------------------------------------------ defaults

    public function test_compact_default_columns_and_city_only_for_nationwide_roles(): void
    {
        $this->contract('Aya');

        $cityScoped = Livewire::actingAs($this->hr())->test(ContractsTable::class);
        $labels = fn ($component) => collect($component->instance()->columns)->all();
        $this->assertSame(
            ['employee', 'contractType_name_en', 'period', 'is_active', 'auto_renew'],
            array_slice($labels($cityScoped), 0, 5),
        );
        $this->assertNotContains('city', $labels($cityScoped));
        $this->assertNotContains('duration', $labels($cityScoped));
        $this->assertNotContains('position_title_en', $labels($cityScoped));

        $hq = $this->makeUser($this->dms, 'HR-SYR', self::HR);
        $this->assertContains('city', $labels(Livewire::actingAs($hq)->test(ContractsTable::class)));
    }

    public function test_identity_cell_shows_position_and_period_shows_ends_in_days(): void
    {
        $this->contract('Aya', ['start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addDays(12)->toDateString()]);
        $this->contract('Bana', ['start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addDays(90)->toDateString()]);

        Livewire::actingAs($this->hr())->test(ContractsTable::class)
            ->assertSee('Program Officer')
            ->assertSee('Ends in 12 days')
            ->assertDontSee('Ends in 90 days');
    }

    // ------------------------------------------------------------- presets

    public function test_presets_narrow_the_list(): void
    {
        $this->contract('Running');
        $this->contract('Soon', ['start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addDays(10)->toDateString()]);
        $this->contract('Gone', ['is_active' => false, 'terminated_at' => now()->subMonth()->toDateString()]);
        $this->contract('Lapsed', ['start_date' => '2025-01-01', 'end_date' => now()->subDays(3)->toDateString(), 'is_active' => false]);
        $this->contract('Reserved', ['type' => 4, 'is_active' => false]);

        $table = Livewire::actingAs($this->hr())->test(ContractsTable::class)
            ->assertSee(['Running', 'Soon'])->assertDontSee(['Gone', 'Lapsed', 'Reserved']);

        $table->call('setPreset', 'ending-soon')->assertSee('Soon')->assertDontSee(['Running', 'Gone', 'Lapsed', 'Reserved'])
            ->call('setPreset', 'ended')->assertSee(['Gone', 'Lapsed'])->assertDontSee(['Running', 'Soon', 'Reserved'])
            ->call('setPreset', 'draft')->assertSee('Reserved')->assertDontSee(['Running', 'Soon', 'Gone', 'Lapsed'])
            ->assertSee(__('general.state-clear-all'))
            ->call('clearAll')->assertSet('preset', 'active')->assertSee('Running');
    }

    public function test_preset_comes_from_the_view_url_parameter(): void
    {
        $this->contract('Running');
        $this->contract('Reserved', ['type' => 4, 'is_active' => false]);

        Livewire::actingAs($this->hr())->withQueryParams(['view' => 'draft'])->test(ContractsTable::class)
            ->assertSet('preset', 'draft')->assertSee('Reserved')->assertDontSee('Running');
    }

    public function test_manager_only_gets_the_active_view_and_only_their_own_reports(): void
    {
        [$manager, $managed] = $this->manager();
        $this->makeContractFor($this->makeEmployee($this->dms, 'rep', ['first_name_en' => 'Myreport']), [
            'position_id' => $this->makePosition($this->dms, ['direct_manager_id' => $managed->id])->id,
        ]);
        $this->makeContractFor($this->makeEmployee($this->dms, 'rep-ended', ['first_name_en' => 'Myended']), [
            'position_id' => $this->makePosition($this->dms, ['direct_manager_id' => $managed->id])->id,
            'is_active' => false, 'terminated_at' => now()->subMonth()->toDateString(),
        ]);
        $this->contract('Stranger');
        $this->contract('Strangerended', ['is_active' => false, 'terminated_at' => now()->subMonth()->toDateString()]);

        $table = Livewire::actingAs($manager)->test(ContractsTable::class)
            ->assertSee('Myreport')->assertDontSee(['Stranger', 'Myended'])
            ->assertDontSee(__('contracts.view-ended'));   // no preset bar for a single view

        // A forged call or a pasted link cannot widen the manager's views.
        $table->call('setPreset', 'ended')->assertForbidden();
        Livewire::actingAs($manager)->withQueryParams(['view' => 'ended'])->test(ContractsTable::class)
            ->assertSet('preset', 'active')
            ->assertSet('stateReset', true)
            ->assertDontSee(['Myended', 'Stranger', 'Strangerended']);
    }

    // ------------------------------------------------- AC-B4-01 / AC-B4-02

    public function test_opening_the_drawer_keeps_search_filters_sort_and_page(): void
    {
        $user = $this->hr();
        foreach (range(1, 16) as $i) {
            $this->contract('Joseph');
        }
        $target = $this->contract('Joseph');

        $table = Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph')
            ->set('filters', [])
            ->call('sort', 'employee')
            ->call('gotoPage', 2);
        $before = [$table->get('globalSearch'), $table->get('search'), $table->get('sortColumn'), $table->get('sortDirection'), $table->get('paginators.page')];

        Livewire::actingAs($user)->test(ContractDrawer::class)
            ->dispatch('open-contract-drawer', contractId: $target->id)
            ->assertSee('Joseph Zed')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSeeHtml('aria-label="'.__('contracts.drawer-close').'"')
            ->call('close')
            ->assertSet('contractId', null)
            ->assertDispatched('contract-drawer-closed');

        // Same user, same scope: everything is exactly as it was left.
        $after = Livewire::actingAs($user)->test(ContractsTable::class);
        $this->assertSame($before, [$after->get('globalSearch'), $after->get('search'), $after->get('sortColumn'), $after->get('sortDirection'), $after->get('paginators.page')]);
        $this->assertSame(2, $after->get('paginators.page'));
    }

    public function test_row_click_and_view_open_the_drawer_never_the_editor(): void
    {
        $contract = $this->contract('Aya');

        $html = Livewire::actingAs($this->hr())->test(ContractsTable::class)->html();

        $this->assertStringContainsString('open-contract-drawer', $html);
        $this->assertStringNotContainsString("Livewire.navigate('".route('contracts.edit', $contract)."')", $html);
        $this->assertStringNotContainsString("Livewire.navigate('".route('contracts.show', $contract)."')", $html);
    }

    public function test_drawer_refuses_a_contract_the_role_cannot_see(): void
    {
        [$manager] = $this->manager();
        $stranger = $this->contract('Stranger');

        Livewire::actingAs($manager)->test(ContractDrawer::class)
            ->call('open', $stranger->id)
            ->assertForbidden();

        Livewire::actingAs($manager)->test(ContractDrawer::class)
            ->assertSet('contractId', null)
            ->assertDontSee('Stranger');
    }

    public function test_drawer_never_carries_a_pay_figure(): void
    {
        $contract = $this->contract('Aya');

        $drawer = Livewire::actingAs($this->hr())->test(ContractDrawer::class)
            ->call('open', $contract->id)
            ->assertSee('Aya Zed');

        $this->assertStringNotContainsString((string) self::SALARY, $drawer->html());
        $this->assertStringNotContainsString('8,731', $drawer->html());
        $this->assertStringNotContainsString('salary', strtolower(json_encode($drawer->snapshot)));
    }

    public function test_state_is_not_restored_across_role_or_city_switch(): void
    {
        $lat = $this->makeCity('LAT', '002');
        $user = $this->hr();
        $user = $this->assignInactiveRole($user, 'HR-LAT', self::HR);

        Livewire::actingAs($user)->test(ContractsTable::class)->set('globalSearch', 'zed')->set('perPage', 25);
        Livewire::actingAs($user)->test(ContractsTable::class)->assertSet('globalSearch', 'zed');

        foreach ($user->roles()->get() as $role) {
            $user->roles()->updateExistingPivot($role->id, ['is_active' => $role->name === 'HR-LAT']);
        }
        $user = $user->unsetRelation('roles')->fresh();

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->assertSet('globalSearch', '')->assertSet('perPage', 15)->assertSet('preset', 'active');
        $this->assertNotNull($lat);
    }

    public function test_a_city_filter_outside_the_active_scope_is_dropped_not_applied(): void
    {
        $lat = $this->makeCity('LAT', '002');
        $this->contract('Mine');

        Livewire::actingAs($this->hr())->withQueryParams(['search' => ['city' => (string) $lat->id]])
            ->test(ContractsTable::class)
            ->assertSet('search', [])
            ->assertSet('stateReset', true)
            ->assertSee('Mine');
    }

    // --------------------------------------------------------- actions/cards

    public function test_every_row_control_has_an_accessible_name_and_menu_items_are_authorized_per_role(): void
    {
        $contract = $this->contract('Aya');
        $name = 'Aya Zed';

        $html = Livewire::actingAs($this->hr())->test(ContractsTable::class)->html();
        $this->assertStringContainsString('aria-label="'.e(__('contracts.view-named', ['name' => $name])).'"', $html);
        $this->assertStringContainsString('aria-label="'.e(__('contracts.more-actions-for', ['name' => $name])).'"', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('role="menuitem"', $html);
        $this->assertStringContainsString(route('contracts.edit', $contract), $html);
        // HR may see pay, so the PDF is offered; a role barred from pay figures never gets it.
        $this->assertStringContainsString(__('general.export-pdf'), $html);
        $gm = $this->makeUser($this->dms, 'general-manager', ['contracts.view', 'contracts.export']);
        $this->assertStringNotContainsString(__('general.export-pdf'), Livewire::actingAs($gm)->test(ContractsTable::class)->html());

        // View-only role: View yes, no menu at all.
        [$manager, $managed] = $this->manager();
        $this->makeContractFor($this->makeEmployee($this->dms, 'rep', ['first_name_en' => 'Myreport']), [
            'position_id' => $this->makePosition($this->dms, ['direct_manager_id' => $managed->id])->id,
        ]);
        $managerHtml = Livewire::actingAs($manager)->test(ContractsTable::class)->html();
        $this->assertStringContainsString('data-contract-view', $managerHtml);
        $this->assertStringNotContainsString('aria-haspopup="menu"', $managerHtml);
        $this->assertStringNotContainsString('contracts/'.$contract->id.'/edit', $managerHtml);
    }

    public function test_phone_cards_render_with_the_named_actions_and_no_pay(): void
    {
        $this->contract('Aya', ['start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addDays(5)->toDateString()]);

        $html = Livewire::actingAs($this->hr())->test(ContractsTable::class)->html();

        $this->assertStringContainsString('data-card=', $html);
        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:block', $html);
        $this->assertStringContainsString('Ends in 5 days', $html);
        $this->assertStringNotContainsString((string) self::SALARY, $html);
        $this->assertStringNotContainsString('8,731', $html);
    }

    public function test_no_amount_in_the_rendered_html_or_payload_under_any_column_choice(): void
    {
        $this->contract('Aya');

        $table = Livewire::actingAs($this->hr())->test(ContractsTable::class)->call('selectAllColumns', true);

        $this->assertStringNotContainsString((string) self::SALARY, $table->html());
        $this->assertStringNotContainsString((string) self::SALARY, json_encode($table->snapshot));
    }

    // ---------------------------------------------------------------- export

    public function test_export_follows_the_columns_shown_and_has_no_pay_column(): void
    {
        $this->contract('Aya');
        Excel::fake();

        $table = Livewire::actingAs($this->hr())->test(ContractsTable::class)->call('exportExcel');
        Excel::assertDownloaded('contracts_'.now()->format('Y-m-d').'.xlsx', function (ContractExport $export) {
            $this->assertSame(['Employee', 'Contract Type', 'Period', 'Status', 'Auto renewal'], array_map(fn ($h) => (string) $h, $export->headings()) ?: []);

            return true;
        });

        // Showing a hidden column puts it in the file too (default-hidden columns used to be dropped).
        Excel::fake();
        $table->call('selectAllColumns', true)->call('exportExcel');
        Excel::assertDownloaded('contracts_'.now()->format('Y-m-d').'.xlsx', function (ContractExport $export) {
            $headings = implode('|', $export->headings());
            $this->assertStringContainsString('Weekly Hours', $headings);
            $this->assertStringNotContainsStringIgnoringCase('salary|', $headings.'|');
            $this->assertStringNotContainsString('Actions', $headings);

            return true;
        });
    }

    public function test_export_button_says_it_exports_the_columns_shown(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);

        $this->actingAs($this->hr())->get(route('contracts.index'))->assertOk()
            ->assertSee('Exports the columns shown');
        $this->assertSame('يصدّر الأعمدة المعروضة', __('contracts.export-columns-shown', [], 'ar'));
    }

    private function hr(): User
    {
        return $this->makeUser($this->dms, 'HR-DMS', self::HR);
    }

    private function contract(string $first, array $overrides = [], ?City $city = null): Contract
    {
        return $this->makeContractFor(
            $this->makeEmployee($city ?? $this->dms, 'emp-'.uniqid(), ['first_name_en' => $first, 'last_name_en' => 'Zed']),
            array_merge(['salary' => self::SALARY], $overrides),
        );
    }

    /** A manager whose reports are the contracts built on $position. */
    private function manager(): array
    {
        $employee = $this->makeEmployee($this->dms, 'mgr-'.uniqid(), ['first_name_en' => 'Boss']);
        $managed = $this->makePosition($this->dms);
        $this->makeContractFor($employee, [
            'position_id' => $managed->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);

        return [$this->makeUser($this->dms, 'manager', ['contracts.view'], $employee), $managed];
    }
}
