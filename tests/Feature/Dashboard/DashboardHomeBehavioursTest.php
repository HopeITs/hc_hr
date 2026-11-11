<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\AppCard;
use App\Livewire\Dashboard\AttentionCard;
use App\Livewire\Dashboard\Header;
use App\Livewire\Dashboard\Hub;
use App\Livewire\Dashboard\Index;
use App\Livewire\Dashboard\MeCard;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The home screen behaviours ported from media-request on 2026-10-29: closed
 * tiles, tiles made small (an icon and a side panel), shortcuts made big (a
 * card with data), double-click to a page, sizes kept as set, the late strip
 * and the decisions as tiles, and a light phone screen. The browser side is
 * home-screen.js (and tests/js/dashboard-layout.test.mjs for the packing);
 * here: what the server decides and draws, and that it keeps the scope.
 */
class DashboardHomeBehavioursTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    public function test_a_widget_goes_down_to_an_icon_and_a_shortcut_up_to_a_card(): void
    {
        $layout = new DashboardLayout;

        $this->assertSame(['min' => [1, 1], 'max' => [12, 8], 'card' => [3, 3], 'full' => [5, 3]], $layout->bounds('calendar'));
        // A page from the menu is a card too: from 3x3 it shows the page, up to 12x8.
        $this->assertSame(['min' => [1, 1], 'max' => [12, 8], 'card' => [3, 3]], $layout->bounds('app.nav.inbox'));
        $this->assertSame('1x1', $layout->size('calendar', '1x1'));
        $this->assertSame('9x8', $layout->size('app.nav.inbox', '9x9'));
        $this->assertSame('4x4', $layout->defaultSize('app.nav.inbox'));
        $this->assertTrue($layout->hasCard('app.nav.inbox'));
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
        $this->assertNull($layout->page($staff, 'app.nav.my-space.leaves')); // shortcuts are links already

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

    public function test_a_minimised_card_is_kept_for_the_strip_and_only_while_hidden(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $layout = (new DashboardLayout)->defaults($user);

        foreach ($layout as &$item) {
            $item = match ($item['key']) {
                'news' => ['hidden' => true, 'min' => true] + $item,
                'my-leaves' => ['hidden' => false, 'min' => true] + $item, // shown: nothing to keep
                default => $item,
            };
        }
        unset($item);

        Livewire::actingAs($user)->test(Index::class)->call('saveLayout', $layout)->assertHasNoErrors();

        $saved = collect(SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout)->keyBy('key');
        $this->assertSame([true, true], [$saved['news']['hidden'], $saved['news']['min']]);
        $this->assertArrayNotHasKey('min', $saved['my-leaves']);

        // A minimised card is hidden, so the page does not draw it; the strip offers it back.
        Livewire::actingAs($user)->test(Index::class)
            ->assertDontSeeHtml('data-key="news"')
            ->assertSeeHtml('data-testid="dashboard-dock"');
    }

    public function test_a_card_has_window_controls_and_its_grip_at_rest(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(Index::class)
            ->assertSeeHtml('class="hc-wctl"')
            ->assertSeeHtml('x-on:click="minimise(')
            ->assertSeeHtml('x-on:click="maximise(')
            // No resize corner on a pinned card (its size stays), 2026-11-04.
            ->assertSeeHtml('&& (editing || ! closed(');

        // A resize corner in each of the four corners; only the bottom inline-end one is a control.
        $html = Livewire::actingAs($user)->test(Index::class)->html();
        foreach (['end-end', 'end-start', 'start-end', 'start-start'] as $corner) {
            $this->assertStringContainsString('data-corner="'.$corner.'"', $html);
        }
        $this->assertSame(substr_count($html, 'data-corner="end-end"'), substr_count($html, 'class="hc-grip" data-corner="end-end"'));
        $this->assertStringContainsString('class="hc-grip hc-grip-more" data-corner="start-start" aria-hidden="true"', $html);
    }

    public function test_the_own_record_cards_are_for_someone_with_a_record_and_draw_it(): void
    {
        $city = $this->makeCity('ALP', '001');
        $layout = new DashboardLayout;
        $noRecord = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);
        $noRecord->forceFill(['employee_id' => null])->save();
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);
        $readOnly = $this->makeUser($city, 'viewer', ['leaves.view-own']); // its own role: permissions are per role

        foreach (['attention', 'me', 'request-leave'] as $key) {
            $this->assertFalse($layout->allowed($noRecord, $key), $key);
            $this->assertTrue($layout->allowed($staff, $key), $key);
        }
        // The form's own rule: no leaves.create, no card.
        $this->assertFalse($layout->allowed($readOnly, 'request-leave'));

        // Attention is on a staff member's first dashboard; the other two wait in Add.
        $shown = array_column(array_filter($layout->defaults($staff), fn (array $i): bool => ! $i['hidden']), 'key');
        $this->assertContains('attention', $shown);
        $this->assertNotContains('me', $shown);

        // Nothing on the record: one quiet line the grid folds away.
        Livewire::actingAs($staff)->test(AttentionCard::class)->assertSeeHtml('data-tile-empty');
        Livewire::actingAs($staff)->test(MeCard::class)->assertSeeHtml('data-testid="me-details"')->assertSee($staff->profile->name);
        $this->assertSame(route('leaves.index'), $layout->page($staff, 'request-leave'));
    }

    public function test_a_hub_group_filters_the_grid_to_its_own_cards(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own', 'leaves.create']);
        $decider = $this->makeUser($city, 'manager', ['leaves.view-own', 'leaves.decide']);

        $groups = (new DashboardLayout)->groupTiles($staff);

        // Its widgets the person may have, and the shortcuts to its own pages.
        $this->assertContains('my-leaves', $groups['leaves']);
        $this->assertContains('request-leave', $groups['leaves']);
        $this->assertContains('app.nav.my-space.leaves', $groups['leaves']);
        $this->assertContains('me', $groups['people']);
        $this->assertNotContains('my-leaves', $groups['people']);
        // Nothing to decide: no alert in any group; a statistics card the role may not have, nowhere.
        $this->assertNotContains('decide', $groups['leaves']);
        $this->assertNotContains('stats-department', array_merge(...array_values($groups)));

        // What waits on the viewer is in every group.
        foreach ((new DashboardLayout)->groupTiles($decider) as $id => $keys) {
            $this->assertContains('decide', $keys, $id);
        }

        $html = Livewire::actingAs($staff)->test(Index::class)->html();
        $this->assertSame($groups, $this->homeConfig($html)['groups']);
        $this->assertStringContainsString('data-testid="dashboard-filter-empty"', $html);

        // My Layout (pressed while nothing filters), the groups in the Categories menu
        // (several can be checked at once), and their strip has the way back.
        Livewire::actingAs($staff)->test(Hub::class)
            ->assertSeeHtml('data-hub="my-layout"')
            ->assertSeeHtml(':aria-pressed="(current.length === 0).toString()"')
            ->assertSeeHtml('data-testid="dashboard-categories"')
            ->assertSeeHtml('role="menuitemcheckbox"')
            ->assertSeeHtml(':aria-checked="on(')
            ->assertSeeHtml('hc-hub-filter')
            ->assertSeeHtml('data-hub-clear');
    }

    public function test_every_own_record_string_is_in_both_languages(): void
    {
        foreach (['tiles.attention', 'tiles.request-leave', 'tiles.me', 'attention.none', 'attention.contract-hint', 'attention.leave',
            'request-leave.continue', 'request-leave.hint', 'me.open', 'me.manager', 'me.not-set',
            'hub.show-all', 'home.filtered-said', 'home.unfiltered-said', 'home.filter-empty'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }
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
        // The glance card leads the page; the alerts come right after it.
        $this->assertSame(['glance', 'late', 'decide'], array_slice(array_column($layout->defaults($decider), 'key'), 0, 3));

        // Ended contracts are late for HR even without a decision of their own.
        $this->assertTrue($layout->allowed($hr, 'late'));
    }

    public function test_a_layout_saved_before_they_were_tiles_gets_them_first(): void
    {
        $city = $this->makeCity('ALP', '001');
        $decider = $this->makeUser($city, 'manager', ['leaves.decide']);
        $old = [
            ['key' => 'notes', 'size' => '5x3', 'pinned' => false, 'hidden' => false],
            ['key' => 'team', 'size' => '3x4', 'pinned' => true, 'hidden' => false],
        ];

        $keys = array_column((new DashboardLayout)->clean($decider, $old), 'key');

        // Pinned still first; then the glance card and the alerts, ahead of everything else.
        $this->assertSame(['team', 'glance', 'late', 'decide', 'notes'], array_slice($keys, 0, 5));
    }

    public function test_the_header_keeps_the_headline_and_quiet_alerts_are_not_drawn(): void
    {
        $city = $this->makeCity('ALP', '001');
        $decider = $this->makeUser($city, 'manager', ['leaves.decide']);

        Livewire::actingAs($decider)->test(Header::class)
            ->assertSeeHtml('data-testid="dashboard-headline"')
            ->assertDontSeeHtml('data-testid="late-band"')
            ->assertDontSeeHtml('data-testid="decision-card"');

        $html = Livewire::actingAs($decider)->test(Index::class)->html();
        // Nothing waits: no tile that would appear and then close, moving the page.
        $this->assertStringNotContainsString('data-key="late"', $html);
        $this->assertStringNotContainsString('data-key="decide"', $html);
    }

    public function test_tiles_carry_their_icon_panel_and_page_and_a_phone_stays_light(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own', 'checklists.view-own']);

        Livewire::actingAs($staff)->test(Index::class)->call('saveLayout', [
            ['key' => 'notes', 'size' => '5x3', 'pinned' => false, 'hidden' => false],
            ['key' => 'calendar', 'size' => '5x3', 'pinned' => false, 'hidden' => false],
            ['key' => 'app.nav.my-space.leaves', 'size' => '1x1', 'pinned' => false, 'hidden' => false],
        ]);
        $html = Livewire::test(Index::class)->html();

        // A widget: its icon (for when it is made small), its card as a popover panel, its closed line.
        $this->assertStringContainsString('class="hc-wmini"', $html);
        $this->assertStringContainsString('id="hc-panel-notes" popover="manual"', $html);
        $this->assertStringContainsString('class="hc-tile-line"', $html);
        // A widget with a page opens it on double-click; one without has none.
        $this->assertMatchesRegularExpression('/data-key="calendar"[^>]*data-page="'.preg_quote(route('team.calendar'), '/').'"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-key="notes"[^>]*data-page=/', $html);

        // A page from the menu has its card (the page itself, live), and its link.
        $this->assertStringContainsString('class="hc-appcard"', $html);
        $this->assertStringContainsString('href="'.route('leaves.index').'"', $html);

        // The phone does not draw the slideshow or the charts (the saved layout keeps it).
        $config = $this->homeConfig($html);
        $this->assertSame(DashboardLayout::PHONE_HIDDEN, $config['phoneHidden']);
        $this->assertContains('today-events', DashboardLayout::PHONE_HIDDEN);
        $this->assertContains('stats-salary', DashboardLayout::PHONE_HIDDEN);
        $this->assertNotEmpty($config['text']['nothing']);
    }

    public function test_a_big_shortcut_shows_its_data_only_within_the_scope(): void
    {
        $city = $this->makeCity('ALP', '001');
        $staff = $this->makeUser($city, 'employee', ['leaves.view-own']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.view']);

        // Not in the employee's menu: nothing at all.
        Livewire::actingAs($staff)->test(AppCard::class, ['tile' => 'app.nav.hr.contracts'])
            ->assertDontSee(__('dashboard.app-card.not-renewed'))
            ->assertDontSeeHtml('class="hc-acnum"');

        Livewire::actingAs($hr)->test(AppCard::class, ['tile' => 'app.nav.hr.contracts'])
            ->assertSee(__('dashboard.app-card.not-renewed'))
            ->assertSeeHtml('class="hc-acnum"');
    }

    public function test_every_new_string_is_in_both_languages(): void
    {
        foreach (['tiles.late', 'tiles.decide', 'late-tile.none', 'decide-tile.none', 'home.nothing', 'home.page-open',
            'home.page-hint', 'home.page-open-link', 'home.panel-close', 'app-card.inbox-empty', 'app-card.not-renewed'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }

        app()->setLocale('ar');
        $forms = array_map(fn (int $n) => trans_choice('dashboard.app-card.in-inbox', $n), [0, 1, 2, 3, 11, 100]);
        $this->assertSame('عنصر في صندوق مهامك', $forms[1]);
        $this->assertSame('عنصران في صندوق مهامك', $forms[2]);
    }

    /** The home screen's data-home attribute. */
    private function homeConfig(string $html): array
    {
        preg_match('/data-home="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
    }
}
