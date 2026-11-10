<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** Ready-made layouts: a shortcut to an arrangement, never to a tile the role may not have. */
class DashboardPresetsTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    public function test_a_role_is_offered_only_the_presets_it_can_fill(): void
    {
        $city = $this->makeCity('ALP', '001');
        $layout = app(DashboardLayout::class);

        $this->assertArrayNotHasKey('numbers', $layout->presets($this->makeUser($city, 'employee', ['leaves.view-own'])));
        $this->assertArrayHasKey('numbers', $layout->presets($this->makeUser($city, 'HR-ALP', ['statistics.view', 'employees.view'])));
    }

    public function test_applying_a_preset_draws_its_tiles_and_marks_it_chosen(): void
    {
        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);

        $page = Livewire::actingAs($user)->test(Index::class)
            ->assertSeeHtml('data-preset="personal"')
            ->call('applyPreset', 'team');

        $html = $page->html();
        $this->assertStringContainsString('data-key="upcoming-leaves"', $html);
        $this->assertStringNotContainsString('data-key="news"', $html);
        $this->assertMatchesRegularExpression('/data-preset="team"[^>]*aria-pressed="true"/s', $html);
        $this->assertSame('upcoming-leaves', collect(SavedDashboardLayout::query()->sole()->layout)->firstWhere('hidden', false)['key']);
    }

    public function test_a_preset_the_role_is_not_offered_changes_nothing(): void
    {
        $user = $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(Index::class)->call('applyPreset', 'numbers')->call('applyPreset', 'nonsense');

        $this->assertSame(0, SavedDashboardLayout::query()->count());
    }
}
