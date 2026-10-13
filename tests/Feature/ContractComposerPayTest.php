<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\PayMode;
use App\Enums\SessionDuration;
use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractEdit;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractComposerPayTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_fixed_mode_stores_a_flat_salary(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Fixed,
                'salary' => 500,
                'currency' => 2,
                'salaryPer' => 1,
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(PayMode::Fixed, $contract->pay_mode);
        $this->assertSame('500.00', $contract->salary);
        $this->assertSame(2, $contract->currency);
        $this->assertSame(1, $contract->salary_per);
        $this->assertNull($contract->session_duration);
        $this->assertSame(0, $contract->payTiers()->count());
    }

    public function test_fixed_mode_paid_per_session_requires_a_session_duration(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Fixed,
                'salary' => 20,
                'salaryPer' => 3, // Session
                // sessionDuration deliberately omitted
            ]))
            ->assertHasErrors('form.session_duration');

        $this->assertSame(0, Contract::count());
    }

    public function test_fixed_mode_paid_per_session_with_a_duration_succeeds(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Fixed,
                'salary' => 20,
                'salaryPer' => 3, // Session
                'sessionDuration' => SessionDuration::HourAndHalf,
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(3, $contract->salary_per);
        $this->assertSame(SessionDuration::HourAndHalf, $contract->session_duration);
    }

    public function test_flexible_mode_creates_pay_tiers_in_order(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Flexible,
                'currency' => 2,
                'payTiers' => [
                    ['conditionEn' => 'If teaching 60 hours a month', 'conditionAr' => 'إذا تم التدريس 60 ساعة شهريًا', 'amount' => 200],
                    ['conditionEn' => 'If teaching 70 hours a month', 'conditionAr' => 'إذا تم التدريس 70 ساعة شهريًا', 'amount' => 300],
                ],
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(PayMode::Flexible, $contract->pay_mode);
        $this->assertNull($contract->salary);
        $this->assertSame(2, $contract->currency);

        $tiers = $contract->payTiers()->orderBy('sort_order')->get();
        $this->assertCount(2, $tiers);
        $this->assertSame('If teaching 60 hours a month', $tiers[0]->condition_en);
        $this->assertSame('200.00', $tiers[0]->amount);
        $this->assertSame('If teaching 70 hours a month', $tiers[1]->condition_en);
        $this->assertSame('300.00', $tiers[1]->amount);
    }

    public function test_flexible_mode_with_no_tiers_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Flexible,
                'payTiers' => [],
            ]))
            ->assertHasErrors('form.pay_tiers');

        $this->assertSame(0, Contract::count());
    }

    public function test_flexible_mode_tier_missing_an_amount_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Flexible,
                'payTiers' => [
                    ['conditionEn' => 'If teaching 60 hours a month', 'conditionAr' => 'إذا تم التدريس 60 ساعة شهريًا', 'amount' => ''],
                ],
            ]))
            ->assertHasErrors('form.pay_tiers.0.amount');

        $this->assertSame(0, Contract::count());
    }

    /**
     * The repeater has no stable row identity, so an edit replaces the whole
     * set wholesale rather than diffing — this is the regression that proves
     * the old tiers are actually gone, not just outnumbered by the new ones.
     */
    public function test_editing_a_contract_replaces_its_pay_tiers_wholesale(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        $contract = Contract::query()->forceCreate([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'weekly_hours' => 40,
            'currency' => 1,
            'salary_per' => 0,
            'salary' => null,
            'pay_mode' => PayMode::Flexible,
            'is_active' => true,
        ]);
        $contract->payTiers()->create(['condition_en' => 'Old condition', 'condition_ar' => 'شرط قديم', 'amount' => 100, 'sort_order' => 0]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Flexible,
                'payTiers' => [
                    ['conditionEn' => 'New condition', 'conditionAr' => 'شرط جديد', 'amount' => 250],
                ],
            ]))
            ->assertHasNoErrors();

        $tiers = $contract->fresh()->payTiers;
        $this->assertCount(1, $tiers);
        $this->assertSame('New condition', $tiers->first()->condition_en);
    }

    public function test_switching_a_contract_from_flexible_to_fixed_drops_its_pay_tiers(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        $contract = Contract::query()->forceCreate([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'weekly_hours' => 40,
            'currency' => 1,
            'salary_per' => 0,
            'salary' => null,
            'pay_mode' => PayMode::Flexible,
            'is_active' => true,
        ]);
        $contract->payTiers()->create(['condition_en' => 'Old condition', 'condition_ar' => 'شرط قديم', 'amount' => 100, 'sort_order' => 0]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->basePayload($fixtures, [
                'payMode' => PayMode::Fixed,
                'salary' => 400,
                'salaryPer' => 1,
            ]))
            ->assertHasNoErrors();

        $fresh = $contract->fresh();
        $this->assertSame(PayMode::Fixed, $fresh->pay_mode);
        $this->assertSame('400.00', $fresh->salary);
        $this->assertSame(0, $fresh->payTiers()->count());
    }

    private function ignoreLeaveBalances(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->andReturnNull();
    }

    private function basePayload(array $fixtures, array $overrides = []): array
    {
        return array_merge([
            'cityId' => $fixtures['city']->id,
            'employeeId' => $fixtures['employee']->id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $fixtures['position']->id,
            'contractTypeId' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'startDate' => now()->addMonth()->startOfMonth()->toDateString(),
            'endDate' => now()->endOfYear()->toDateString(),
            'weeklyHours' => 40,
            'currency' => 1,
        ], $overrides);
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');

        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);

        $position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $program->id,
            'title_en' => 'Program Officer',
            'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        $employee = $this->makeEmployee($city, 'pay-test-employee');

        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.update']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'position', 'employee', 'annualType', 'hr');
    }
}
