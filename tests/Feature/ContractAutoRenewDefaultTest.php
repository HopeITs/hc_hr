<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractAutoRenewDefaultTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_volunteer_category_contracts_default_to_auto_renew_true(): void
    {
        $contract = $this->makeContract(1); // 'annual' per config('contracts.map_by_id')

        $this->assertTrue($contract->fresh()->auto_renew);
    }

    public function test_trial_category_contracts_default_to_auto_renew_false(): void
    {
        $contract = $this->makeContract(3); // 'trial' per config('contracts.map_by_id')

        $this->assertFalse($contract->fresh()->auto_renew);
    }

    public function test_service_category_contracts_default_to_auto_renew_true(): void
    {
        $contract = $this->makeContract(5); // 'service' per config('contracts.map_by_id')

        $this->assertTrue($contract->fresh()->auto_renew);
    }

    public function test_an_explicit_false_is_never_overridden_for_a_volunteer_contract(): void
    {
        $volunteerOptedOut = $this->makeContract(1, ['auto_renew' => false]);

        $this->assertFalse($volunteerOptedOut->fresh()->auto_renew);
    }

    public function test_a_trial_contract_never_auto_renews_even_when_asked_to(): void
    {
        $trialOptedIn = $this->makeContract(3, ['auto_renew' => true]);

        $this->assertFalse($trialOptedIn->fresh()->auto_renew);
    }

    public function test_switching_a_contract_to_trial_turns_auto_renew_off(): void
    {
        $contract = $this->makeContract(1);
        ContractType::query()->find(3) ?? ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial', 'name_ar' => 'تجريبي']);

        $contract->update(['contract_type_id' => 3]);

        $this->assertFalse($contract->fresh()->auto_renew);
    }

    private function makeContract(int $contractTypeId, array $overrides = []): Contract
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

        $employee = $this->makeEmployee($city, 'auto-renew-test-employee-'.$contractTypeId.'-'.uniqid());

        $contractType = ContractType::query()->find($contractTypeId)
            ?? ContractType::query()->forceCreate([
                'id' => $contractTypeId,
                'name_en' => 'Type '.$contractTypeId,
                'name_ar' => 'نوع '.$contractTypeId,
            ]);

        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $contractType->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ], $overrides));
    }
}
