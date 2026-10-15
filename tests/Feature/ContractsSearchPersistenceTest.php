<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractsTable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class ContractsSearchPersistenceTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_the_contract_details_back_link_restores_the_previous_search(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-DMS', ['contracts.view']);
        $contract = $this->makeContractFor($user->profile);

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph')
            ->set('search.employee', 'Joseph')
            ->call('gotoPage', 5);

        $details = $this->get(route('contracts.show', $contract))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($details->getContent());
        $links = (new DOMXPath($document))->query('//a[normalize-space(.)="'.__('general.back').'"]');
        $this->assertSame(1, $links->length);
        $backUrl = $links->item(0)->getAttribute('href');
        $this->assertSame(route('contracts.index'), $backUrl);

        $this->get($backUrl)->assertOk()
            ->assertSeeLivewire(ContractsTable::class)
            ->assertSee('"globalSearch":"Joseph"')
            ->assertSee('"employee":"Joseph"')
            ->assertSee('"page":5');
    }

    public function test_page_and_page_size_are_restored_after_navigation(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('perPage', 25)
            ->call('gotoPage', 5);

        Livewire::test(ContractsTable::class)
            ->assertSet('paginators.page', 5)
            ->assertSet('perPage', 25);
    }

    public function test_changing_search_resets_the_saved_page(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->call('gotoPage', 5)
            ->set('globalSearch', 'Joseph');

        Livewire::test(ContractsTable::class)
            ->assertSet('globalSearch', 'Joseph')
            ->assertSet('paginators.page', 1);
    }

    public function test_url_page_takes_precedence_over_saved_page(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->call('gotoPage', 5);

        Livewire::withQueryParams(['page' => 2])->test(ContractsTable::class)
            ->assertSet('paginators.page', 2);
    }

    public function test_searches_are_restored_when_returning_to_contracts(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph')
            ->set('search.employee', 'Joseph');

        Livewire::test(ContractsTable::class)
            ->assertSet('globalSearch', 'Joseph')
            ->assertSet('search.employee', 'Joseph');
    }

    public function test_clearing_search_also_clears_the_saved_search(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph')
            ->set('search.employee', 'Joseph')
            ->call('clearSearch');

        Livewire::test(ContractsTable::class)
            ->assertSet('globalSearch', '')
            ->assertSet('search', []);
    }

    public function test_saved_search_is_not_shared_with_another_user(): void
    {
        $city = $this->makeCity('DMS', '003');
        $first = $this->makeUser($city, 'HR-DMS');
        $second = $this->makeUser($city, 'HR-DMS');

        Livewire::actingAs($first)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph');

        Livewire::actingAs($second)->test(ContractsTable::class)
            ->assertSet('globalSearch', '');
    }

    public function test_an_explicit_search_in_the_url_takes_precedence_over_saved_search(): void
    {
        $user = $this->makeUser($this->makeCity('DMS', '003'), 'HR-DMS');

        Livewire::actingAs($user)->test(ContractsTable::class)
            ->set('globalSearch', 'Joseph');

        Livewire::withQueryParams(['globalSearch' => 'Mary'])->test(ContractsTable::class)
            ->assertSet('globalSearch', 'Mary');
    }
}
