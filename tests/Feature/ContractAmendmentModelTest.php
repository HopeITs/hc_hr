<?php

namespace Tests\Feature;

use App\Enums\ContractAmendmentStatus;
use App\Enums\ContractType as ContractTypeEnum;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAmendmentPayTier;
use App\Support\Status\StatusTone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Phase 1 of contract amendments (ملحق عقد): the tables, the model and the
 * status enum. Applying an amendment to its contract comes in Phase 2.
 */
class ContractAmendmentModelTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pay_figures_are_stored_encrypted_and_read_back(): void
    {
        $amendment = $this->makeAmendment($this->makeContract(), salaryAfter: 2750);

        $this->addTier($amendment, ContractAmendmentPayTier::SIDE_AFTER, 'Per session', 'لكل جلسة', 35);

        $row = DB::table('contract_amendments')->find($amendment->id);
        $this->assertStringNotContainsString('1500', (string) $row->salary_before);
        $this->assertStringNotContainsString('2750', (string) $row->salary_after);
        $this->assertStringNotContainsString('35', (string) DB::table('contract_amendment_pay_tiers')->value('amount'));

        $fresh = $amendment->fresh();
        $this->assertSame('1500.00', $fresh->salary_before);
        $this->assertSame('2750.00', $fresh->salary_after);
        $this->assertSame('35.00', $fresh->payTiersAfter->first()->amount);
    }

    public function test_snapshot_holds_the_amendable_terms_and_nothing_else(): void
    {
        $snapshot = ContractAmendment::snapshotOf($this->makeContract());

        $this->assertSame(ContractAmendment::AMENDABLE_FIELDS, array_keys($snapshot));
        $this->assertSame(40, $snapshot['weekly_hours']);

        foreach (['position_id', 'contract_type_id', 'start_date', 'end_date', 'salary'] as $field) {
            $this->assertArrayNotHasKey($field, $snapshot);
        }
    }

    public function test_changed_fields_lists_only_what_moved(): void
    {
        $amendment = $this->makeAmendment($this->makeContract(), [
            'weekly_hours' => 30,
            'type' => ContractTypeEnum::FullTime,
        ], salaryAfter: 2000);

        $this->assertSame(['type', 'weekly_hours', 'salary'], $amendment->changedFields());
        $this->assertTrue($amendment->touchesPay());
    }

    public function test_equivalent_values_are_not_changes(): void
    {
        $contract = $this->makeContract(['work_hours_from' => '09:00:00', 'show_day_range' => false]);

        $amendment = $this->makeAmendment($contract, [
            'work_hours_from' => '09:00',
            'weekly_hours' => '40',
            'show_day_range' => '0',
            'flex_custom_en' => '',
        ]);

        // Same salary, re-encrypted with a fresh IV: still no change.
        $this->assertSame([], $amendment->changedFields());
        $this->assertFalse($amendment->touchesPay());
    }

    public function test_pay_tier_changes_are_detected(): void
    {
        $amendment = $this->makeAmendment($this->makeContract());
        $this->addTier($amendment, ContractAmendmentPayTier::SIDE_BEFORE, 'Per session', 'لكل جلسة', 30);
        $this->addTier($amendment, ContractAmendmentPayTier::SIDE_AFTER, 'Per session', 'لكل جلسة', 30);

        $this->assertSame([], $amendment->fresh()->changedFields());

        $amendment->payTiersAfter()->first()->update(['amount' => 40]);

        $this->assertSame(['pay_tiers'], $amendment->fresh()->changedFields());
        $this->assertTrue($amendment->fresh()->touchesPay());
    }

    public function test_schedule_only_change_does_not_touch_pay(): void
    {
        $amendment = $this->makeAmendment($this->makeContract(), ['work_hours_to' => '15:00:00']);

        $this->assertSame(['work_hours_to'], $amendment->changedFields());
        $this->assertFalse($amendment->touchesPay());
    }

    public function test_daily_hours_before_and_after(): void
    {
        $amendment = $this->makeAmendment($this->makeContract(['weekly_hours' => 20]), ['weekly_hours' => 30]);

        $this->assertSame(4, $amendment->dailyHoursBefore());
        $this->assertSame(6, $amendment->dailyHoursAfter());
    }

    public function test_only_a_contract_in_force_can_be_amended(): void
    {
        $this->assertTrue($this->makeContract()->canBeAmended());

        $this->assertFalse($this->makeContract(['type' => ContractTypeEnum::Draft])->canBeAmended());
        $this->assertFalse($this->makeContract(['is_active' => false])->canBeAmended());
        $this->assertFalse($this->makeContract(['terminated_at' => '2026-10-01'])->canBeAmended());
        $this->assertFalse($this->makeContract(['start_date' => '2026-12-01', 'end_date' => '2027-05-31'])->canBeAmended());
        $this->assertFalse($this->makeContract(['start_date' => '2025-11-01', 'end_date' => '2026-10-31'])->canBeAmended());
    }

    public function test_amendments_are_numbered_per_contract(): void
    {
        $contract = $this->makeContract();
        $other = $this->makeContract();

        $this->assertSame(1, $contract->nextAmendmentNumber());

        $this->makeAmendment($contract);
        $this->makeAmendment($contract);
        $this->makeAmendment($other);

        $this->assertSame(3, $contract->nextAmendmentNumber());
        $this->assertSame(2, $other->nextAmendmentNumber());

        $this->expectException(QueryException::class);
        $this->makeAmendment($contract, number: 1);
    }

    public function test_amendments_are_ordered_by_effective_date(): void
    {
        $contract = $this->makeContract();
        $this->makeAmendment($contract, effectiveFrom: '2026-09-01');
        $this->makeAmendment($contract, effectiveFrom: '2026-05-01');

        $this->assertSame(['2026-05-01', '2026-09-01'], $contract->amendments->map(fn ($a) => $a->effective_from->toDateString())->all());
    }

    public function test_has_applied_amendments_ignores_drafts_and_pending(): void
    {
        $contract = $this->makeContract();
        $this->makeAmendment($contract, status: ContractAmendmentStatus::Draft);
        $this->makeAmendment($contract, status: ContractAmendmentStatus::Approved);

        $this->assertFalse($contract->hasAppliedAmendments());

        $this->makeAmendment($contract, status: ContractAmendmentStatus::Applied);

        $this->assertTrue($contract->hasAppliedAmendments());
    }

    public function test_due_on_picks_approved_amendments_whose_date_has_come(): void
    {
        $contract = $this->makeContract();
        $due = $this->makeAmendment($contract, effectiveFrom: '2026-11-05', status: ContractAmendmentStatus::Approved);
        $this->makeAmendment($contract, effectiveFrom: '2026-11-06', status: ContractAmendmentStatus::Approved);
        $this->makeAmendment($contract, effectiveFrom: '2026-10-01', status: ContractAmendmentStatus::Draft);
        $this->makeAmendment($contract, effectiveFrom: '2026-10-01', status: ContractAmendmentStatus::Applied);

        $this->assertSame([$due->id], ContractAmendment::query()->dueOn(now())->pluck('id')->all());
    }

    public function test_deleting_a_contract_removes_its_amendments_and_tiers(): void
    {
        $contract = $this->makeContract();
        $amendment = $this->makeAmendment($contract);
        $this->addTier($amendment, ContractAmendmentPayTier::SIDE_AFTER, 'Per session', 'لكل جلسة', 30);

        $contract->payTiers()->delete();
        DB::table('contracts')->where('id', $contract->id)->delete();

        $this->assertSame(0, ContractAmendment::query()->count());
        $this->assertSame(0, ContractAmendmentPayTier::query()->count());
    }

    public function test_activity_log_never_records_figures(): void
    {
        $amendment = $this->makeAmendment($this->makeContract(), salaryAfter: 98765);
        $amendment->update(['status' => ContractAmendmentStatus::Approved]);

        $logged = Activity::query()->where('subject_type', ContractAmendment::class)->get();

        $this->assertNotEmpty($logged);
        foreach ($logged as $activity) {
            $this->assertStringNotContainsString('98765', json_encode($activity->properties));
            $this->assertStringNotContainsString('terms_', json_encode($activity->properties));
        }
    }

    public function test_status_enum_shapes(): void
    {
        $draft = ContractAmendmentStatus::of(ContractAmendmentStatus::Draft);
        $approved = ContractAmendmentStatus::of(ContractAmendmentStatus::Approved);
        $applied = ContractAmendmentStatus::of(ContractAmendmentStatus::Applied);

        $this->assertSame(StatusTone::Attn, $draft->tone());
        $this->assertSame(StatusTone::Wait, $approved->tone());
        $this->assertSame(StatusTone::Done, $applied->tone());

        $this->assertSame('pen', $draft->icon());
        $this->assertSame('clock', $approved->icon());
        $this->assertSame('check', $applied->icon());

        app()->setLocale('ar');
        $this->assertSame('ساري', $applied->label());
        app()->setLocale('en');
        $this->assertSame('Draft', $draft->label());

        // An unknown stored value reads as a draft rather than throwing.
        $this->assertTrue(ContractAmendmentStatus::of(99)->is(ContractAmendmentStatus::Draft));
    }

    private function makeContract(array $overrides = []): Contract
    {
        $city = City::query()->where('slug', 'ALP')->first() ?? $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'amend-'.uniqid());

        return $this->makeContractFor($employee, array_merge(['type' => ContractTypeEnum::PartTime], $overrides))->refresh();
    }

    private function makeAmendment(
        Contract $contract,
        array $after = [],
        int|float|null $salaryAfter = null,
        string $effectiveFrom = '2026-11-01',
        int $status = ContractAmendmentStatus::Draft,
        ?int $number = null,
    ): ContractAmendment {
        $before = ContractAmendment::snapshotOf($contract);

        return ContractAmendment::query()->create([
            'contract_id' => $contract->id,
            'number' => $number ?? $contract->nextAmendmentNumber(),
            'status' => $status,
            'effective_from' => $effectiveFrom,
            'terms_before' => $before,
            'terms_after' => array_merge($before, $after),
            'salary_before' => $contract->salary,
            'salary_after' => $salaryAfter ?? $contract->salary,
        ]);
    }

    private function addTier(ContractAmendment $amendment, int $side, string $en, string $ar, int|float $amount): void
    {
        $amendment->payTiers()->create([
            'side' => $side,
            'condition_en' => $en,
            'condition_ar' => $ar,
            'amount' => $amount,
            'sort_order' => 0,
        ]);
    }
}
