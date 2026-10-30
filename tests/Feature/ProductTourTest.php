<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeTable;
use App\Livewire\Position\OrgChart;
use App\Livewire\Statistics\ContractStatistics;
use App\Livewire\Statistics\GeneralStatistics;
use App\Models\User;
use App\Support\Navigation;
use App\Support\NavigationMemory;
use App\Support\ProductTour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ProductTourTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_every_configured_step_has_copy_in_both_languages(): void
    {
        foreach (config('tours') as $id => $tour) {
            foreach (array_keys($tour['steps']) as $key) {
                foreach (['en', 'ar'] as $locale) {
                    foreach (['title', 'body'] as $field) {
                        $this->assertTrue(
                            Lang::hasForLocale("tour.{$id}.{$key}.{$field}", $locale),
                            "Missing tour.{$id}.{$key}.{$field} for [{$locale}]",
                        );
                    }
                }
            }
        }
    }

    public function test_every_configured_route_exists(): void
    {
        foreach (config('tours') as $tour) {
            foreach ($tour['routes'] as $route) {
                $this->assertTrue(Route::has($route), "Tour route [{$route}] is not defined");
            }
        }
    }

    public function test_it_resolves_the_steps_for_the_current_route(): void
    {
        $request = Request::create('/');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('leaves.index'));

        $tour = ProductTour::forRequest($request);

        $this->assertSame('leaves', $tour['id']);
        $this->assertSame('leaves.balances', $tour['steps'][0]['target']);
        $this->assertSame(__('tour.leaves.balances.title'), $tour['steps'][0]['title']);
    }

    public function test_only_the_dashboard_tour_opens_by_itself(): void
    {
        $resolve = function (string $route) {
            $request = Request::create('/');
            $request->setRouteResolver(fn () => Route::getRoutes()->getByName($route));

            return ProductTour::forRequest($request);
        };

        $this->assertTrue($resolve('dashboard')['autostart']);
        $this->assertFalse($resolve('leaves.index')['autostart']);
    }

    public function test_pages_without_a_tour_resolve_to_null(): void
    {
        $request = Request::create('/');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('settings.password'));

        $this->assertNull(ProductTour::forRequest($request));
    }

    public function test_the_org_chart_renders_its_tour_targets(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(OrgChart::class)
            ->assertSeeHtml('data-tour="org.stats"')
            ->assertSeeHtml('data-tour="org.search"')
            ->assertSeeHtml('data-tour="org.filters"')
            ->assertSeeHtml('data-tour="org.canvas"')
            ->assertSeeHtml('data-tour="org.export"');
    }

    public function test_the_statistics_pages_render_their_tour_targets(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GeneralStatistics::class)
            ->assertSeeHtml('data-tour="stats.departments"')
            ->assertSeeHtml('data-tour="stats.personnel"')
            ->assertSeeHtml('data-tour="stats.contract-types"');

        Livewire::test(ContractStatistics::class)
            ->assertSeeHtml('data-tour="contract-stats.filters"')
            ->assertSeeHtml('data-tour="contract-stats.reset"');
    }

    public function test_tables_expose_the_shared_tour_targets(): void
    {
        $this->actingAs(User::factory()->create());

        // Every LivewireTable renders the published vendor views, so one table
        // stands in for all of them.
        Livewire::test(EmployeeTable::class)
            ->assertSeeHtml('data-tour="table.search"')
            ->assertSeeHtml('data-tour="table.column-search"')
            ->assertSeeHtml('data-tour="table.columns"')
            ->assertSeeHtml('data-tour="table.per-page"');
    }

    public function test_the_contract_composer_sections_are_tour_targets(): void
    {
        $html = Blade::render(
            '<div x-data><x-contract.section name="pay" index="3" title="Pay" summary="x">body</x-contract.section></div>',
        );

        $this->assertStringContainsString('data-tour="contract.pay"', $html);
    }

    public function test_the_dashboard_renders_the_tour_and_its_targets(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('productTour(', false)
            ->assertSee(__('tour.dashboard.welcome.title'), false)
            ->assertSee('data-tour="tour.button"', false)
            ->assertSee('data-tour="dashboard.notes"', false)
            ->assertSee('data-tour="nav"', false)
            ->assertSee('data-tour="nav.dashboard"', false);
    }

    /** Prompt 11: the dashboard tour covers customising, Ctrl+K and favourites. */
    public function test_the_dashboard_tour_points_at_customise_search_and_favourites(): void
    {
        $steps = array_column(config('tours.dashboard.steps'), 'target');
        $this->assertContains('dashboard.customise', $steps);
        $this->assertContains('header.search', $steps);
        $this->assertContains('nav.favourites', $steps);

        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);
        $this->actingAs($user);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-tour="dashboard.customise"', $html);
        $this->assertStringContainsString('data-tour="header.search"', $html);
        // Nothing starred yet: no favourites section, so its step is skipped.
        $this->assertStringNotContainsString('data-tour="nav.favourites"', $html);

        NavigationMemory::toggleFavourite($user, 'nav.my-space.leaves', Navigation::shortcuts($user));
        $this->assertStringContainsString('data-tour="nav.favourites"', $this->get(route('dashboard'))->getContent());
    }
}
