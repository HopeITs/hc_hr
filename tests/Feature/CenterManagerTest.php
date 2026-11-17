<?php

namespace Tests\Feature;

use App\Livewire\ProgramManagement\Center\CentersTable;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
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

class CenterManagerTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private Department $department;

    private Program $program;

    private ContractType $contractType;

    private JobLevel $projectManager;

    private JobLevel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
        $this->department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $this->program = Program::query()->forceCreate([
            'name' => 'Core Program',
            'slug' => 'core-program',
            'code' => 'CORE',
            'is_active' => true,
        ]);
        $this->contractType = ContractType::query()->forceCreate([
            'name_en' => 'Full Annual',
            'name_ar' => 'سنوي كامل',
        ]);
        $this->projectManager = JobLevel::query()->forceCreate([
            'name_en' => 'Project Manager',
            'name_ar' => 'مدير المشروع',
            'grade' => JobLevel::PROJECT_MANAGER_GRADE,
        ]);
        $this->officer = JobLevel::query()->forceCreate([
            'name_en' => 'Team',
            'name_ar' => 'الفريق',
            'grade' => 6,
        ]);
    }

    public function test_manager_is_the_active_project_manager_of_that_center(): void
    {
        $center = $this->makeCenter('Hope Center');
        $otherCenter = $this->makeCenter('Other Center');

        $managerPosition = $this->makePosition($center, $this->projectManager);

        $this->makeContract($this->employee('rami', 'Rami', 'Haddad'), $managerPosition);
        // Not a manager: a team member at the same center.
        $this->makeContract($this->employee('team', 'Team', 'Member'), $this->makePosition($center, $this->officer));
        // Not current: an expired and a deactivated project manager contract.
        $this->makeContract($this->employee('old', 'Former', 'Manager'), $managerPosition, [
            'start_date' => now()->subYears(2),
            'end_date' => now()->subYear(),
        ]);
        $this->makeContract($this->employee('off', 'Inactive', 'Manager'), $managerPosition, ['is_active' => false]);
        // Not this center: the other center's project manager.
        $this->makeContract(
            $this->employee('else', 'Elsewhere', 'Manager'),
            $this->makePosition($otherCenter, $this->projectManager),
        );

        $this->assertSame('Rami Haddad', $center->managerNames());
        $this->assertSame('Elsewhere Manager', $otherCenter->managerNames());
    }

    public function test_center_without_a_project_manager_shows_a_dash(): void
    {
        $center = $this->makeCenter('Hope Center');
        $this->makeContract($this->employee('team', 'Team', 'Member'), $this->makePosition($center, $this->officer));

        $this->assertSame('—', $center->managerNames());
    }

    public function test_employee_holding_two_manager_positions_is_listed_once(): void
    {
        $center = $this->makeCenter('Hope Center');
        $rami = $this->employee('rami', 'Rami', 'Haddad');

        $this->makeContract($rami, $this->makePosition($center, $this->projectManager));
        $this->makeContract($rami, $this->makePosition($center, $this->projectManager));

        $this->assertSame('Rami Haddad', $center->managerNames());
    }

    public function test_centers_table_shows_the_manager(): void
    {
        $center = $this->makeCenter('Hope Center');
        $this->makeContract($this->employee('rami', 'Rami', 'Haddad'), $this->makePosition($center, $this->projectManager));

        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.view']);

        Livewire::actingAs($hr)
            ->test(CentersTable::class)
            ->assertSee(__('general.center-manager'))
            ->assertSee('Rami Haddad');
    }

    private function employee(string $username, string $first, string $last): Employee
    {
        return $this->makeEmployee($this->city, $username, [
            'first_name_en' => $first,
            'first_name_ar' => $first,
            'last_name_en' => $last,
            'last_name_ar' => $last,
        ]);
    }

    private function makeCenter(string $name): Center
    {
        return Center::query()->create([
            'name_en' => $name,
            'name_ar' => $name,
            'city_id' => $this->city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);
    }

    private function makePosition(Center $center, JobLevel $jobLevel): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $this->city->id,
            'department_id' => $this->department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $this->program->id,
            'title_en' => $jobLevel->name_en,
            'title_ar' => $jobLevel->name_ar,
            'is_active' => true,
        ]);
    }

    private function makeContract(Employee $employee, Position $position, array $attributes = []): Contract
    {
        return Contract::query()->forceCreate(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $this->contractType->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'weekly_hours' => 40,
            'is_active' => true,
        ], $attributes));
    }
}
