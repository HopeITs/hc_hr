<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Services\ContractAmendmentDescriber;
use App\Services\ContractAmendmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * What happens to amendments when their contract ends, what
 * contracts:amendments-check finds after a deletion outside the app, and how
 * the card words a change.
 */
class ContractAmendmentLifecycleTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private ContractAmendmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->service = app(ContractAmendmentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── the contract ends ───────────────────────────────────────────

    public function test_terminating_the_contract_cancels_an_amendment_it_will_not_reach(): void
    {
        $contract = $this->contract();
        $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30, 'salary' => 250], '2026-12-01'));

        $contract->update(['terminated_at' => '2026-11-20', 'is_active' => false]);

        $this->assertSame(0, ContractAmendment::query()->count());
        $this->assertSame(20, $contract->refresh()->weekly_hours);
    }

    public function test_a_termination_after_the_effective_date_leaves_the_amendment_to_apply(): void
    {
        $contract = $this->contract();
        $amendment = $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-12-01'));

        $contract->update(['terminated_at' => '2026-12-15', 'is_active' => false]);
        $this->assertTrue($amendment->refresh()->isApproved());

        $this->service->applyDue(Carbon::parse('2026-12-01'));
        $this->assertTrue($amendment->refresh()->isApplied());
        $this->assertSame(30, $contract->refresh()->weekly_hours);
    }

    public function test_shortening_the_term_before_the_effective_date_cancels_it(): void
    {
        $contract = $this->contract();
        $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-12-01'));

        $contract->update(['end_date' => '2026-11-30']);

        $this->assertSame(0, ContractAmendment::query()->count());
    }

    public function test_the_daily_run_cancels_one_whose_contract_ended_by_other_means(): void
    {
        $contract = $this->contract();
        $amendment = $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-12-01'));
        // A termination written without the model (an import, a script).
        DB::table('contracts')->where('id', $contract->id)->update(['terminated_at' => '2026-11-20', 'is_active' => false]);

        $result = $this->service->applyDue(Carbon::parse('2026-12-01'));

        $this->assertSame([$amendment->id], $result['cancelled']->pluck('id')->all());
        $this->assertCount(0, $result['applied']);
        $this->assertSame(20, $contract->refresh()->weekly_hours);
    }

    public function test_an_amendment_in_effect_stays_when_the_contract_is_terminated(): void
    {
        $contract = $this->contract();
        $amendment = $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-05-01'));

        $contract->refresh()->update(['terminated_at' => '2026-11-20', 'is_active' => false]);

        $this->assertTrue($amendment->refresh()->isApplied());
        $this->assertSame(30, $contract->refresh()->weekly_hours);
    }

    public function test_a_draft_on_an_ended_contract_can_only_be_deleted(): void
    {
        $contract = $this->contract();
        $draft = $this->service->draft($contract, ['weekly_hours' => 30], '2026-12-01');
        $contract->update(['terminated_at' => '2026-11-20', 'is_active' => false]);
        $hr = $this->makeUser($contract->employee->city, 'HR-ALP', ['contracts.view', 'contracts.amend']);

        $this->assertFalse($hr->can('decideAmendment', $draft->refresh()));
        $this->assertTrue($hr->can('discardAmendment', $draft));
    }

    // ── contracts:amendments-check ──────────────────────────────────

    public function test_the_check_passes_on_a_sound_history(): void
    {
        $contract = $this->contract();
        $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30, 'salary' => 250], '2026-05-01'));
        $this->service->approve($this->service->draft($contract->refresh(), ['weekly_hours' => 35], '2026-09-01'));

        $this->artisan('contracts:amendments-check')->assertSuccessful();
    }

    public function test_the_check_finds_the_newest_deleted_outside_the_app(): void
    {
        $contract = $this->contract();
        $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-05-01'));
        $newest = $this->service->approve($this->service->draft($contract->refresh(), ['weekly_hours' => 35], '2026-09-01'));
        DB::table('contract_amendments')->where('id', $newest->id)->delete();

        $this->artisan('contracts:amendments-check')
            ->expectsOutputToContain('not-current')
            ->assertFailed();
    }

    public function test_the_check_finds_the_oldest_deleted_outside_the_app(): void
    {
        $contract = $this->contract();
        $oldest = $this->service->approve($this->service->draft($contract, ['weekly_hours' => 30], '2026-05-01'));
        $this->service->approve($this->service->draft($contract->refresh(), ['weekly_hours' => 35], '2026-09-01'));
        DB::table('contract_amendments')->where('id', $oldest->id)->delete();

        $this->artisan('contracts:amendments-check')
            ->expectsOutputToContain('amendment 1 is gone')
            ->assertFailed();
    }

    // ── the card's wording ──────────────────────────────────────────

    public function test_a_term_that_had_no_value_has_no_before(): void
    {
        $contract = $this->contract(['work_day_from' => null, 'work_day_to' => null]);
        $amendment = $this->service->draft($contract, ['work_day_from' => 1, 'work_day_to' => 3], '2026-12-01');

        [[$label, $from, $to]] = app(ContractAmendmentDescriber::class)->lines($amendment, true);

        $this->assertSame(__('general.work-days'), $label);
        $this->assertSame('', $from);
        $this->assertSame('Monday – Wednesday', $to);
    }

    public function test_print_toggles_are_not_named_as_changes(): void
    {
        $amendment = $this->service->draft($this->contract(), ['weekly_hours' => 30, 'show_hours_per_week' => true, 'show_day_range' => true], '2026-12-01');

        $labels = array_column(app(ContractAmendmentDescriber::class)->lines($amendment, true), 0);

        $this->assertSame([__('general.weekly-hours')], $labels);
    }

    public function test_moving_to_a_flexible_schedule_names_the_flexible_terms_only(): void
    {
        $amendment = $this->service->draft($this->contract(), [
            'schedule_mode' => 2, 'work_hours_from' => null, 'work_hours_to' => null, 'work_day_from' => null, 'work_day_to' => null,
            'flex_hours_per_month' => 60, 'flex_coordinated_with_manager' => true,
        ], '2026-12-01');

        $lines = app(ContractAmendmentDescriber::class)->lines($amendment, true);

        $this->assertSame([__('general.composer.schedule-mode'), __('contracts.amendment.flexible-terms')], array_column($lines, 0));
        $this->assertSame('', $lines[1][1]);
        $this->assertSame('60 hours a month · coordinated with the manager', $lines[1][2]);
    }

    public function test_a_time_range_keeps_its_order_in_arabic(): void
    {
        $amendment = $this->service->draft($this->contract(), ['work_hours_to' => '15:00'], '2026-12-01');

        [[, $from, $to]] = app(ContractAmendmentDescriber::class)->lines($amendment, true);

        // Isolated left-to-right, so an Arabic line does not print it as 15:00–09:00.
        $this->assertSame("\u{2066}09:00–15:00\u{2069}", $to);
        $this->assertSame("\u{2066}09:00–13:00\u{2069}", $from);
    }

    public function test_money_drops_empty_decimals_and_says_the_period(): void
    {
        $amendment = $this->service->draft($this->contract(), ['salary' => 250], '2026-12-01');

        $this->assertSame([__('general.salary'), '150 USD per month', '250 USD per month'], app(ContractAmendmentDescriber::class)->lines($amendment, true)[0]);
        $this->assertSame([], app(ContractAmendmentDescriber::class)->lines($amendment, false));
    }

    private function contract(array $overrides = []): Contract
    {
        $city = City::query()->where('slug', 'ALP')->first() ?? $this->makeCity('ALP', '001');

        return $this->makeContractFor($this->makeEmployee($city, 'life-'.uniqid()), array_merge([
            'type' => ContractTypeEnum::PartTime, 'schedule_mode' => 1, 'work_day_from' => 0, 'work_day_to' => 4,
            'work_hours_from' => '09:00:00', 'work_hours_to' => '13:00:00', 'weekly_hours' => 20,
            'salary' => 150, 'currency' => Currency::USD, 'salary_per' => 1, 'pay_mode' => 1,
            'show_hours_per_week' => false, 'show_day_range' => false,
        ], $overrides))->refresh();
    }
}
