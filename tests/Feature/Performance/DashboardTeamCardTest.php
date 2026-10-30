<?php

namespace Tests\Feature\Performance;

use App\Livewire\Dashboard\TeamCard;
use App\Models\City;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The dashboard team card used to render an <img> for every colleague at full
 * size, eagerly, whether or not the viewer could fetch that photo (each refusal
 * was a wasted 403). It now renders photos lazily at a reserved size, and only
 * for people the viewer may see. Since prompt 4 the card is its own dashboard
 * tile (TeamCard) instead of a section of Dashboard\Index.
 */
class DashboardTeamCardTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
    }

    public function test_authorized_viewer_gets_lazy_sized_img_for_permitted_people(): void
    {
        $viewer = $this->viewer();
        $other = $this->colleague($this->homs, 'Hamsa');

        $html = Livewire::actingAs($viewer)->test(TeamCard::class)->html();

        $this->assertStringContainsString($other->avatar_url, $html);
        $this->assertMatchesRegularExpression('/<img[^>]+loading="lazy"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+decoding="async"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+width="34"[^>]+height="34"/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+alt="Hamsa Colleague"/', $html);
    }

    public function test_forbidden_photo_renders_initials_and_leaks_no_url(): void
    {
        $viewer = $this->viewer();
        $allowed = $this->colleague($this->aleppo, 'Allowed');
        $forbidden = $this->colleague($this->homs, 'Forbidden');

        // Same ability the avatar endpoint enforces; refuse one person.
        Gate::before(fn ($user, $ability, $args) => $ability === 'viewAvatar' && ($args[0] ?? null)?->is($forbidden) ? false : null);

        $html = Livewire::actingAs($viewer)->test(TeamCard::class)->html();

        $this->assertStringContainsString($allowed->avatar_url, $html);
        $this->assertStringNotContainsString(route('employees.avatar', ['employee' => $forbidden->id]), $html);
        $this->assertStringNotContainsString('/employees/'.$forbidden->id.'/avatar', $html);
        $this->assertStringNotContainsString('alt="Forbidden Colleague"', $html);
        // Still named for screen readers, and the initials stand in visually.
        $this->assertStringContainsString('Forbidden Colleague', $html);
        $this->assertMatchesRegularExpression('/aria-hidden="true"[^>]*>FC</', $html);
    }

    public function test_query_count_does_not_grow_with_team_size(): void
    {
        $viewer = $this->viewer();
        foreach (range(1, 4) as $i) {
            $this->colleague($this->homs, 'Small'.$i);
        }
        $count = function () use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($viewer)->test(TeamCard::class);

            return count(DB::getQueryLog());
        };

        $small = $count();

        foreach (range(1, 25) as $i) {
            $this->colleague($this->homs, 'Large'.$i);
        }
        $large = $count();

        // 25 more colleagues must not add a query each. A small slack covers
        // data-dependent branches, not per-row work.
        $this->assertLessThanOrEqual($small + 3, $large, "Queries grew from {$small} to {$large} with 25 more colleagues.");
        $this->assertLessThan(120, $large);
    }

    private function colleague(City $city, string $name): Employee
    {
        $employee = $this->makeEmployee($city, strtolower($name).'.'.uniqid(), [
            'first_name_en' => $name, 'last_name_en' => 'Colleague',
        ]);
        $this->makeContractFor($employee, [
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => today()->addYear()->toDateString(),
        ]);

        return $employee->fresh();
    }

    private function viewer(string $role = 'HR-ALP')
    {
        $employee = $this->colleague($this->aleppo, 'Viewer');

        return $this->makeUser($this->aleppo, $role, [], $employee);
    }
}
