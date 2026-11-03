<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * "Customize your desk": the ready layouts (DashboardLayout::PRESETS) offered
 * to each person, and what applying one stores.
 */
class DeskPresetsTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    public function test_an_employee_is_offered_only_layouts_made_of_cards_they_may_have(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');

        $presets = (new DashboardLayout)->presets($user);

        // Statistics need statistics.view: an employee is never offered them.
        $this->assertArrayNotHasKey('people-numbers', $presets);
        $this->assertArrayNotHasKey('leadership', $presets);

        // The family's suggestion comes first.
        $this->assertSame('my-day', array_key_first($presets));
        $this->assertTrue($presets['my-day']['suggested']);

        foreach ($presets as $preset) {
            foreach ($preset['tiles'] as $tile) {
                $this->assertTrue((new DashboardLayout)->allowed($user, $tile['key']), $tile['key']);
                $this->assertNotContains($tile['key'], ['stats-salary', 'stats-department', 'soon-ending-contracts']);
            }
        }
    }

    public function test_applying_a_layout_shows_its_cards_and_hides_the_rest(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');
        $this->actingAs($user);

        $tiles = new DashboardLayout;
        $expected = array_column($tiles->presets($user)['community']['tiles'], 'key');

        $page = Livewire::test(Index::class)->call('applyPreset', 'community')->assertHasNoErrors();
        $this->assertArrayHasKey('html', $page->effects, 'applyPreset re-renders');

        $layout = SavedDashboardLayout::query()->sole()->layout;
        $shown = array_values(array_filter($layout, fn (array $i): bool => ! $i['hidden']));

        $this->assertSame($expected, array_column($shown, 'key'));
        $this->assertSame('4x6', $shown[0]['size']);
        $this->assertTrue($shown[0]['fixed']);

        // Every other panel stays in the layout, hidden, so My panels can switch it back on.
        $hidden = array_column(array_filter($layout, fn (array $i): bool => $i['hidden']), 'key');
        $this->assertSame([], array_intersect($expected, $hidden));
        $this->assertContains('notes', $hidden);
        $this->assertSame([], array_filter(array_column($layout, 'key'), fn (string $key): bool => str_starts_with($key, DashboardLayout::APP_PREFIX)));
    }

    public function test_a_layout_the_person_may_not_use_changes_nothing(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('applyPreset', 'people-numbers')
            ->call('applyPreset', 'no-such-layout');

        $this->assertSame(0, SavedDashboardLayout::query()->count());
    }

    public function test_the_dashboard_draws_the_desk_dialog_with_the_suggested_layout(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee(__('dashboard.desk.title'))
            ->assertSee(__('dashboard.presets.my-day.name'))
            ->assertSee('data-desk-default="my-day"', false)
            ->assertDontSee('shortcuts stay')
            ->assertDontSee(__('dashboard.presets.people-numbers.name'));
    }
}
