<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractDetails;
use App\Livewire\Contract\ContractsTable;
use App\Models\City;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A6: the contract PDF export is offered only to a role the server will
 * actually serve it to, and retrying a failed export only reads the contract.
 */
class ContractExportUiTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->city = $this->makeCity('ALP', '001');
        $this->contract = $this->makeContractFor(
            $this->makeEmployee($this->city, 'export-subject'),
            ['salary' => 98765],
        );
    }

    public function test_hr_with_the_export_permission_is_offered_the_export_on_the_details_page(): void
    {
        $this->detailsAs($this->hr(['contracts.view', 'contracts.export']))
            ->assertSee($this->exportTarget(), false);
    }

    public function test_hr_without_the_export_permission_is_not_offered_the_export(): void
    {
        $this->detailsAs($this->hr(['contracts.view']))
            ->assertDontSee($this->exportTarget(), false);
    }

    public function test_a_role_that_cannot_see_pay_is_not_offered_the_export_nor_shown_the_figure(): void
    {
        // super-admin holds every permission, contracts.export included, and
        // is still refused pay figures (config('salary.blind_roles')).
        $user = $this->makeUser($this->city, 'super-admin', ['contracts.view', 'contracts.export']);
        $this->assertFalse($user->canViewSalary());

        // Page 2 is the one carrying the pay clause.
        $html = $this->detailsAs($user)
            ->assertDontSee($this->exportTarget(), false)
            ->call('setPage', 2)
            ->assertSee(__('general.salary-hidden-title'))
            ->html();

        $this->assertStringNotContainsString('98765', $html);
        $this->assertStringNotContainsString('98,765', $html);
    }

    public function test_hr_does_see_the_figure_on_the_same_page(): void
    {
        // The control for the test above: the figure is on this page when allowed.
        $this->detailsAs($this->hr(['contracts.view', 'contracts.export']))
            ->call('setPage', 2)
            ->assertSee('98,765');
    }

    public function test_the_contracts_list_offers_the_export_only_to_salary_viewers(): void
    {
        $url = $this->exportTarget();

        Livewire::actingAs($this->hr(['contracts.view', 'contracts.export']))
            ->test(ContractsTable::class)
            ->assertSee($url, false);

        Livewire::actingAs($this->makeUser($this->city, 'super-admin', ['contracts.view', 'contracts.export']))
            ->test(ContractsTable::class)
            ->assertDontSee($url, false);
    }

    public function test_the_export_url_refuses_a_role_that_cannot_see_pay(): void
    {
        $this->actingAs($this->makeUser($this->city, 'super-admin', ['contracts.view', 'contracts.export']))
            ->get(route('contracts.export', $this->contract), ['Accept' => 'application/pdf'])
            ->assertForbidden();
    }

    public function test_retrying_a_failed_export_reads_the_same_contract_and_creates_nothing(): void
    {
        $renderer = $this->mock(ContractPdfRenderer::class);
        $renderer->shouldReceive('render')->once()->andThrow(new RuntimeException('chrome died'));
        $renderer->shouldReceive('render')->once()->andReturn('%PDF-1.7 synthetic');
        $renderer->shouldReceive('fileName')->andReturn('contract.pdf');

        $this->actingAs($this->hr(['contracts.view', 'contracts.export']));

        $contracts = Contract::query()->count();
        $before = $this->contract->fresh()->updated_at;
        $url = route('contracts.export', $this->contract);

        $first = $this->get($url, ['Accept' => 'application/pdf']);
        $this->assertFalse($first->isSuccessful(), 'The failed render must not look like a download.');

        // Retry: the very same GET.
        $this->get($url, ['Accept' => 'application/pdf'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame($contracts, Contract::query()->count());
        $this->assertEquals($before, $this->contract->fresh()->updated_at);
    }

    public function test_every_export_message_exists_in_english_and_arabic(): void
    {
        foreach (['export-loading', 'export-failed', 'export-forbidden', 'export-error', 'export-retry', 'export-retry-label'] as $key) {
            $en = trans('contracts.'.$key, [], 'en');
            $ar = trans('contracts.'.$key, [], 'ar');

            $this->assertNotSame('contracts.'.$key, $en, "Missing EN contracts.{$key}");
            $this->assertNotSame('contracts.'.$key, $ar, "Missing AR contracts.{$key}");
            $this->assertNotSame($en, $ar, "contracts.{$key} is not translated");
        }
    }

    /**
     * The export URL as the page hands it to the fetch() that downloads it
     * (a JS string literal, slashes escaped).
     */
    private function exportTarget(): string
    {
        return str_replace('/', '\/', route('contracts.export', $this->contract));
    }

    private function hr(array $permissions): User
    {
        return $this->makeUser($this->city, 'HR-ALP', $permissions);
    }

    private function detailsAs(User $user)
    {
        return Livewire::actingAs($user)->test(ContractDetails::class, ['contract' => $this->contract]);
    }
}
