<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\ScheduleMode;
use App\Livewire\Contract\ContractCreate;
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

class ContractComposerScheduleTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_fixed_mode_computes_weekly_hours_from_the_day_and_hour_range(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Fixed,
                'workHoursFrom' => '09:00',
                'workHoursTo' => '17:00', // 8h
                'workDayFrom' => 0,
                'workDayTo' => 4, // 5 days -> 40h/week
                'weeklyHours' => 40, // what the JS would have computed and sent
            ]))
            ->assertHasNoErrors();

        $this->assertSame(40, Contract::first()->weekly_hours);
    }

    public function test_fixed_mode_adds_a_second_shift_to_the_daily_hours(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Fixed,
                'workHoursFrom' => '09:00',
                'workHoursTo' => '13:00', // 4h
                'workHours2From' => '17:00',
                'workHours2To' => '21:00', // 4h -> 8h/day
                'workDayFrom' => 0,
                'workDayTo' => 4, // 5 days -> 40h/week
                'weeklyHours' => 40, // what the JS would have computed and sent
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(40, $contract->weekly_hours);
        $this->assertSame('17:00', $contract->work_hours_2_from);
        $this->assertSame('21:00', $contract->work_hours_2_to);
    }

    public function test_a_second_shift_start_without_an_end_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Fixed,
                'workDayFrom' => 0,
                'workDayTo' => 4,
                'workHours2From' => '17:00',
                // workHours2To deliberately omitted
            ]))
            ->assertHasErrors('form.work_hours_2_to');

        $this->assertSame(0, Contract::count());
    }

    public function test_flexible_mode_takes_weekly_hours_from_its_own_box(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Flexible,
                'flexHoursPerWeek' => 25,
                'flexVariesByRequirements' => true,
                'weeklyHours' => 25, // what the JS would have computed and sent
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(25, $contract->weekly_hours);
        $this->assertTrue((bool) $contract->flex_varies_by_requirements);
        $this->assertNull($contract->work_hours_from);
    }

    public function test_a_non_service_contract_with_no_explicit_weekly_hours_is_rejected(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Flexible,
                'flexVariesByRequirements' => true,
                // flexHoursPerWeek deliberately omitted -> weeklyHours = 0
            ]))
            ->assertHasErrors('form.weekly_hours');

        $this->assertSame(0, Contract::count());
    }

    public function test_a_service_contract_with_no_explicit_weekly_hours_is_accepted(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('submit', array_merge($this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Flexible,
                'flexCoordinatedWithManager' => true,
                'weeklyHours' => 0,
            ]), [
                'contractTypeId' => $fixtures['serviceType']->id,
                'type' => ContractTypeEnum::Freelance,
            ]))
            ->assertHasNoErrors();

        $contract = Contract::first();
        $this->assertSame(0, $contract->weekly_hours);
        $this->assertTrue((bool) $contract->flex_coordinated_with_manager);
    }

    /**
     * Regression coverage: a contract created before schedule_mode/day-range
     * existed has null work_day_from/to. Renewing it must not be blocked by
     * the new fields, and must not silently zero out its already-valid
     * weekly_hours before anyone has touched the schedule section.
     */
    public function test_renewing_a_pre_phase_3_contract_with_no_day_range_keeps_its_weekly_hours(): void
    {
        $fixtures = $this->makeFixtures();
        $this->ignoreLeaveBalances();

        $source = Contract::query()->forceCreate([
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'weekly_hours' => 35,
            'currency' => 1,
            'salary_per' => 1,
            'salary' => 100,
            'is_active' => true,
            // schedule_mode/work_day_from/work_day_to left at their column
            // defaults/null — exactly what a pre-Phase-3 row looks like.
        ]);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class, ['contract' => $source])
            ->call('submit', $this->basePayload($fixtures, [
                'scheduleMode' => ScheduleMode::Fixed,
                'workHoursFrom' => '09:00',
                'workHoursTo' => '17:00',
                // workDayFrom/workDayTo deliberately omitted, matching what
                // the JS sends when it fell back to initialWeeklyHours.
                'weeklyHours' => 35,
            ]))
            ->assertHasNoErrors();

        $this->assertSame(2, Contract::query()->count());
        $renewed = Contract::query()->where('id', '!=', $source->id)->first();
        $this->assertNotNull($renewed);
        $this->assertSame(35, $renewed->weekly_hours);
        $this->assertNull($renewed->work_day_from);
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
            'currency' => 1,
            'salary' => 100,
            'salaryPer' => 1,
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

        $employee = $this->makeEmployee($city, 'schedule-test-employee');

        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $serviceType = ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Specific Voluntary Service Contract', 'name_ar' => 'عقد تقديم خدمة تطوعية محددة']);

        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'position', 'employee', 'annualType', 'serviceType', 'hr');
    }
}
