<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Hub;
use App\Support\Dashboard\HubGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** The dashboard's groups stand in for the long menu, without widening anything. */
class DashboardHubTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    public function test_an_employee_only_gets_groups_with_pages_it_may_open(): void
    {
        $employee = $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);

        $groups = collect(HubGroups::for($employee))->keyBy('id');

        $this->assertTrue($groups->has('leaves'));
        $this->assertFalse($groups->has('settings'));
        $this->assertFalse($groups->has('reports'));
        $this->assertNotContains(route('employees.index'), $groups->pluck('pages')->flatten(1)->pluck('href')->all());

        foreach ($groups->pluck('pages')->flatten(1) as $page) {
            $this->assertNotContains($this->actingAs($employee)->get($page['href'])->getStatusCode(), [403, 404, 500], $page['href']);
        }
    }

    public function test_a_card_opens_with_a_group_the_person_has_and_nothing_else(): void
    {
        $employee = $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);

        $hub = Livewire::actingAs($employee)->test(Hub::class)
            ->assertSeeHtml('data-hub="leaves"')
            ->assertDontSeeHtml('data-hub="settings"')
            ->call('load', 'settings');

        $this->assertArrayNotHasKey('settings', $hub->get('figures'));

        $hub->call('load', 'leaves');
        $this->assertArrayHasKey('leaves', $hub->get('figures'));
        $this->assertNotNull($hub->get('figures')['leaves']);
    }

    public function test_contract_figures_need_the_contracts_permission(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.view', 'employees.view']);

        $figures = Livewire::actingAs($hr)->test(Hub::class)->call('load', 'people')->get('figures')['people'];

        $this->assertSame(['running-contracts', 'ending-soon', 'not-renewed'], array_column($figures, 'label'));
    }
}
