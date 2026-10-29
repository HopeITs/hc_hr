<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Concerns\HasDashboardLayout;
use App\Livewire\Dashboard\Index;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The customisable dashboard's data layer (prompt 3): which tiles a person may
 * have, how a posted layout is cleaned, and that layouts are kept per role.
 */
class DashboardLayoutTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    public function test_an_employee_cannot_save_hr_tiles_the_salary_tile_or_shortcuts_to_pages_they_cannot_open(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', ['leaves.view-own']);

        $this->actingAs($employee);

        Livewire::test(DashboardLayoutProbe::class)->call('saveLayout', [
            $this->item('stats-salary'),
            $this->item('stats-department'),
            $this->item('soon-ending-contracts'),
            $this->item('app.nav.hr.employees', '1x1'),
            $this->item('app.nav.settings.access', '1x1'),
            $this->item('app.new.employee', '1x1'),
            $this->item('notes'),
            $this->item('app.nav.my-space.leaves', '1x1'),
        ])->assertHasNoErrors();

        $keys = $this->storedKeys($employee);

        foreach (['stats-salary', 'stats-department', 'soon-ending-contracts', 'app.nav.hr.employees', 'app.nav.settings.access', 'app.new.employee'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }

        $this->assertContains('notes', $keys);
        $this->assertContains('app.nav.my-space.leaves', $keys);
        // What they posted keeps its order at the top.
        $this->assertSame(['notes', 'app.nav.my-space.leaves'], array_slice($keys, 0, 2));
    }

    public function test_hr_with_statistics_gets_the_statistics_tiles_and_salary_tile(): void
    {
        $city = $this->makeCity('SYR', '000');
        $hr = $this->makeUser($city, 'HR-SYR');

        $keys = $this->keys($this->layout()->defaults($hr));

        $this->assertContains('stats-department', $keys);
        $this->assertContains('stats-salary', $keys);
        $this->assertContains('app.nav.hr.employees', $keys);
    }

    public function test_city_hr_without_statistics_permission_gets_no_statistics_tiles(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.view']);

        $layout = $this->layout();

        $this->assertFalse($layout->allowed($hr, 'stats-department'));
        $this->assertFalse($layout->allowed($hr, 'stats-salary'));
        $this->assertTrue($layout->allowed($hr, 'app.nav.hr.employees'));
    }

    public function test_super_admin_never_gets_the_salary_tile(): void
    {
        $city = $this->makeCity('SYR', '000');
        $admin = $this->makeUser($city, 'super-admin', ['statistics.view']);

        $layout = $this->layout();

        $this->assertTrue($layout->allowed($admin, 'stats-department'));
        $this->assertFalse($layout->allowed($admin, 'stats-salary'));
        $this->assertNotContains('stats-salary', $this->keys($layout->defaults($admin)));
        $this->assertNotContains('stats-salary', $this->keys($layout->clean($admin, [$this->item('stats-salary')])));
    }

    public function test_out_of_bounds_sizes_are_clamped_and_unknown_and_duplicate_keys_dropped(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);

        $clean = $this->layout()->clean($user, [
            $this->item('calendar', '0x1'),          // below one cell
            $this->item('notes', '99x99'),           // above max 12x8
            $this->item('app.nav.my-space.leaves', '9x9'),   // a shortcut is at most 4x4 (a card)
            $this->item('team', 'huge'),             // unreadable: the default
            $this->item('no-such-tile'),
            $this->item('notes', '2x2'),             // duplicate: the first wins
            'not-an-item',
            ['size' => '2x2'],
        ]);

        $sizes = array_column($clean, 'size', 'key');

        // Any widget goes down to one cell (an icon that opens it in the side panel).
        $this->assertSame('1x1', $sizes['calendar']);
        $this->assertSame('12x8', $sizes['notes']);
        $this->assertSame('4x4', $sizes['app.nav.my-space.leaves']);
        $this->assertSame('3x4', $sizes['team']);
        $this->assertNotContains('no-such-tile', $this->keys($clean));
        $this->assertSame(count($clean), count(array_unique($this->keys($clean))));
        $this->assertLessThanOrEqual(DashboardLayout::MAX_ITEMS, count($clean));
    }

    public function test_pinned_tiles_come_first_and_looks_are_kept_within_their_list(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');

        $clean = $this->layout()->clean($user, [
            $this->item('notes'),
            $this->item('calendar', pinned: true),
            ['key' => 'clock', 'size' => '2x2', 'pinned' => false, 'hidden' => false, 'style' => 'analog'],
        ]);

        $this->assertSame('calendar', $clean[0]['key']);
        $this->assertSame('analog', collect($clean)->firstWhere('key', 'clock')['style']);

        $clean = $this->layout()->clean($user, [['key' => 'clock', 'size' => '2x2', 'pinned' => false, 'hidden' => false, 'style' => 'sundial']]);
        $this->assertSame('digital', $clean[0]['style']);
    }

    public function test_the_default_layout_reproduces_todays_dashboard_order(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee', ['leaves.view-own']);

        $shown = array_values(array_filter($this->layout()->defaults($user), fn ($item) => ! $item['hidden']));

        $this->assertSame(
            ['for-you', 'my-leaves', 'notes', 'team', 'news', 'calendar', 'upcoming-leaves', 'today-events', 'upcoming-events', 'pending-requests', 'checklist', 'badges'],
            array_slice($this->keys($shown), 0, 12),
        );
        $this->assertContains('app.nav.my-space.leaves', $this->keys($shown));
    }

    public function test_the_payload_shape_is_validated_strictly(): void
    {
        $city = $this->makeCity('ALP', '001');
        $this->actingAs($this->makeUser($city, 'employee'));

        Livewire::test(DashboardLayoutProbe::class)
            ->call('saveLayout', [['key' => 'notes', 'size' => '4x3', 'pinned' => 'yes', 'hidden' => false]])
            ->assertHasErrors('items.0.pinned')
            ->call('saveLayout', [['key' => 'notes', 'size' => 'big', 'pinned' => false, 'hidden' => false]])
            ->assertHasErrors('items.0.size')
            ->call('saveLayout', [['key' => 'notes', 'size' => '4x3', 'pinned' => false, 'hidden' => false, 'html' => '<b>']])
            ->assertHasErrors('items.0')
            ->call('saveLayout', [['key' => ['notes'], 'size' => '4x3', 'pinned' => false, 'hidden' => false]])
            ->assertHasErrors('items.0.key');

        $this->assertSame(0, SavedDashboardLayout::query()->count());
    }

    public function test_layouts_are_stored_per_role_and_switching_role_returns_that_roles_layout(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.view']);
        $user = $this->assignInactiveRole($user, 'employee');

        $this->actingAs($user);
        Livewire::test(DashboardLayoutProbe::class)->call('saveLayout', [$this->item('team', pinned: true)]);

        $user->setActiveRole(Role::findByName('employee', 'web')->id);
        $user = $user->fresh();
        $this->actingAs($user);

        $probe = Livewire::test(DashboardLayoutProbe::class);
        $this->assertSame('for-you', $probe->get('layout')[0]['key'], 'employee sees its default, not the HR layout');

        $probe->call('saveLayout', [$this->item('badges', pinned: true)]);

        $this->assertSame(
            ['HR-ALP', 'employee'],
            SavedDashboardLayout::query()->where('user_id', $user->id)->orderBy('role')->pluck('role')->all(),
        );

        $hrRole = Role::findByName('HR-ALP', 'web');
        $user->setActiveRole($hrRole->id);
        $this->actingAs($user->fresh());

        $this->assertSame('team', Livewire::test(DashboardLayoutProbe::class)->get('layout')[0]['key']);
    }

    public function test_a_tile_added_to_the_catalogue_later_appears_for_a_saved_layout(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');

        // Saved before "badges" and "clock" existed.
        SavedDashboardLayout::query()->create([
            'user_id' => $user->id,
            'role' => 'employee',
            'layout' => [$this->item('notes'), $this->item('team')],
        ]);

        $this->actingAs($user);
        $layout = collect(Livewire::test(DashboardLayoutProbe::class)->get('layout'))->keyBy('key');

        $this->assertSame(['notes', 'team'], $layout->keys()->take(2)->all());
        $this->assertFalse($layout['badges']['hidden'], 'shown by default, so it appears');
        $this->assertTrue($layout['clock']['hidden'], 'not shown by default, so it waits in the gallery');
    }

    public function test_a_shortcut_disappears_when_the_user_loses_access_to_its_page(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.view']);

        $this->actingAs($user);
        Livewire::test(DashboardLayoutProbe::class)->call('saveLayout', [$this->item('app.nav.hr.employees', '2x2')]);
        $this->assertContains('app.nav.hr.employees', $this->storedKeys($user));

        Role::findByName('HR-ALP', 'web')->revokePermissionTo(Permission::findByName('employees.view', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user = $user->fresh());

        $this->assertNotContains('app.nav.hr.employees', $this->keys(Livewire::test(DashboardLayoutProbe::class)->get('layout')));
    }

    public function test_reset_returns_to_the_default_and_never_creates_a_row(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');
        $this->actingAs($user);

        Livewire::test(DashboardLayoutProbe::class)->call('resetLayout');
        $this->assertSame(0, SavedDashboardLayout::query()->count());

        $probe = Livewire::test(DashboardLayoutProbe::class)
            ->call('saveLayout', [$this->item('badges', pinned: true)])
            ->call('resetLayout');

        $this->assertNull(SavedDashboardLayout::query()->sole()->layout);
        $this->assertSame('for-you', $probe->get('layout')[0]['key']);
    }

    public function test_the_dashboard_page_saves_and_resets_the_layout(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'employee');
        $this->actingAs($user);

        $page = Livewire::test(Index::class)
            ->call('saveLayout', [$this->item('notes', '6x4', pinned: true), $this->item('stats-salary')])
            ->assertHasNoErrors();

        // Prompt 4: the browser already shows the change, so saving sends no page back.
        $this->assertArrayNotHasKey('html', $page->effects, 'saveLayout is renderless');

        $row = SavedDashboardLayout::query()->sole();
        $this->assertSame('employee', $row->role);
        $this->assertSame(['key' => 'notes', 'size' => '6x4', 'pinned' => true, 'hidden' => false], $row->layout[0]);
        $this->assertNotContains('stats-salary', $this->keys($row->layout));

        // The next visit draws the saved order: the pinned card first.
        $this->assertSame('notes', $this->drawnKeys(Livewire::test(Index::class)->html())[0]);

        // Reset renders, and the page then holds the role's default layout again.
        $reset = Livewire::test(Index::class)->call('resetLayout');
        $this->assertArrayHasKey('html', $reset->effects, 'resetLayout re-renders');
        $this->assertNull($row->fresh()->layout);
        $this->assertSame('for-you', $this->drawnKeys($reset->html())[0]);
        $this->assertSame(
            $this->keys($this->layout()->defaults($user)),
            $this->keys($this->homeConfig($reset->html())['layout']),
        );
    }

    private function layout(): DashboardLayout
    {
        return new DashboardLayout;
    }

    /** @return list<string> */
    private function keys(array $layout): array
    {
        return array_column($layout, 'key');
    }

    private function item(string $key, string $size = '4x3', bool $pinned = false, bool $hidden = false): array
    {
        return ['key' => $key, 'size' => $size, 'pinned' => $pinned, 'hidden' => $hidden];
    }

    private function storedKeys(User $user): array
    {
        $row = SavedDashboardLayout::query()->where('user_id', $user->id)->sole();

        return $this->keys($row->layout);
    }

    /** @return list<string> the tiles the page drew, in order */
    private function drawnKeys(string $html): array
    {
        preg_match_all('/<div class="hc-tile[^"]*"[^>]*\sdata-key="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    /** The home screen's data-home attribute (layout, absent tiles, editing…). */
    private function homeConfig(string $html): array
    {
        preg_match('/data-home="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
    }
}

/** Exposes the concern's read path; the real page renders it in prompt 4. */
class DashboardLayoutProbe extends Component
{
    use HasDashboardLayout;

    public array $layout = [];

    public function render()
    {
        $this->layout = $this->dashboardLayout();

        return '<div></div>';
    }
}
