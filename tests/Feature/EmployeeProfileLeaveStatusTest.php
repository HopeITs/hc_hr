<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The profile header badge reads "On Leave" while an accepted leave covers
 * now (a day leave all day, an hour leave only during its hours), and
 * "Active" otherwise.
 */
class EmployeeProfileLeaveStatusTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private ?City $cityFixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_an_accepted_day_leave_covering_today_marks_the_employee_on_leave(): void
    {
        $employee = $this->makeSubject();
        $this->addLeave($employee, today()->subDay(), today()->addDay());

        $this->assertTrue($employee->isOnLeaveToday());
        $this->renderProfile($employee)
            ->assertViewHas('isOnLeave', true)
            ->assertSee(__('general.on-leave'));
    }

    public function test_a_single_day_leave_today_counts(): void
    {
        $employee = $this->makeSubject();
        $this->addLeave($employee, today()->setTime(8, 0), today()->setTime(16, 0));

        $this->assertTrue($employee->isOnLeaveToday());
    }

    public function test_an_accepted_hourly_leave_counts_only_while_it_is_running(): void
    {
        $this->travelTo(today()->setTime(10, 0));

        $employee = $this->makeSubject();
        $this->addLeave($employee, today()->setTime(9, 0), today()->setTime(11, 0), type: 'hour');

        $this->assertTrue($employee->isOnLeaveToday());
        $this->renderProfile($employee)
            ->assertViewHas('isOnLeave', true)
            ->assertSee(__('general.on-leave'));

        $this->travelTo(today()->setTime(11, 30));

        $this->assertFalse($employee->fresh()->isOnLeaveToday());
    }

    public function test_leaves_that_do_not_count_keep_the_employee_active(): void
    {
        $cases = [
            'pending' => [today(), today(), LeaveRequestStatus::Pending, 'day'],
            'rejected' => [today(), today(), LeaveRequestStatus::Rejected, 'day'],
            'past' => [today()->subWeek(), today()->subDay(), LeaveRequestStatus::Accepted, 'day'],
            'future' => [today()->addDay(), today()->addWeek(), LeaveRequestStatus::Accepted, 'day'],
            'hourly, finished' => [now()->subHours(3), now()->subHour(), LeaveRequestStatus::Accepted, 'hour'],
            'hourly, later today' => [now()->addHour(), now()->addHours(3), LeaveRequestStatus::Accepted, 'hour'],
            'hourly, pending' => [now()->subHour(), now()->addHour(), LeaveRequestStatus::Pending, 'hour'],
        ];

        foreach ($cases as $label => [$start, $end, $status, $type]) {
            $employee = $this->makeSubject();
            $this->addLeave($employee, $start, $end, $status, $type);

            $this->assertFalse($employee->isOnLeaveToday(), "{$label} leave counted");
            $this->renderProfile($employee)
                ->assertViewHas('isOnLeave', false)
                ->assertSee(__('general.active'))
                ->assertDontSee(__('general.on-leave'));
        }
    }

    public function test_another_employees_leave_does_not_count(): void
    {
        $employee = $this->makeSubject();
        $this->addLeave($this->makeSubject(), today(), today());

        $this->assertFalse($employee->isOnLeaveToday());
    }

    private function renderProfile(Employee $employee)
    {
        return Livewire::actingAs($this->makeUser($employee->city, 'HR-ALP'))
            ->test(EmployeeProfile::class, ['employee' => $employee])
            ->assertOk();
    }

    private function makeSubject(): Employee
    {
        $city = $this->cityFixture ??= $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'leave.subject.'.uniqid());

        $this->makeContractFor($employee, [
            'start_date' => today()->subMonth(),
            'end_date' => today()->addMonths(6),
        ]);

        return $employee->fresh();
    }

    private function addLeave(
        Employee $employee,
        Carbon $start,
        Carbon $end,
        int $status = LeaveRequestStatus::Accepted,
        string $type = 'day',
    ): void {
        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'name_en' => 'Annual Leave',
            'name_ar' => 'إجازة سنوية',
            'base_days' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaveBalanceId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $employee->allContracts()->value('id'),
            'type_id' => $leaveTypeId,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leave_requests')->insert([
            'leave_balance_id' => $leaveBalanceId,
            'type' => $type,
            'start_at' => $start,
            'end_at' => $end,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
