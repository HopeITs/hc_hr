<?php

namespace Tests\Feature;

use App\Enums\ContractAmendmentStatus;
use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Exceptions\ContractAmendmentCannotApply;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractType;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\ContractAmendmentService;
use App\Services\LeaveBalanceService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Phase 2 of contract amendments: draft → approve → apply, the leave balance
 * conversion and the daily run. The scenario is the sheet that started it: a
 * part-time volunteer on 4 hours a day moved to 6 hours with a new stipend,
 * effective from 01/05/2026 and signed months later.
 */
class ContractAmendmentServiceTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private ContractAmendmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->service = app(ContractAmendmentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_draft_records_before_and_after_without_touching_the_contract(): void
    {
        $contract = $this->makeContract();

        $amendment = $this->service->draft($contract, $this->mayaChanges(), '2026-05-01', 'Moved to full days');

        $this->assertTrue($amendment->isDraft());
        $this->assertSame(1, $amendment->number);
        $this->assertSame(20, $amendment->terms_before['weekly_hours']);
        $this->assertSame(30, $amendment->terms_after['weekly_hours']);
        $this->assertSame('150.00', $amendment->salary_before);
        $this->assertSame('250.00', $amendment->salary_after);
        $this->assertSame(['type', 'weekly_hours', 'work_hours_to', 'salary'], $amendment->changedFields());

        $contract->refresh();
        $this->assertSame(20, $contract->weekly_hours);
        $this->assertSame('150.00', $contract->salary);
    }

    public function test_draft_ignores_what_an_amendment_may_not_change(): void
    {
        $contract = $this->makeContract();

        $this->expectValidationError('terms', fn () => $this->service->draft($contract, [
            'position_id' => 999,
            'end_date' => '2027-12-31',
            'contract_type_id' => 5,
        ], '2026-11-01'));
    }

    public function test_draft_refuses_a_contract_not_in_force(): void
    {
        $this->expectValidationError('contract', fn () => $this->service->draft(
            $this->makeContract(['terminated_at' => '2026-10-01']),
            $this->mayaChanges(),
            '2026-05-01',
        ));
    }

    public function test_draft_refuses_a_date_outside_the_term(): void
    {
        $contract = $this->makeContract();

        $this->expectValidationError('effective_from', fn () => $this->service->draft($contract, $this->mayaChanges(), '2025-12-31'));
        $this->expectValidationError('effective_from', fn () => $this->service->draft($contract, $this->mayaChanges(), '2027-01-01'));
    }

    public function test_draft_refuses_a_second_open_amendment(): void
    {
        $contract = $this->makeContract();
        $this->service->draft($contract, $this->mayaChanges(), '2026-05-01');

        $this->expectValidationError('contract', fn () => $this->service->draft($contract, ['weekly_hours' => 25], '2026-06-01'));
    }

    public function test_draft_refuses_a_date_before_the_last_amendment_in_effect(): void
    {
        $contract = $this->makeContract();
        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $this->expectValidationError('effective_from', fn () => $this->service->draft($contract->refresh(), ['weekly_hours' => 25], '2026-04-01'));
    }

    public function test_draft_refuses_turning_a_contract_into_a_draft(): void
    {
        $this->expectValidationError('type', fn () => $this->service->draft(
            $this->makeContract(),
            ['type' => ContractTypeEnum::Draft],
            '2026-11-01',
        ));
    }

    public function test_draft_refuses_a_change_that_changes_nothing(): void
    {
        $contract = $this->makeContract();

        $this->expectValidationError('terms', fn () => $this->service->draft($contract, [
            'weekly_hours' => '20',
            'work_hours_from' => '09:00',
            'salary' => 150,
        ], '2026-11-01'));

        $this->assertSame(0, ContractAmendment::query()->count());
    }

    public function test_a_retroactive_amendment_applies_on_approval(): void
    {
        $contract = $this->makeContract();
        $amendment = $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $this->assertTrue($amendment->isApplied());
        $this->assertNotNull($amendment->approved_at);
        $this->assertNotNull($amendment->applied_at);

        $contract->refresh();
        $this->assertSame(30, $contract->weekly_hours);
        $this->assertSame(ContractTypeEnum::FullTime, $contract->type);
        $this->assertSame('15:00:00', $contract->work_hours_to);
        $this->assertSame('250.00', $contract->salary);
        $this->assertTrue($contract->hasAppliedAmendments());
    }

    public function test_a_future_amendment_waits_for_its_date(): void
    {
        $contract = $this->makeContract();
        $amendment = $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-12-01'));

        $this->assertTrue($amendment->isApproved());
        $this->assertSame(20, $contract->refresh()->weekly_hours);

        $this->assertCount(0, $this->service->applyDue(Carbon::parse('2026-11-30'))['applied']);

        Carbon::setTestNow('2026-12-01 21:25:00');
        $result = $this->service->applyDue();

        $this->assertSame([$amendment->id], $result['applied']->pluck('id')->all());
        $this->assertSame(30, $contract->refresh()->weekly_hours);
    }

    public function test_leave_balances_keep_the_days_left(): void
    {
        $contract = $this->makeContract();
        // 14 days on a 4-hour day, 4 days (16 hours) used: 10 days left.
        $balance = $this->balanceOn($contract, deserved: 56, used: 16);

        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $balance->refresh();
        // Used stays as deducted; the 10 days left are 60 hours on a 6-hour day.
        $this->assertSame(16.0, (float) $balance->used);
        $this->assertSame(76, (int) $balance->deserved);
        $this->assertSame(10.0, ($balance->deserved - $balance->used) / 6);
    }

    public function test_leave_balances_are_untouched_when_the_working_day_is_the_same(): void
    {
        $contract = $this->makeContract();
        $balance = $this->balanceOn($contract, deserved: 56, used: 16);

        $this->service->approve($this->service->draft($contract, ['salary' => 300, 'work_hours_from' => '10:00'], '2026-05-01'));

        $this->assertSame(56, (int) $balance->refresh()->deserved);
    }

    public function test_a_service_contract_has_no_balances_to_convert(): void
    {
        ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Service Contract', 'name_ar' => 'عقد خدمة']);
        $contract = $this->makeContract(['contract_type_id' => 5]);
        $balance = $this->balanceOn($contract, deserved: 56, used: 16);

        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $this->assertSame(56, (int) $balance->refresh()->deserved);
    }

    public function test_recalculation_leaves_a_converted_contract_alone(): void
    {
        $contract = $this->makeContract();
        $balance = $this->balanceOn($contract, deserved: 56, used: 16, annual: true);

        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));
        app(LeaveBalanceService::class)->update($contract->refresh());

        $this->assertSame(76, (int) $balance->refresh()->deserved);
    }

    public function test_pay_tiers_are_replaced_on_apply(): void
    {
        $contract = $this->makeContract();
        $contract->payTiers()->create(['condition_en' => 'Per session', 'condition_ar' => 'لكل جلسة', 'amount' => 30, 'sort_order' => 0]);

        $amendment = $this->service->draft($contract, ['pay_tiers' => [
            ['condition_en' => 'Per session', 'condition_ar' => 'لكل جلسة', 'amount' => 40],
            ['condition_en' => 'Per workshop', 'condition_ar' => 'لكل ورشة', 'amount' => 90],
        ]], '2026-11-01');

        $this->assertSame(['pay_tiers'], $amendment->changedFields());

        $this->service->approve($amendment);

        $tiers = $contract->payTiers()->get();
        $this->assertSame(['40.00', '90.00'], $tiers->pluck('amount')->all());
        $this->assertSame('30.00', $amendment->payTiersBefore()->first()->amount);
    }

    public function test_a_contract_changed_after_drafting_is_not_overwritten(): void
    {
        $contract = $this->makeContract();
        $amendment = $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-12-01'));

        $contract->refresh()->update(['weekly_hours' => 25]);

        try {
            $this->service->apply($amendment);
            $this->fail('An amendment drafted against other terms was applied.');
        } catch (ContractAmendmentCannotApply) {
        }

        $this->assertSame(25, $contract->refresh()->weekly_hours);
        $this->assertTrue($amendment->refresh()->isApproved());
    }

    public function test_the_daily_run_skips_a_failing_amendment_and_applies_the_rest(): void
    {
        $broken = $this->makeContract();
        $fine = $this->makeContract();
        $a = $this->service->approve($this->service->draft($broken, $this->mayaChanges(), '2026-12-01'));
        $b = $this->service->approve($this->service->draft($fine, $this->mayaChanges(), '2026-12-01'));
        $broken->refresh()->update(['weekly_hours' => 25]);

        $result = $this->service->applyDue(Carbon::parse('2026-12-01'));

        $this->assertSame([$b->id], $result['applied']->pluck('id')->all());
        $this->assertSame([$a->id], $result['failed']->pluck('id')->all());
    }

    public function test_an_unreadable_figure_is_never_written_over_the_contract(): void
    {
        $contract = $this->makeContract();
        $amendment = $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-12-01'));

        // What a database copy without the salary key looks like.
        DB::table('contract_amendments')->where('id', $amendment->id)
            ->update(['salary_after' => (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('250.00')]);

        $this->expectException(ContractAmendmentCannotApply::class);

        try {
            $this->service->apply($amendment->refresh());
        } finally {
            $this->assertSame('150.00', $contract->refresh()->salary);
            $this->assertSame(20, $contract->weekly_hours);
        }
    }

    public function test_applying_keeps_the_seat_count_and_auto_renew(): void
    {
        $contract = $this->makeContract(['auto_renew' => true]);
        $seats = $contract->position->refresh()->exist_emp;

        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $this->assertSame($seats, $contract->position->refresh()->exist_emp);
        $this->assertTrue($contract->refresh()->auto_renew);
    }

    public function test_a_draft_can_be_discarded_but_an_applied_amendment_cannot(): void
    {
        $contract = $this->makeContract();
        $this->service->discard($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));
        $this->assertSame(0, ContractAmendment::query()->count());

        $applied = $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-05-01'));

        $this->expectValidationError('amendment', fn () => $this->service->discard($applied));
    }

    public function test_the_command_applies_what_is_due(): void
    {
        $contract = $this->makeContract();
        $this->service->approve($this->service->draft($contract, $this->mayaChanges(), '2026-12-01'));
        Carbon::setTestNow('2026-12-01 21:25:00');

        $this->artisan('contracts:apply-amendments')
            ->expectsOutput('Applied 1 amendment(s).')
            ->assertSuccessful();

        $this->assertSame(30, $contract->refresh()->weekly_hours);
        $this->assertSame(ContractAmendmentStatus::Applied, ContractAmendment::query()->value('status'));
    }

    /** The sheet: 6 hours a day, 9 to 3, a new stipend. */
    private function mayaChanges(): array
    {
        return [
            'type' => ContractTypeEnum::FullTime,
            'weekly_hours' => 30,
            'work_hours_from' => '09:00',
            'work_hours_to' => '15:00',
            'salary' => 250,
        ];
    }

    private function makeContract(array $overrides = []): Contract
    {
        $city = City::query()->where('slug', 'ALP')->first() ?? $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'amend-'.uniqid());

        return $this->makeContractFor($employee, array_merge([
            'type' => ContractTypeEnum::PartTime,
            'weekly_hours' => 20,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '13:00:00',
            'salary' => 150,
            'currency' => Currency::USD,
        ], $overrides))->refresh();
    }

    private function balanceOn(Contract $contract, int $deserved, float $used, bool $annual = false): LeaveBalance
    {
        $typeId = $annual
            ? LeaveType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 14])->id
            : LeaveType::query()->firstOrCreate(['name_en' => 'Special'], ['name_ar' => 'خاصة', 'base_days' => 3])->id;

        return LeaveBalance::query()->create([
            'contract_id' => $contract->id,
            'type_id' => $typeId,
            'deserved' => $deserved,
            'used' => $used,
        ]);
    }

    private function expectValidationError(string $key, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on [{$key}].");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
}
