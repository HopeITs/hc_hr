<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Badges\BadgesModule;
use App\Livewire\Dashboard\AddToDashboard;
use App\Livewire\Dashboard\EmployeeChartStatistics;
use App\Livewire\Dashboard\EmployeeSectionCard;
use App\Livewire\Dashboard\Index;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\News\NewsModule;
use App\Livewire\Statistics\GeneralStatistics;
use App\Models\City;
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
 * "Add to dashboard" on every card of the site (2026-11-04): the statistics
 * page's cards, the pages that have a live card, and every section of a
 * profile (EmployeeSectionCard), each behind the rule its page or section
 * already has.
 */
class AddToDashboardEverywhereTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const SECTIONS = ['overview', 'timeline', 'education', 'experience', 'skills', 'courses', 'badges', 'personal', 'personal-contact', 'family'];

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_every_statistics_card_has_the_button_for_someone_with_statistics(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);

        $html = Livewire::withoutLazyLoading()->actingAs($hr)->test(GeneralStatistics::class)->html();

        // All seven cards (the salary one also needs pay visibility, which regional HR has).
        $this->assertTrue($hr->canViewSalary());
        $this->assertSame(7, substr_count($html, 'data-testid="add-to-dashboard"'));
        $this->assertTrue((new DashboardLayout)->allowed($hr, 'stats-personnel'));
        $this->assertFalse((new DashboardLayout)->allowed($this->makeUser($this->alp, 'employee', ['leaves.view-own']), 'stats-personnel'));
    }

    public function test_the_personnel_card_is_a_tile(): void
    {
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['statistics.view']);
        $layout = new DashboardLayout;

        $this->assertSame(EmployeeChartStatistics::class, $layout->component('stats-personnel'));
        $this->assertContains('stats-personnel', $layout->available($hr));

        Livewire::actingAs($hr)->test(EmployeeChartStatistics::class)
            ->assertSee(__('general.employees-statistics'))
            ->assertSeeHtml('employeeChart-');
    }

    public function test_pages_with_a_live_card_offer_it_in_their_heading(): void
    {
        $user = $this->makeUser($this->alp, 'HR-ALP', ['news.view', 'badges.view']);

        Livewire::actingAs($user)->test(NewsModule::class)->assertSeeHtml('data-testid="add-to-dashboard"');
        Livewire::actingAs($user)->test(BadgesModule::class)->assertSeeHtml('data-testid="add-to-dashboard"');

        foreach ([
            'news.news-module' => 'news', 'badges.badges-module' => 'badges', 'team.calendar' => 'calendar',
            'checklist-items.all' => 'checklist', 'checklist-items.pending' => 'pending-requests',
            'basics.holiday-module' => 'next-holiday', 'employee.leave.all' => 'upcoming-leaves',
        ] as $view => $tile) {
            $source = file_get_contents(resource_path('views/livewire/'.str_replace('.', '/', $view).'.blade.php'));
            $this->assertStringContainsString('<livewire:dashboard.add-to-dashboard tile="'.$tile.'" />', $source, $view);
        }
    }

    public function test_each_profile_section_card_follows_its_section_rule(): void
    {
        $layout = new DashboardLayout;
        $hms = $this->makeCity('HMS', '002');
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->alp, 'colleague', ['is_active' => true]);
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['employees.view']);
        $far = $this->makeEmployee($hms, 'far-away', ['is_active' => true]);

        foreach (self::SECTIONS as $section) {
            // Their own profile: every section.
            $this->assertTrue($layout->allowed($staff, DashboardLayout::recordKey($section, $staff->employee_id)), $section);
            $this->assertSame(['employeeId' => $staff->employee_id, 'section' => $section], $layout->componentParams(DashboardLayout::recordKey($section, $staff->employee_id)));
            $this->assertSame(EmployeeSectionCard::class, $layout->component(DashboardLayout::recordKey($section, $staff->employee_id)));
        }

        // A colleague: the directory's overview only, nothing of their records or private life.
        $this->assertTrue($layout->allowed($staff, DashboardLayout::recordKey('overview', $colleague->id)));
        foreach (['timeline', 'education', 'skills', 'badges', 'personal', 'personal-contact', 'family'] as $section) {
            $this->assertFalse($layout->allowed($staff, DashboardLayout::recordKey($section, $colleague->id)), $section);
        }

        // Regional HR: private sections in its own city only.
        $this->assertTrue($layout->allowed($hr, DashboardLayout::recordKey('personal', $colleague->id)));
        $this->assertFalse($layout->allowed($hr, DashboardLayout::recordKey('personal', $far->id)));
        $this->assertFalse($layout->allowed($hr, DashboardLayout::recordKey('family', $far->id)));
    }

    public function test_the_profile_offers_every_section_it_shows(): void
    {
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $staff->profile->relatives()->create(['name' => 'Sami', 'relation' => 1]);

        $html = Livewire::actingAs($staff)->test(EmployeeProfile::class, ['employee' => $staff->profile])->html();

        foreach ([...self::SECTIONS, 'contracts', 'contact'] as $section) {
            // Badges are drawn for the owner only when there are some to see.
            if ($section === 'badges') {
                continue;
            }
            $this->assertStringContainsString("atd-{$section}-{$staff->employee_id}", $html, $section);
        }
    }

    public function test_a_section_card_goes_on_the_dashboard_and_comes_off_again(): void
    {
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $key = DashboardLayout::recordKey('personal-contact', $staff->employee_id);

        $button = Livewire::actingAs($staff)->test(AddToDashboard::class, ['tile' => $key, 'compact' => true])->call('toggle');
        $this->assertSame($key, $this->saved($staff)[0]['key']);
        $this->assertStringContainsString('data-key="'.$key.'"', Livewire::actingAs($staff)->test(Index::class)->html());

        $button->call('toggle');
        $this->assertNotContains($key, array_column($this->saved($staff), 'key'));
    }

    public function test_a_section_card_shows_only_what_the_profile_would(): void
    {
        $staff = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        $colleague = $this->makeEmployee($this->alp, 'colleague', ['is_active' => true, 'personal_phone' => '0933333333']);
        $staff->profile->forceFill(['personal_phone' => '0944444444', 'skills' => 'Excel, Arabic'])->save();

        Livewire::actingAs($staff)->test(EmployeeSectionCard::class, ['employeeId' => $staff->employee_id, 'section' => 'personal-contact'])
            ->assertSee('0944444444');
        Livewire::actingAs($staff)->test(EmployeeSectionCard::class, ['employeeId' => $staff->employee_id, 'section' => 'skills'])
            ->assertSee('Excel')->assertSee('Arabic');

        // Someone else's private section, or a section the card does not draw: one quiet line.
        Livewire::actingAs($staff)->test(EmployeeSectionCard::class, ['employeeId' => $colleague->id, 'section' => 'personal-contact'])
            ->assertSee(__('dashboard.record.gone'))
            ->assertDontSee('0933333333');
        Livewire::actingAs($staff)->test(EmployeeSectionCard::class, ['employeeId' => $staff->employee_id, 'section' => 'contracts'])
            ->assertSee(__('dashboard.record.gone'));

        // The timeline never carries pay for a role that may not see it.
        $this->makeContractFor($staff->profile, ['salary' => 1234567]);
        Livewire::actingAs($staff)->test(EmployeeSectionCard::class, ['employeeId' => $staff->employee_id, 'section' => 'timeline'])
            ->assertSee(__('employees.profile.events.contract-joined'))
            ->assertDontSee('1,234,567');
    }

    public function test_the_new_names_exist_in_both_languages(): void
    {
        foreach ([...array_map(fn (string $s): string => 'tiles.rec-'.$s, self::SECTIONS), 'tiles.stats-personnel'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
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
