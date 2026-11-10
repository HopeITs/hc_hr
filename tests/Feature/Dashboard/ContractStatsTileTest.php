<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\AddToDashboard;
use App\Livewire\Dashboard\ContractStatsCard;
use App\Livewire\Dashboard\Index;
use App\Livewire\Statistics\ContractStatistics;
use App\Models\City;
use App\Models\Contract;
use App\Models\Program;
use App\Models\SavedDashboardLayout;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Add to dashboard" on the contract statistics page (2026-11-04): each
 * program or center card becomes a tile "cstat.<program>.<center>.<city>",
 * behind the page's statistics.view and the active role's cities.
 */
class ContractStatsTileTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    private City $hms;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->alp = $this->makeCity('ALP', '001');
        $this->hms = $this->makeCity('HMS', '002');
        $this->contract = $this->makeContractFor($this->makeEmployee($this->alp, 'worker', ['is_active' => true]));
    }

    public function test_keys_are_read_strictly(): void
    {
        $layout = new DashboardLayout;

        $this->assertSame('cstat.4.0.2', DashboardLayout::contractStatKey(4, 0, 2));
        $this->assertSame([4, 7, 2], $layout->contractStat('cstat.4.7.2'));

        foreach (['cstat.0.0.0', 'cstat.4.0', 'cstat.4.x.1', 'cstat.04.0.1', 'cstat.4.0.1.9', 'cstat.4.-1.0'] as $key) {
            $this->assertNull($layout->contractStat($key), $key);
        }
    }

    public function test_a_card_is_allowed_only_within_the_pages_rule_and_the_roles_cities(): void
    {
        $layout = new DashboardLayout;
        $program = $this->program();
        $center = $this->contract->position->center_id;
        $regional = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);
        $national = $this->makeUser($this->alp, 'HR-SYR', ['statistics.view']);
        // Another role: permissions are kept on the role, and HR-ALP already has statistics.view here.
        $none = $this->makeUser($this->alp, 'manager', ['employees.view']);

        $this->assertTrue($layout->allowed($regional, DashboardLayout::contractStatKey($program, 0, $this->alp->id)));
        $this->assertTrue($layout->allowed($regional, DashboardLayout::contractStatKey($program, $center, $this->alp->id)));
        // Regional HR: never "every city", never another city, never a center outside the city.
        $this->assertFalse($layout->allowed($regional, DashboardLayout::contractStatKey($program, 0, 0)));
        $this->assertFalse($layout->allowed($regional, DashboardLayout::contractStatKey($program, 0, $this->hms->id)));
        $this->assertFalse($layout->allowed($national, DashboardLayout::contractStatKey($program, $center, $this->hms->id)));

        // Headquarters sees every city.
        $this->assertTrue($layout->allowed($national, DashboardLayout::contractStatKey($program, 0, 0)));
        $this->assertTrue($layout->allowed($national, DashboardLayout::contractStatKey($program, $center, 0)));

        // No statistics.view, an unknown or inactive program: nothing.
        $this->assertFalse($layout->allowed($none, DashboardLayout::contractStatKey($program, 0, $this->alp->id)));
        $this->assertFalse($layout->allowed($national, DashboardLayout::contractStatKey(999999, 0, 0)));
        Program::query()->whereKey($program)->update(['is_active' => false]);
        $this->assertFalse((new DashboardLayout)->allowed($national, DashboardLayout::contractStatKey($program, 0, 0)));
    }

    public function test_every_card_on_the_page_has_the_button(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);
        $program = $this->program();

        Livewire::actingAs($hr)->test(ContractStatistics::class)
            ->assertSeeHtml('atd-'.DashboardLayout::contractStatKey($program, 0, $this->alp->id))
            ->set('selectedProgram', $program)
            ->assertSeeHtml('atd-'.DashboardLayout::contractStatKey($program, $this->contract->position->center_id, $this->alp->id));
    }

    public function test_the_tile_counts_what_the_page_counts_and_goes_on_and_off_the_dashboard(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);
        $key = DashboardLayout::contractStatKey($this->program(), 0, $this->alp->id);

        Livewire::actingAs($hr)->test(ContractStatsCard::class, (new DashboardLayout)->componentParams($key))
            ->assertSee('Core Program')
            ->assertSee(trans_choice('general.employee', 2).':')
            ->assertSeeHtml('<span class="font-bold text-ink num">1</span>')
            ->assertDontSee(__('dashboard.record.gone'));

        $button = Livewire::actingAs($hr)->test(AddToDashboard::class, ['tile' => $key, 'compact' => true])->call('toggle');
        $this->assertSame($key, $this->saved($hr)[0]['key']);
        $this->assertStringContainsString('data-key="'.$key.'"', Livewire::actingAs($hr)->test(Index::class)->html());

        $button->call('toggle');
        $this->assertNotContains($key, array_column($this->saved($hr), 'key'));
    }

    public function test_a_card_outside_the_roles_cities_shows_nothing_and_cannot_be_stored(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);
        $key = DashboardLayout::contractStatKey($this->program(), 0, 0);

        Livewire::actingAs($hr)->test(ContractStatsCard::class, (new DashboardLayout)->componentParams($key))
            ->assertSee(__('dashboard.record.gone'))
            ->assertDontSee('Core Program');

        Livewire::actingAs($hr)->test(AddToDashboard::class, ['tile' => $key])->call('toggle')->assertForbidden();

        foreach (['en', 'ar'] as $locale) {
            $this->assertNotSame('dashboard.tiles.cstat', __('dashboard.tiles.cstat', [], $locale));
        }
    }

    private function program(): int
    {
        return (int) $this->contract->position->program_id;
    }

    /** @return array<int, array<string, mixed>> */
    private function saved(User $user): array
    {
        $layout = SavedDashboardLayout::query()->where('user_id', $user->id)->sole()->layout;

        return is_string($layout) ? json_decode($layout, true) : $layout;
    }
}
