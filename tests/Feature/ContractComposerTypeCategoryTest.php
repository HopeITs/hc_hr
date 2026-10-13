<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractCreate;
use App\Models\Center;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractComposerTypeCategoryTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_a_service_category_contract_rejects_full_time(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, $fixtures['serviceType']->id, ContractTypeEnum::FullTime))
            ->assertHasErrors('form.type');
    }

    public function test_a_service_category_contract_accepts_freelance(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, $fixtures['serviceType']->id, ContractTypeEnum::Freelance))
            ->assertHasNoErrors('form.type');
    }

    public function test_a_volunteer_category_contract_rejects_freelance(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, $fixtures['annualType']->id, ContractTypeEnum::Freelance))
            ->assertHasErrors('form.type');
    }

    public function test_a_volunteer_category_contract_accepts_full_time(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, $fixtures['annualType']->id, ContractTypeEnum::FullTime))
            ->assertHasNoErrors('form.type');
    }

    private function basePayload(array $fixtures, int $contractTypeId, int $type): array
    {
        return [
            'cityId' => $fixtures['city']->id,
            'employeeId' => $fixtures['employee']->id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $fixtures['position']->id,
            'contractTypeId' => $contractTypeId,
            'type' => $type,
        ];
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

        $employee = $this->makeEmployee($city, 'type-category-test-employee');

        // Ids match config('contracts.map_by_id'): 1 => annual, 5 => service.
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $serviceType = ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Specific Voluntary Service Contract', 'name_ar' => 'عقد تقديم خدمة تطوعية محددة']);

        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'position', 'employee', 'annualType', 'serviceType', 'hr');
    }
}
