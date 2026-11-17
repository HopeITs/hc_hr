<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Livewire\Layout\Sidebar;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Work stays on the dashboard (2026-11-09): any menu page can be dragged onto
 * it as a card, and the dashboard opens pages in its workspace instead of leaving.
 */
class SidebarPinTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    public function test_menu_links_drag_onto_the_dashboard_and_it_has_its_workspace(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own']);

        // The menu id travels with a dragged link; there is no separate "+" button.
        $sidebar = Livewire::actingAs($staff)->test(Sidebar::class)->html();
        $this->assertStringContainsString('draggable="true"', $sidebar);
        $this->assertStringContainsString("setData('application/x-hc-page'", $sidebar);
        $this->assertStringNotContainsString('data-pin-page', $sidebar);

        $home = Livewire::actingAs($staff)->test(Index::class)->html();
        $this->assertStringContainsString('data-testid="dashboard-workspace"', $home);
        $this->assertStringContainsString('x-data="hcWorkspace"', $home);
    }

    public function test_a_menu_page_is_a_card_the_dashboard_can_take_only_when_the_role_has_it(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $layout = new DashboardLayout;

        $this->assertTrue($layout->allowed($staff, 'app.nav.my-space.leaves'));
        $this->assertTrue($layout->hasCard('app.nav.my-space.leaves'));
        // A dropped page the role may not open is not among its cards (home-screen.js pinPage finds no item).
        $this->assertFalse($layout->allowed($staff, 'app.nav.hr.contracts'));
    }
}
