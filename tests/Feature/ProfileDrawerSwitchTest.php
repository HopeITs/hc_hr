<?php

namespace Tests\Feature;

use App\Livewire\Position\ProfileDrawer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Clicking one org chart card and then another must show the second employee,
 * not keep the first one on screen.
 */
class ProfileDrawerSwitchTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_opening_a_second_employee_replaces_the_first(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $fredy = $this->makeEmployee($aleppo, 'fredy', ['first_name_en' => 'Fredy']);
        $other = $this->makeEmployee($aleppo, 'other', ['first_name_en' => 'Nadia']);

        Livewire::actingAs($hr)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $fredy->id)
            ->assertSet('employeeId', $fredy->id)
            ->assertSee('Fredy')
            ->call('openProfile', $other->id)
            ->assertSet('employeeId', $other->id)
            ->assertSee('Nadia')
            ->assertDontSee('Fredy');
    }

    public function test_the_drawer_loads_the_employee_itself_and_hides_stale_content(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);

        // The drawer must request the clicked employee from its own Alpine
        // handler and gate the rendered profile on the server catching up.
        Livewire::actingAs($hr)
            ->test(ProfileDrawer::class)
            ->assertSeeHtml('x-on:open-profile.window="open($event.detail.employeeId)"')
            ->assertSeeHtml('this.$wire.openProfile(this.requestedId)')
            ->assertSeeHtml('x-show="! ready"');
    }
}
