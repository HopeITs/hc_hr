<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\DefaultDeliverable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Notifications\ChecklistAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Cancelling auto-renew is HR's signal that a volunteer's time here is
 * ending, so it should also start collecting back whatever of the center's
 * equipment is still checked out to them — see ContractObserver::updated().
 */
class ContractAutoRenewCancellationTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_cancelling_auto_renew_starts_a_return_cycle_and_notifies_the_receiver(): void
    {
        Notification::fake();

        $contract = $this->makeContract(['auto_renew' => true]);
        $employee = $contract->employee;

        $item = ChecklistItem::query()->create(['name' => 'Laptop', 'order' => 1]);
        $receiverEmployee = $this->makeEmployee($employee->city, 'default-deliverer-'.uniqid());
        $receiverUser = User::factory()->create(['employee_id' => $receiverEmployee->id]);

        DefaultDeliverable::query()->create([
            'employee_id' => $receiverEmployee->id,
            'city_id' => $employee->city_id,
            'item_id' => $item->id,
        ]);

        $checklist = Checklist::query()->create([
            'item_id' => $item->id,
            'employee_id' => $employee->id,
            'delivered_id' => $receiverEmployee->id,
            'delivered_status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $contract->update(['auto_renew' => false]);

        $this->assertSame('pending', $checklist->fresh()->returned_status);
        $this->assertSame($receiverEmployee->id, $checklist->fresh()->receiver_id);

        Notification::assertSentTo($receiverUser, ChecklistAssigned::class);
    }

    public function test_an_item_with_no_default_deliverable_is_left_for_hr_to_assign_by_hand(): void
    {
        Notification::fake();

        $contract = $this->makeContract(['auto_renew' => true]);
        $employee = $contract->employee;

        $item = ChecklistItem::query()->create(['name' => 'Badge', 'order' => 1]);
        $someoneElse = $this->makeEmployee($employee->city, 'deliverer-'.uniqid());

        $checklist = Checklist::query()->create([
            'item_id' => $item->id,
            'employee_id' => $employee->id,
            'delivered_id' => $someoneElse->id,
            'delivered_status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $contract->update(['auto_renew' => false]);

        $this->assertNull($checklist->fresh()->returned_status);
        Notification::assertNothingSent();
    }

    public function test_turning_auto_renew_back_on_does_not_start_a_return_cycle(): void
    {
        Notification::fake();

        $contract = $this->makeContract(['auto_renew' => false]);
        $employee = $contract->employee;

        $item = ChecklistItem::query()->create(['name' => 'Laptop', 'order' => 1]);
        $receiverEmployee = $this->makeEmployee($employee->city, 'default-deliverer-'.uniqid());

        DefaultDeliverable::query()->create([
            'employee_id' => $receiverEmployee->id,
            'city_id' => $employee->city_id,
            'item_id' => $item->id,
        ]);

        $checklist = Checklist::query()->create([
            'item_id' => $item->id,
            'employee_id' => $employee->id,
            'delivered_id' => $receiverEmployee->id,
            'delivered_status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $contract->update(['auto_renew' => true]);

        $this->assertNull($checklist->fresh()->returned_status);
        Notification::assertNothingSent();
    }

    private function makeContract(array $overrides = []): Contract
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
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program-'.uniqid(), 'code' => 'CORE'.uniqid(), 'is_active' => true]);

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

        $employee = $this->makeEmployee($city, 'auto-renew-cancel-employee-'.uniqid());

        $contractType = ContractType::query()->find(1)
            ?? ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

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
