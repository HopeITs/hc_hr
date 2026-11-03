<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\Index;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The dashboard page as a desk: the hello, the grid it draws from the
 * person's layout, every widget a lazy panel, nothing at all for a panel
 * that is hidden, and the "Add a panel" tile and Customize sheet. The browser side (drag, resize, FLIP) is
 * resources/js/dashboard/home-screen.js and is not exercised here.
 */
class DashboardHomeScreenTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // A test that fails before its own clean-up must not leak a throwing scope.
        Model::clearBootedModels();

        parent::tearDown();
    }

    public function test_a_first_visit_draws_todays_dashboard_each_card_a_lazy_tile(): void
    {
        $html = Livewire::actingAs($this->employee())->test(Index::class)->html();

        // Today's order. "My leaves" is left out, as it always was, for someone without a balance.
        $this->assertSame(
            ['notes', 'team', 'news', 'calendar', 'upcoming-leaves', 'today-events', 'upcoming-events', 'pending-requests', 'checklist', 'badges'],
            array_slice($this->drawnKeys($html), 0, 10),
        );
        $this->assertSame([], array_filter($this->drawnKeys($html), fn (string $key): bool => str_starts_with($key, DashboardLayout::APP_PREFIX)));
        $this->assertNotContains('my-leaves', $this->drawnKeys($html));
        // The employee family's own defaults (prompt 8) follow today's cards.
        $this->assertSame(['next-holiday', 'birthdays'], array_slice($this->drawnKeys($html), 10, 2));

        // Every card waits behind its placeholder, named, so the page never waits for one.
        $this->assertSame(12, substr_count($html, 'data-state="loading"'));
        $this->assertStringContainsString(e(__('dashboard.tiles.today-events')), $html);
    }

    public function test_a_hidden_tile_is_not_drawn_and_its_card_never_loads(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        Livewire::test(Index::class)->call('saveLayout', [$this->item('team', hidden: true), $this->item('notes')]);

        // Every drawn tile rendered in place, so a loaded card would show here.
        $html = Livewire::withoutLazyLoading()->test(Index::class)->html();

        $this->assertNotContains('team', $this->drawnKeys($html));
        $this->assertStringNotContainsString('dashboard.team-card', $html);
        $this->assertStringNotContainsString(e(__('general.contact-the-team')), $html);
        $this->assertStringContainsString(e(__('general.notes')), $html);

        // It is still in the layout the page holds, so Customize (My panels) can offer it.
        $team = collect($this->homeConfig($html)['layout'])->firstWhere('key', 'team');
        $this->assertTrue($team['hidden']);
    }

    public function test_the_clock_waits_in_my_panels_until_it_is_switched_on(): void
    {
        $html = Livewire::actingAs($this->employee())->test(Index::class)->html();
        $config = $this->homeConfig($html);

        // In the layout, offered in My panels, not drawn on a first visit.
        $this->assertNotNull(app(DashboardLayout::class)->component('clock'));
        $this->assertContains('clock', array_column($config['layout'], 'key'));
        $this->assertNotContains('clock', $config['absent']);
        $this->assertStringContainsString("x-model=\"deskShown['clock']\"", $html);
        $this->assertNotContains('clock', $this->drawnKeys($html));

        // So the "Add a panel" tile is there, with how many are left to add.
        $hidden = count(array_filter($config['layout'], fn (array $item): bool => $item['hidden'] && ! in_array($item['key'], $config['absent'], true)));
        $this->assertGreaterThan(0, $hidden);
        $this->assertDoesNotMatchRegularExpression('/data-testid="dashboard-add-panel"(?:(?!<\/button>).)*?\sx-cloak\s*>/s', $html);
        $this->assertStringContainsString(e(trans_choice('dashboard.home.more-available', $hidden, ['count' => $hidden])), $html);
    }

    public function test_the_statistics_tiles_are_offered_but_not_drawn_by_default(): void
    {
        $hr = $this->makeUser($this->makeCity('ALP', '001'), 'HR-ALP', ['statistics.view', 'employees.view']);

        $html = Livewire::actingAs($hr)->test(Index::class)->html();

        foreach (['stats-department', 'stats-job-level', 'stats-contract-type', 'stats-no-leaves', 'soon-ending-contracts'] as $key) {
            $this->assertNotContains($key, $this->drawnKeys($html), $key);
            $this->assertStringContainsString("x-model=\"deskShown['{$key}']\"", $html, $key);
        }
        $this->assertStringNotContainsString('dashboard.department-employee-statistics', $html);
    }

    public function test_the_add_panel_tile_goes_once_every_panel_is_on_the_desk(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $layout = array_map(fn (array $item): array => ['hidden' => false] + $item, (new DashboardLayout)->defaults($user));
        Livewire::test(Index::class)->call('saveLayout', $layout)->assertHasNoErrors();

        // Still drawn (the browser shows it again once a panel is removed), but cloaked.
        $this->assertMatchesRegularExpression('/data-testid="dashboard-add-panel"(?:(?!<\/button>).)*?\sx-cloak\s*>/s', Livewire::test(Index::class)->html());
    }

    public function test_add_draws_a_tile_the_page_never_had(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        Livewire::test(Index::class)->call('saveLayout', [$this->item('team', hidden: true)]);

        $page = Livewire::test(Index::class);
        $this->assertNotContains('team', $this->drawnKeys($page->html()));

        $page->call('addTile', [$this->item('team', hidden: false)])->assertHasNoErrors();

        $this->assertArrayHasKey('html', $page->effects, 'addTile renders, so the new tile arrives');
        $this->assertSame('team', $this->drawnKeys($page->html())[0]);
        $this->assertFalse(collect(SavedDashboardLayout::query()->sole()->layout)->firstWhere('key', 'team')['hidden']);
    }

    public function test_add_cannot_draw_a_tile_the_role_may_not_have(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $page = Livewire::test(Index::class)->call('addTile', [$this->item('stats-salary'), $this->item('app.nav.hr.employees', '1x1')]);

        $this->assertNotContains('stats-salary', $this->drawnKeys($page->html()));
        $this->assertNotContains('app.nav.hr.employees', $this->drawnKeys($page->html()));
        $this->assertStringNotContainsString('dashboard.salary-per-employee-statistics', $page->html());
    }

    public function test_customise_in_the_address_opens_the_customize_sheet_with_the_phone_bar_picker(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        // The address the command palette's "Customize dashboard" opens.
        $this->assertTrue($this->homeConfig($this->get(route('dashboard', ['customise' => 1]))->assertOk()->getContent())['customise']);
        $this->assertFalse($this->homeConfig($this->get(route('dashboard'))->assertOk()->getContent())['customise']);

        // The phone bar's picker sits in My panels, for phones only (rendered in place here; lazy on the page).
        $html = Livewire::withoutLazyLoading()->test(Index::class)->html();
        $this->assertMatchesRegularExpression('/<div class="md:hidden" data-slot="bottom-bar-picker">\s*<section[^>]*data-testid="bottom-bar-picker"/', $html);
        $this->assertStringContainsString('id="hc-desk-panels"', $html);
    }

    public function test_the_page_draws_the_hello_and_panels_keep_their_tour_anchor(): void
    {
        $html = $this->actingAs($this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own', 'leaves.create']))
            ->get(route('dashboard'))->assertOk()->getContent();

        // The hello above the grid: date, greeting, Customize (the tour's anchor).
        $hello = substr($html, (int) strpos($html, 'data-testid="dashboard-header"'));
        $hello = substr($hello, 0, (int) strpos($hello, '</section>'));
        $this->assertLessThan(strpos($html, 'class="hc-home-screen"'), strpos($html, 'data-testid="dashboard-header"'));
        $this->assertStringContainsString(e(now()->translatedFormat('l, j F Y')), $hello);
        $this->assertMatchesRegularExpression('/data-tour="dashboard\.customise" data-testid="dashboard-customize"/', $hello);
        $this->assertStringContainsString(e(__('dashboard.desk.open')), $hello);
        // No New-action buttons in the hello, even for someone who may start a leave.
        $this->assertStringNotContainsString('data-action=', $hello);
        $this->assertStringNotContainsString(e(route('leaves.index', ['new' => 1])), $hello);

        // The panels' tools: open in full only where there is a page.
        $this->assertStringContainsString('href="'.route('team.calendar').'" wire:navigate class="hc-tbtn"', $html);

        // On the tile itself, so the tour finds a card before it has loaded.
        foreach (['notes', 'team', 'news', 'upcoming-leaves', 'pending-requests'] as $key) {
            $this->assertMatchesRegularExpression('/data-key="'.preg_quote($key, '/').'"\s+data-tour="dashboard\.'.preg_quote($key, '/').'"/', $html, $key);
        }
        // The page's own script, on this page only.
        $this->assertMatchesRegularExpression('/<script type="module" src="[^"]*home-screen[^"]*\.js"/', $html);
    }

    public function test_a_failing_leave_balance_check_shows_inside_the_my_leaves_tile_only(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'leaver');
        $contract = $this->makeContractFor($employee, ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $type = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'سنوية', 'base_days' => 20]);
        LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $type->id, 'deserved' => 160, 'used' => 0]);
        $user = $this->makeUser($city, 'employee', ['leaves.view-own'], $employee);

        // The page's own check (does this person have a balance?) fails: the
        // card itself is lazy, so this is the page's only read of balances.
        LeaveBalance::addGlobalScope('prompt4-boom', function (): void {
            throw new RuntimeException('simulated load failure');
        });

        try {
            $html = Livewire::actingAs($user)->test(Index::class)->assertOk()->html();
        } finally {
            Model::clearBootedModels();
        }

        // The tile stays, saying it failed; every other tile is drawn as usual.
        $this->assertContains('my-leaves', $this->drawnKeys($html));
        $this->assertContains('notes', $this->drawnKeys($html));
        $this->assertSame(1, substr_count($html, 'role="alert"'));
    }

    private function employee(): User
    {
        return $this->makeUser($this->makeCity('ALP', '001'), 'employee', ['leaves.view-own']);
    }

    private function item(string $key, string $size = '3x4', bool $hidden = false): array
    {
        return ['key' => $key, 'size' => $size, 'hidden' => $hidden];
    }

    /** @return list<string> the tiles the page drew, in order */
    private function drawnKeys(string $html): array
    {
        preg_match_all('/<div class="hc-tile[^"]*"[^>]*\sdata-key="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    /** The home screen's data-home attribute (layout, absent tiles, customise…). */
    private function homeConfig(string $html): array
    {
        preg_match('/data-home="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
    }
}
