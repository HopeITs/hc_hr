<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Dashboard\DepartmentEmployeeStatistics;
use App\Livewire\Dashboard\EmployeeNotesCard;
use App\Livewire\Dashboard\Index;
use App\Livewire\Dashboard\NoLeavesEmployeesStatistics;
use App\Livewire\Dashboard\SoonEndingContractsCard;
use App\Livewire\Dashboard\UpcomingLeavesCard;
use App\Models\Checklist;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeNote;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use ErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * B3: dashboard cards tell "nothing here" apart from "could not load", recover on
 * retry, and the lazy ones render a placeholder instead of blocking the page.
 */
class DashboardCardStatesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        // A test that fails before repair() must not leak its throwing scope into later tests.
        Model::clearBootedModels();

        parent::tearDown();
    }

    public function test_soon_ending_card_shows_empty_state_not_error_when_nothing_ends(): void
    {
        $this->live($this->hr())
            ->test(SoonEndingContractsCard::class)
            ->assertSet('loadFailed', false)
            ->assertSee(__('dashboard.no-contracts-ending-soon'))
            ->assertDontSee(__('general.state-error-title'));
    }

    public function test_soon_ending_card_failure_is_an_error_state_and_retry_recovers(): void
    {
        $hr = $this->hr();
        $employee = $this->makeEmployee($hr->profile->city, 'ending-soon', ['first_name_en' => 'Soonest']);
        $this->makeContractFor($employee, ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addDays(5)->toDateString()]);

        $this->breakQueriesOn(Contract::class);
        $component = $this->live($hr)->test(SoonEndingContractsCard::class);

        $component->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertSee(__('general.retry'))
            ->assertDontSee(__('dashboard.no-contracts-ending-soon'));

        $this->repair();

        $component->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertDontSee(__('general.state-error-title'))
            ->assertSee('Soonest');
    }

    public function test_soon_ending_card_never_renders_pay_figures(): void
    {
        $hr = $this->hr();
        $employee = $this->makeEmployee($hr->profile->city, 'paid');
        $this->makeContractFor($employee, ['salary' => 987654, 'end_date' => now()->addDays(3)->toDateString(), 'start_date' => now()->subMonth()->toDateString()]);

        $html = $this->live($hr)->test(SoonEndingContractsCard::class)->html();

        $this->assertStringNotContainsString('987654', $html);
        $this->assertStringNotContainsString('987,654', $html);
    }

    public function test_cards_render_a_placeholder_instead_of_blocking_the_page(): void
    {
        $this->actingAs($this->hr());

        foreach (['soon-ending-contracts-card', 'no-leaves-employees-statistics'] as $tag) {
            $html = Blade::render("<livewire:dashboard.{$tag} />");

            $this->assertStringContainsString('aria-busy="true"', $html, $tag);
            $this->assertStringContainsString('data-state="loading"', $html, $tag);
            $this->assertStringContainsString(__('general.loading'), $html, $tag);
        }
    }

    public function test_cheap_cached_cards_are_not_lazy(): void
    {
        $this->actingAs($this->hr());

        $html = Blade::render('<livewire:dashboard.department-employee-statistics />');

        $this->assertStringNotContainsString('data-state="loading"', $html);
    }

    public function test_no_leaves_card_failure_and_retry(): void
    {
        $hr = $this->hr();

        $this->breakQueriesOn(Employee::class);
        $component = $this->live($hr)->test(NoLeavesEmployeesStatistics::class);

        $component->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee(__('dashboard.no-leaves-employees-empty'));

        $this->repair();

        $component->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertSee(__('dashboard.no-leaves-employees-empty'));
    }

    public function test_upcoming_leaves_empty_action_follows_permission(): void
    {
        $withAccess = $this->hr('DMS', '003', ['leaves.view-own']);
        $this->live($withAccess)->test(UpcomingLeavesCard::class)
            ->assertSee(__('general.no-available-data'))
            ->assertSee(route('leaves.index'));

        $withoutAccess = $this->makeUser($withAccess->profile->city, 'staff');
        $this->live($withoutAccess)->test(UpcomingLeavesCard::class)
            ->assertSee(__('general.no-available-data'))
            ->assertDontSee(route('leaves.index'));
    }

    public function test_upcoming_leaves_failure_and_retry_keep_city_scoping(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $dms = $this->makeCity('DMS', '003');
        $alp = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($dms, 'HR-DMS');
        $this->makeLeave($dms, 'Ownleaver');
        $this->makeLeave($alp, 'Otherleaver');

        $this->breakQueriesOn(LeaveRequest::class);
        $component = $this->live($hr)->test(UpcomingLeavesCard::class);
        $component->assertSet('loadFailed', true)->assertSee(__('general.state-error-title'));

        $this->repair();

        $component->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertSee('Ownleaver')
            ->assertDontSee('Otherleaver');
    }

    public function test_statistics_card_distinguishes_empty_from_failed_and_recovers(): void
    {
        $hr = $this->hr();

        $this->live($hr)->test(DepartmentEmployeeStatistics::class)
            ->assertSet('loadFailed', false)
            ->assertSee(__('general.state-empty-title'))
            ->assertDontSee(__('general.state-error-title'));

        Cache::flush();
        $this->breakQueriesOn(Contract::class);
        $component = $this->live($hr)->test(DepartmentEmployeeStatistics::class);
        $component->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee(__('general.state-empty-title'));

        $this->repair();

        $component->call('retryLoad')->assertSet('loadFailed', false);
    }

    public function test_dashboard_survives_a_failed_data_source_and_marks_only_the_cards_that_use_it(): void
    {
        $hr = $this->hr();

        $this->breakQueriesOn(Checklist::class);
        $component = $this->live($hr)->test(Index::class);

        $component->assertOk()
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'));
        $this->assertSame(2, substr_count($component->html(), 'role="alert"'), 'checklist feeds exactly two cards: delivered items and pending requests');

        $this->repair();

        $component->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertDontSee(__('general.state-error-title'));
    }

    public function test_dashboard_no_longer_computes_the_unused_today_events(): void
    {
        $component = $this->live($this->hr())->test(Index::class);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('todayEvents');

        $component->viewData('todayEvents');
    }

    public function test_notes_card_failure_is_distinct_from_empty_and_adding_a_note_updates_the_list(): void
    {
        $hr = $this->hr();

        $component = $this->live($hr)->test(EmployeeNotesCard::class)
            ->assertSet('loadFailed', false)
            ->assertSee(__('general.no-notes-1'))
            ->assertDontSee(__('general.state-error-title'));

        $component->call('createNote', 'Remember the audit', 'note')
            ->assertSee('Remember the audit')
            ->assertDontSee(__('general.no-notes-1'));
        $this->assertSame(1, EmployeeNote::count());

        $this->breakQueriesOn(EmployeeNote::class);
        $failed = $this->live($hr)->test(EmployeeNotesCard::class);
        $failed->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee(__('general.no-notes-1'));

        $this->repair();

        $failed->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertSee('Remember the audit');
    }

    /** Test mode renders #[Lazy] components as placeholders; most tests need the real card. */
    private function live($user)
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($user);
    }

    /** Make every query on $model fail the way a dead database would. */
    private function breakQueriesOn(string $model): void
    {
        $model::addGlobalScope('b3-boom', function (Builder $query) {
            throw new RuntimeException('simulated load failure');
        });
    }

    /** Undo breakQueriesOn() by resetting the booted-model registry. */
    private function repair(): void
    {
        Model::clearBootedModels();
    }

    private function hr(string $slug = 'ALP', string $code = '001', array $permissions = [])
    {
        return $this->makeUser($this->makeCity($slug, $code), 'HR-'.$slug, $permissions);
    }

    private function makeLeave(City $city, string $firstName): LeaveRequest
    {
        $employee = $this->makeEmployee($city, 'leave-'.uniqid(), ['first_name_en' => $firstName]);
        $contract = $this->makeContractFor($employee);
        $leaveType = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'سنوية', 'base_days' => 20]);
        $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $leaveType->id, 'deserved' => 160, 'used' => 0]);

        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => '2026-10-13',
            'end_at' => '2026-10-14',
            'status' => LeaveRequestStatus::Pending,
        ]);
    }
}
