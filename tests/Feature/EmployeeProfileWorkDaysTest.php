<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeProfile;
use App\Models\City;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The work days & hours section on an employee's profile: who sees it, and
 * who gets the button that changes the days (ContractPolicy::viewWorkDays,
 * ::changeWorkDays) — HR of the city, the direct or technical manager, not a
 * colleague; the employee reads their own without the button.
 */
class EmployeeProfileWorkDaysTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_hr_of_the_city_sees_the_section_and_the_button(): void
    {
        $subject = $this->subject();

        $this->profile($this->makeUser($this->city, 'HR-ALP', ['employees.view', 'contracts.view', 'contracts.update']), $subject)
            ->assertSee(__('contracts.work-days.title'))
            ->assertSee(__('contracts.work-days.range-badge', ['range' => 'Sunday – Thursday']))
            ->assertSee(__('contracts.work-days.change-range'));
    }

    public function test_the_direct_and_the_technical_manager_get_the_button(): void
    {
        $subject = $this->subject();
        [$direct, $directPosition] = $this->manager();
        [$technical, $technicalPosition] = $this->manager();
        $subject->latestActiveContract()->position->update([
            'direct_manager_id' => $directPosition->id,
            'tech_manager_id' => $technicalPosition->id,
        ]);

        foreach ([$direct, $technical] as $manager) {
            $this->profile($manager, $subject->fresh())
                ->assertSee(__('contracts.work-days.title'))
                ->assertSee(__('contracts.work-days.change-range'));
        }
    }

    public function test_the_employee_reads_their_own_without_the_button_and_a_colleague_sees_nothing(): void
    {
        $subject = $this->subject();

        $this->profile($this->makeUser($this->city, 'employee', [], $subject), $subject)
            ->assertSee(__('contracts.work-days.title'))
            ->assertSee('09:00–17:00')
            ->assertDontSee(__('contracts.work-days.change-range'));

        $this->profile($this->makeUser($this->city, 'employee'), $subject)
            ->assertDontSee(__('contracts.work-days.title'));
    }

    private function profile($user, Employee $subject)
    {
        return Livewire::withoutLazyLoading()->actingAs($user)->test(EmployeeProfile::class, ['employee' => $subject]);
    }

    /** Someone on Sunday–Thursday, 09:00–17:00, at a center closed on Friday and Saturday. */
    private function subject(): Employee
    {
        $subject = $this->makeEmployee($this->city, 'work.days.'.uniqid());
        $contract = $this->makeContractFor($subject, [
            'start_date' => today()->subMonth(),
            'end_date' => today()->addMonths(6),
            'schedule_mode' => 1,
            'work_day_from' => 0,
            'work_day_to' => 4,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
        ]);
        $contract->position->center->update(['weekends' => '5,6']);

        return $subject->fresh();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Position} */
    private function manager(): array
    {
        $employee = $this->makeEmployee($this->city, 'manager.'.uniqid());
        $contract = $this->makeContractFor($employee, ['start_date' => today()->subYear(), 'end_date' => today()->addYear()]);

        return [$this->makeUser($this->city, 'manager', [], $employee), $contract->position];
    }
}
