<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Models\City;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Pin on every dashboard card (2026-11-04): a pinned card keeps the size and
 * the cell it was pinned at ("at": [column, row]); the packer
 * (layout-math.js, tests/js) puts it there and flows the others around it.
 */
class PinnedTileTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_a_pinned_card_keeps_its_cell_and_size_when_saved(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(Index::class)->call('saveLayout', [
            ['key' => 'news', 'size' => '4x3', 'pinned' => true, 'hidden' => false, 'fixed' => true, 'at' => [4, 1]],
            ['key' => 'calendar', 'size' => '5x3', 'pinned' => false, 'hidden' => false, 'at' => [0, 0]],
        ])->assertOk();

        $saved = collect($this->saved($user))->keyBy('key');
        $this->assertSame([4, 1], $saved['news']['at']);
        $this->assertTrue($saved['news']['fixed']);
        $this->assertSame('4x3', $saved['news']['size']);
        // An unpinned card keeps a cell too: resized from a top or start corner, it holds its place.
        $this->assertSame([0, 0], $saved['calendar']['at']);
    }

    public function test_a_cell_that_is_not_a_cell_is_dropped_or_refused(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $layout = new DashboardLayout;

        $clean = collect($layout->clean($user, [
            ['key' => 'news', 'size' => '4x3', 'pinned' => true, 'hidden' => false, 'at' => [12, 0]],
            ['key' => 'calendar', 'size' => '5x3', 'pinned' => true, 'hidden' => false, 'at' => ['a' => 1, 'b' => 2]],
            ['key' => 'notes', 'size' => '5x3', 'pinned' => true, 'hidden' => true, 'at' => [0, 0]],
            ['key' => 'team', 'size' => '3x4', 'pinned' => true, 'hidden' => false, 'at' => [1, 2, 3]],
        ]))->keyBy('key');

        foreach (['news', 'calendar', 'notes', 'team'] as $key) {
            $this->assertArrayNotHasKey('at', $clean[$key], $key);
        }

        Livewire::actingAs($user)->test(Index::class)->call('saveLayout', [
            ['key' => 'news', 'size' => '4x3', 'pinned' => true, 'hidden' => false, 'at' => [-1, 'x']],
        ])->assertHasErrors(['items.0.at.0', 'items.0.at.1']);
        $this->assertSame(0, SavedDashboardLayout::query()->where('user_id', $user->id)->count());
    }

    public function test_every_card_has_a_pin_at_rest_and_a_pinned_one_has_no_resize_corner(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $html = Livewire::actingAs($user)->test(Index::class)->html();

        $cards = substr_count($html, 'class="hc-wctl"');
        $this->assertGreaterThan(0, $cards);
        $this->assertSame($cards, substr_count($html, 'class="hc-w-pin"'));
        $this->assertStringContainsString('x-show="! pinned(', $html);

        foreach (['home.pin', 'home.pin-short', 'home.unpin-short', 'home.pinned', 'home.unpinned'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, ['tile' => 'X'], $locale), "{$key} {$locale}");
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function saved(User $user): array
    {
        $layout = SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout;

        return is_string($layout) ? json_decode($layout, true) : $layout;
    }
}
