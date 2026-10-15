<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveTypeCases;
use App\Models\AnnualLeaveRule;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class LeaveBalanceCarryOverTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const ANNUAL = 1;

    private const TRIAL = 3;

    private Employee $employee;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        AnnualLeaveRule::create(['days' => 12, 'day_per_month' => 1, 'years_of_volunteering' => 0]);
        ContractType::query()->forceCreate(['id' => self::ANNUAL, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوع']);
        ContractType::query()->forceCreate(['id' => self::TRIAL, 'name_en' => 'Trial Volunteer Contract', 'name_ar' => 'عقد تطوع تجريبي']);

        // base_days = 12 keeps the gender/marital-specific branch empty.
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }

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

        $this->position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG'])->id,
            'job_level_id' => JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2])->id,
            'program_id' => Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true])->id,
            'title_en' => 'Program Officer',
            'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        $this->employee = $this->makeEmployee($city, 'leave-carry-over-test');
    }

    public function test_a_position_change_keeps_the_years_entitlement_from_the_joining_date(): void
    {
        // Joined in March on a trial (3 days), then an annual contract to the
        // end of the year (7 days) that is ended after June to move to a new
        // position on a fresh trial Jul–Oct.
        $this->contract(self::TRIAL, '2026-03-01', '2026-05-31');
        $annual = $this->contract(self::ANNUAL, '2026-06-01', '2026-12-31');
        $this->assertSame(10.0, $this->annualDays($annual));

        $annual->update(['terminated_at' => '2026-06-30', 'is_active' => false]);

        $newTrial = $this->contract(self::TRIAL, '2026-07-01', '2026-10-31');

        // March to December: the 10 days move across untouched.
        $this->assertSame(10.0, $this->annualDays($newTrial));
    }

    public function test_leave_already_taken_is_not_carried_over(): void
    {
        $this->contract(self::TRIAL, '2026-03-01', '2026-05-31');
        $annual = $this->contract(self::ANNUAL, '2026-06-01', '2026-12-31');
        $annual->update(['terminated_at' => '2026-08-31', 'is_active' => false]);

        LeaveBalance::where('contract_id', $annual->id)
            ->where('type_id', LeaveTypeCases::Annual)
            ->update(['used' => 2 * 8]);

        $next = $this->contract(self::TRIAL, '2026-09-01', '2026-10-31');

        $this->assertSame(8.0, $this->annualDays($next));
    }

    public function test_a_new_position_running_past_the_old_contract_prices_the_extra_months(): void
    {
        $old = $this->contract(self::ANNUAL, '2026-06-01', '2026-09-30');
        $old->update(['terminated_at' => '2026-07-31', 'is_active' => false]);

        $new = $this->contract(self::ANNUAL, '2026-08-01', '2026-12-31');

        // 4 days to the end of September carried, plus October–December.
        $this->assertSame(7.0, $this->annualDays($new));
    }

    public function test_someone_who_left_and_came_back_only_brings_what_they_earned(): void
    {
        $old = $this->contract(self::ANNUAL, '2026-01-01', '2026-12-31');
        $old->update(['terminated_at' => '2026-04-30', 'is_active' => false]);

        $rehired = $this->contract(self::ANNUAL, '2026-09-01', '2026-12-31');

        // Jan–Apr earned (4), not the 8 months after leaving; plus Sep–Dec.
        $this->assertSame(8.0, $this->annualDays($rehired));
    }

    public function test_carried_over_leave_is_counted_in_days_not_hours(): void
    {
        $this->contract(self::TRIAL, '2026-03-01', '2026-05-31', weeklyHours: 40);
        $partTime = $this->contract(self::ANNUAL, '2026-06-01', '2026-11-30', weeklyHours: 20);

        // 3 trial days on an 8-hour day are 3 days on a 4-hour day as well.
        $this->assertSame(9.0, $this->annualDays($partTime));
    }

    public function test_nothing_is_carried_over_from_a_previous_year(): void
    {
        $this->contract(self::TRIAL, '2025-09-01', '2025-11-30');
        $annual = $this->contract(self::ANNUAL, '2026-01-01', '2026-06-30');

        $this->assertSame(6.0, $this->annualDays($annual));
    }

    private function contract(int $contractTypeId, string $start, string $end, int $weeklyHours = 40): Contract
    {
        $contract = Contract::create([
            'employee_id' => $this->employee->id,
            'position_id' => $this->position->id,
            'contract_type_id' => $contractTypeId,
            'type' => ContractTypeEnum::FullTime,
            'is_active' => true,
            'start_date' => $start,
            'end_date' => $end,
            'weekly_hours' => $weeklyHours,
            'currency' => 1,
            'salary_per' => 1,
        ]);

        app(LeaveBalanceService::class)->create($contract);

        return $contract;
    }

    private function annualDays(Contract $contract): float
    {
        $deserved = LeaveBalance::where('contract_id', $contract->id)
            ->where('type_id', LeaveTypeCases::Annual)
            ->value('deserved');

        return round($deserved / $contract->work_hour_per_day, 1);
    }
}
