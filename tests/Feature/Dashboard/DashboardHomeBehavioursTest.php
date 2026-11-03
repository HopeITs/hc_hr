<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Header;
use App\Livewire\Dashboard\Index;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The desk's panel behaviours: bounds, the page a panel opens in full, sizes
 * kept as set, the late strip and the decisions as panels, a layout stored
 * before the desk cleaned of shortcuts and pins, and a light phone screen. The browser side is
 * home-screen.js (and tests/js/dashboard-layout.test.mjs for the packing);
 * here: what the server decides and draws, and that it keeps the scope.
 */
class DashboardHomeBehavioursTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    public function test_a_panel_is_sized_between_its_own_minimum_and_maximum(): void
    {
        $layout = new DashboardLayout;

        $this->assertSame(['min' => [3, 3], 'max' => [12, 8]], $layout->bounds('calendar'));
        $this->assertSame('3x3', $layout->size('calendar', '1x1'));
        $this->assertSame('12x8', $layout->size('calendar', '20x20'));
        $this->assertTrue($layout->canResize('calendar'));
    }

    public function test_a_widget_opens_only_a_page_the_role_may_open(): void
    {
        $city = $this->makeCity('ALP', '001');
        $layout = new DashboardLayout;
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.view', 'statistics.view', 'leaves.view-own']);

        $this->assertSame(route('leaves.index'), $layout->page($staff, 'my-leaves'));
        $this->assertNull($layout->page($staff, 'soon-ending-contracts'));   // not allowed at all
        $this->assertNull($layout->page($staff, 'notes'));                   // no page
        $this->assertNull($layout->page($staff, 'app.nav.my-space.leaves')); // not a panel

        $this->assertSame(route('contracts.index'), $layout->page($hr, 'soon-ending-contracts'));
        $this->assertSame(route('statistics.general'), $layout->page($hr, 'stats-department'));
    }

    public function test_a_size_set_by_hand_is_kept_as_set(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $layout = (new DashboardLayout)->defaults($user);

        foreach ($layout as &$item) {
            if ($item['key'] === 'calendar') {
                $item = ['size' => '3x3', 'fixed' => true] + $item;
            }
        }
        unset($item);

        Livewire::actingAs($user)->test(Index::class)->call('saveLayout', $layout)->assertHasNoErrors();

        $saved = collect(SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout)->keyBy('key');
        $this->assertSame(['3x3', true], [$saved['calendar']['size'], $saved['calendar']['fixed']]);
        $this->assertArrayNotHasKey('fixed', $saved['notes']);
    }

    public function test_the_late_and_decision_tiles_are_only_for_someone_something_can_wait_on(): void
    {
        $city = $this->makeCity('ALP', '001');
        $layout = new DashboardLayout;
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $decider = $this->makeUser($city, 'manager', ['leaves.decide']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.view']);

        $this->assertFalse($layout->allowed($staff, 'late'));
        $this->assertFalse($layout->allowed($staff, 'decide'));
        $this->assertNotContains('late', array_column($layout->defaults($staff), 'key'));

        $this->assertTrue($layout->allowed($decider, 'decide'));
        $this->assertTrue($layout->allowed($decider, 'late'));
        // They were above the grid: first, as before.
        $this->assertSame(['late', 'decide'], array_slice(array_column($layout->defaults($decider), 'key'), 0, 2));

        // Ended contracts are late for HR even without a decision of their own.
        $this->assertTrue($layout->allowed($hr, 'late'));
    }

    public function test_a_layout_saved_before_they_were_tiles_gets_them_first(): void
    {
        $city = $this->makeCity('ALP', '001');
        $decider = $this->makeUser($city, 'manager', ['leaves.decide']);
        $old = [
            ['key' => 'notes', 'size' => '5x3', 'hidden' => false],
            ['key' => 'team', 'size' => '3x4', 'hidden' => false],
        ];

        $keys = array_column((new DashboardLayout)->clean($decider, $old), 'key');

        // The alerts lead, then what was saved, in its order.
        $this->assertSame(['late', 'decide', 'notes', 'team'], array_slice($keys, 0, 4));
    }

    public function test_a_layout_saved_before_the_desk_loses_its_shortcuts_and_pins(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $old = [
            ['key' => 'notes', 'size' => '5x3', 'pinned' => false, 'hidden' => false],
            ['key' => 'app.nav.my-space.leaves', 'size' => '1x1', 'pinned' => true, 'hidden' => false],
            ['key' => 'team', 'size' => '3x4', 'pinned' => true, 'hidden' => false],
        ];

        $clean = (new DashboardLayout)->clean($user, $old);

        // Shortcut tiles are gone, a pin no longer moves anything, and no item keeps the flag.
        $this->assertSame(['notes', 'team'], array_slice(array_column($clean, 'key'), 0, 2));
        $this->assertSame([], array_filter(array_column($clean, 'key'), fn (string $key): bool => str_starts_with($key, DashboardLayout::APP_PREFIX)));
        foreach ($clean as $item) {
            $this->assertArrayNotHasKey('pinned', $item, $item['key']);
        }

        // The page draws such a layout without them.
        SavedDashboardLayout::query()->create(['user_id' => $user->id, 'role' => 'employee', 'layout' => $old]);
        $html = Livewire::actingAs($user)->test(Index::class)->html();
        $this->assertStringNotContainsString('data-key="app.', $html);
    }

    public function test_the_hello_has_no_alerts_and_the_grid_draws_them_as_panels(): void
    {
        $city = $this->makeCity('ALP', '001');
        $decider = $this->makeUser($city, 'manager', ['leaves.decide']);

        Livewire::actingAs($decider)->test(Header::class)
            ->assertSeeHtml('data-testid="dashboard-headline"')
            ->assertDontSeeHtml('data-testid="late-band"')
            ->assertDontSeeHtml('data-testid="decision-card"');

        $html = Livewire::actingAs($decider)->test(Index::class)->html();
        $this->assertStringContainsString('data-key="late"', $html);
        $this->assertStringContainsString('data-key="decide"', $html);
    }

    public function test_panels_carry_their_tools_and_a_phone_stays_light(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own', 'checklists.view-own']);

        $html = Livewire::actingAs($staff)->test(Index::class)->html();

        // Every panel has its tool capsule and options menu; none of the removed parts remain.
        $this->assertStringContainsString('class="hc-ttools"', $html);
        $this->assertStringContainsString(e(__('dashboard.home.options', ['tile' => __('dashboard.tiles.notes')])), $html);
        $this->assertStringContainsString(e(__('dashboard.home.remove')), $html);
        $this->assertStringNotContainsString('class="hc-wmini"', $html);
        $this->assertStringNotContainsString('popover="manual"', $html);
        $this->assertStringNotContainsString('data-page=', $html);

        // Open in full only where the role's menu has the page: the calendar has one, notes none.
        $this->assertStringContainsString('href="'.route('team.calendar').'" wire:navigate class="hc-tbtn"', $this->tileHtml($html, 'calendar'));
        $this->assertStringNotContainsString(e(__('dashboard.home.open-full')), $this->tileHtml($html, 'notes'));

        // The phone does not draw the slideshow or the charts (the saved layout keeps it).
        $config = $this->homeConfig($html);
        $this->assertSame(DashboardLayout::PHONE_HIDDEN, $config['phoneHidden']);
        $this->assertContains('today-events', DashboardLayout::PHONE_HIDDEN);
        $this->assertContains('stats-salary', DashboardLayout::PHONE_HIDDEN);
        $this->assertNotEmpty($config['text']['removed']);
    }

    public function test_every_new_string_is_in_both_languages(): void
    {
        foreach (['tiles.late', 'tiles.decide', 'late-tile.none', 'decide-tile.none', 'home.open-full', 'home.open-full-tile',
            'home.options', 'home.move-top', 'home.move-earlier', 'home.move-later', 'home.remove', 'home.removed',
            'home.add-panel', 'header.clear', 'header.figures-link', 'desk.open'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }

        app()->setLocale('ar');
        $forms = array_map(fn (int $n) => trans_choice('dashboard.home.more-available', $n, ['count' => $n]), [0, 1, 2, 3, 11, 100]);
        $this->assertCount(6, array_unique($forms));
        $this->assertSame('لوحتان أخريان متاحتان', $forms[2]);
    }

    /** One panel's own markup, up to its card. */
    private function tileHtml(string $html, string $key): string
    {
        $tile = substr($html, (int) strpos($html, 'id="hc-tile-'.$key.'"'));

        return substr($tile, 0, (int) strpos($tile, 'hc-tile-body'));
    }

    /** The home screen's data-home attribute. */
    private function homeConfig(string $html): array
    {
        preg_match('/data-home="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
    }
}
