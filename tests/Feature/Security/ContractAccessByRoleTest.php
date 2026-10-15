<?php

namespace Tests\Feature\Security;

use App\Livewire\Contract\ContractsTable;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Who may reach each of the four contract pages, and what each one shows them.
 *
 * Visibility comes from one place, Contract::scopeVisibleTo(), which both the
 * list query and ContractPolicy go through - so for every role the index, a
 * direct /contracts/{id} and a direct /contracts/{id}/edit must agree.
 *
 * Permissions given to each role below mirror the live role table.
 */
class ContractAccessByRoleTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const HR_PERMISSIONS = [
        'contracts.view', 'contracts.create', 'contracts.update', 'contracts.delete', 'contracts.export',
    ];

    private const GM_PERMISSIONS = [
        'contracts.view', 'contracts.create', 'contracts.update', 'contracts.export',
    ];

    private City $alp;

    private City $lat;

    /** An Aleppo employee nobody in the manager fixture manages. */
    private Contract $alpContract;

    /** A Latakia employee: out of HR-ALP's city. */
    private Contract $latContract;

    /** Reports to the manager on the direct line only. */
    private Contract $directReport;

    /** Reports to the manager on the technical line only. */
    private Contract $techReport;

    /** Reports to the manager on both lines. */
    private Contract $bothLinesReport;

    private Employee $managerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Routes register without a locale prefix under test, so these would
        // bounce every request to /{locale}/... before it reaches the page.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->alp = $this->makeCity('ALP', '001');
        $this->lat = $this->makeCity('LAT', '002');

        $this->alpContract = $this->makeContractFor($this->makeNamedEmployee($this->alp, 'Aleppoworker'));
        $this->latContract = $this->makeContractFor($this->makeNamedEmployee($this->lat, 'Latakiaworker'));

        // The manager's own position, held through a contract running today.
        $this->managerEmployee = $this->makeNamedEmployee($this->alp, 'Managerperson');
        $managerPosition = $this->makePosition($this->alp, ['title_en' => 'Center Coordinator']);
        $this->makeContractFor($this->managerEmployee, [
            'position_id' => $managerPosition->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $this->directReport = $this->makeContractFor($this->makeNamedEmployee($this->alp, 'Directreport'), [
            'position_id' => $this->makePosition($this->alp, ['direct_manager_id' => $managerPosition->id])->id,
        ]);
        $this->techReport = $this->makeContractFor($this->makeNamedEmployee($this->lat, 'Techreport'), [
            'position_id' => $this->makePosition($this->lat, ['tech_manager_id' => $managerPosition->id])->id,
        ]);
        $this->bothLinesReport = $this->makeContractFor($this->makeNamedEmployee($this->alp, 'Bothreport'), [
            'position_id' => $this->makePosition($this->alp, [
                'direct_manager_id' => $managerPosition->id,
                'tech_manager_id' => $managerPosition->id,
            ])->id,
        ]);
    }

    // ────────────────────────────────────────────────
    // Manager
    // ────────────────────────────────────────────────

    public function test_manager_lists_only_direct_and_technical_reports(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->get(route('contracts.index'))->assertOk();

        Livewire::actingAs($manager)
            ->test(ContractsTable::class)
            ->assertSee(['Directreport', 'Techreport', 'Bothreport'])
            ->assertDontSee(['Aleppoworker', 'Latakiaworker'])
            // No edit button, and clicking the row opens the contract itself.
            ->assertDontSee(route('contracts.edit', $this->directReport))
            ->assertSeeHtml("window.location.href = '".route('contracts.show', $this->directReport)."'");
    }

    public function test_manager_opens_a_report_contract_read_only(): void
    {
        $this->actingAs($this->manager());

        foreach ([$this->directReport, $this->techReport, $this->bothLinesReport] as $contract) {
            $this->get(route('contracts.show', $contract))
                ->assertOk()
                ->assertDontSee(route('contracts.edit', $contract))
                ->assertDontSee('1,500');
        }
    }

    public function test_manager_cannot_reach_contracts_outside_their_reports_by_url(): void
    {
        $this->actingAs($this->manager());

        $this->get(route('contracts.show', $this->alpContract))->assertForbidden();
        $this->get(route('contracts.show', $this->latContract))->assertForbidden();
    }

    public function test_manager_can_neither_create_nor_edit(): void
    {
        $this->actingAs($this->manager());

        $this->get(route('contracts.create'))->assertForbidden();
        $this->get(route('contracts.edit', $this->directReport))->assertForbidden();
        $this->get(route('contracts.edit', $this->alpContract))->assertForbidden();
    }

    /**
     * "Their" positions are the ones on their contracts running today: a
     * coordinator whose contract has ended stops seeing the old team.
     */
    public function test_a_manager_whose_own_contract_has_ended_sees_no_reports(): void
    {
        $manager = $this->manager();
        Contract::query()->where('employee_id', $this->managerEmployee->id)->update([
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($manager)->get(route('contracts.show', $this->directReport))->assertForbidden();

        Livewire::actingAs($manager)
            ->test(ContractsTable::class)
            ->assertDontSee(['Directreport', 'Techreport', 'Bothreport']);
    }

    // ────────────────────────────────────────────────
    // Regional HR
    // ────────────────────────────────────────────────

    public function test_regional_hr_lists_only_its_own_city(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);

        $this->actingAs($hr)->get(route('contracts.index'))->assertOk();

        Livewire::actingAs($hr)
            ->test(ContractsTable::class)
            ->assertSee(['Aleppoworker', 'Directreport', 'Bothreport'])
            ->assertDontSee(['Latakiaworker', 'Techreport'])
            ->assertSee(route('contracts.edit', $this->alpContract));
    }

    public function test_regional_hr_views_creates_and_edits_in_its_own_city(): void
    {
        $this->actingAs($this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS));

        $this->get(route('contracts.show', $this->alpContract))
            ->assertOk()
            ->assertSee(route('contracts.edit', $this->alpContract))
            ->assertSee('1,500');
        $this->get(route('contracts.edit', $this->alpContract))->assertOk();
        $this->get(route('contracts.create'))->assertOk();
    }

    public function test_regional_hr_is_refused_another_city_by_url(): void
    {
        $this->actingAs($this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS));

        $this->get(route('contracts.show', $this->latContract))->assertForbidden();
        $this->get(route('contracts.edit', $this->latContract))->assertForbidden();
    }

    // ────────────────────────────────────────────────
    // HR-SYR
    // ────────────────────────────────────────────────

    public function test_hr_syr_sees_and_edits_every_city(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-SYR');

        Livewire::actingAs($hr)
            ->test(ContractsTable::class)
            ->assertSee(['Aleppoworker', 'Latakiaworker', 'Techreport']);

        $this->actingAs($hr);

        $this->get(route('contracts.index'))->assertOk();
        $this->get(route('contracts.create'))->assertOk();
        $this->get(route('contracts.show', $this->latContract))
            ->assertOk()
            ->assertSee(route('contracts.edit', $this->latContract))
            ->assertSee('1,500');
        $this->get(route('contracts.edit', $this->latContract))->assertOk();
    }

    // ────────────────────────────────────────────────
    // General manager
    // ────────────────────────────────────────────────

    public function test_general_manager_sees_and_edits_every_city_without_pay_figures(): void
    {
        $gm = $this->makeUser($this->alp, 'general-manager', self::GM_PERMISSIONS);

        Livewire::actingAs($gm)
            ->test(ContractsTable::class)
            ->assertSee(['Aleppoworker', 'Latakiaworker'])
            ->assertSee(route('contracts.edit', $this->latContract));

        $this->actingAs($gm);

        $this->get(route('contracts.index'))->assertOk();
        $this->get(route('contracts.show', $this->latContract))
            ->assertOk()
            ->assertSee(route('contracts.edit', $this->latContract))
            ->assertDontSee('1,500')
            ->assertSee(__('general.salary-hidden-title', [], 'en'));
        $this->get(route('contracts.edit', $this->latContract))->assertOk();
    }

    // ────────────────────────────────────────────────
    // Super-admin
    // ────────────────────────────────────────────────

    public function test_super_admin_sees_every_city_but_never_a_pay_figure(): void
    {
        // A figure distinctive enough not to turn up anywhere else on a page.
        $this->alpContract->update(['salary' => 864213]);
        $admin = $this->makeUser($this->alp, 'super-admin');

        Livewire::actingAs($admin)
            ->test(ContractsTable::class)
            ->assertSee(['Aleppoworker', 'Latakiaworker'])
            ->assertDontSee(['864213', '864,213']);

        $this->actingAs($admin);

        $this->get(route('contracts.index'))->assertOk();
        $this->get(route('contracts.show', $this->alpContract))
            ->assertOk()
            ->assertDontSee(['864213', '864,213'])
            ->assertSee(__('general.salary-hidden-title', [], 'en'));
        $this->get(route('contracts.edit', $this->alpContract))
            ->assertOk()
            ->assertDontSee(['864213', '864,213']);
        $this->get(route('contracts.create'))->assertOk();
    }

    public function test_super_admin_does_not_see_flexible_pay_tier_amounts_on_the_contract(): void
    {
        $this->alpContract->update(['pay_mode' => 2, 'salary' => null]);
        $this->alpContract->payTiers()->create([
            'condition_en' => 'Per workshop',
            'condition_ar' => 'لكل ورشة',
            'amount' => 7777,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->makeUser($this->alp, 'super-admin'))
            ->get(route('contracts.show', $this->alpContract))
            ->assertOk()
            ->assertDontSee('7,777');
    }

    // ────────────────────────────────────────────────
    // Employee
    // ────────────────────────────────────────────────

    public function test_employee_reaches_none_of_the_pages(): void
    {
        $this->actingAs($this->makeUser($this->alp, 'employee'));

        $this->get(route('contracts.index'))->assertForbidden();
        $this->get(route('contracts.create'))->assertForbidden();
        $this->get(route('contracts.show', $this->alpContract))->assertForbidden();
        $this->get(route('contracts.edit', $this->alpContract))->assertForbidden();
    }

    // ────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────

    private function manager(): User
    {
        return $this->makeUser($this->alp, 'manager', ['contracts.view'], $this->managerEmployee);
    }

    private function makeNamedEmployee(City $city, string $firstName): Employee
    {
        // Both columns: the suite renders in whichever locale is current.
        return $this->makeEmployee($city, strtolower($firstName), [
            'first_name_en' => $firstName,
            'first_name_ar' => $firstName,
        ]);
    }
}
