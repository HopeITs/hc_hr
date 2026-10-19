<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Services\LeaveAlternativeEmployees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Who a requester may name as their alternative employee, per job level.
 */
class LeaveAlternativeEmployeesTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    private City $otherCity;

    private Center $headOffice;

    private Center $fieldCenter;

    private Program $hope;

    private Program $micro;

    private Department $management;

    private Department $communication;

    private Department $frontDesk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
        $this->otherCity = $this->makeCity('LAT', '005');
        $this->headOffice = $this->center('Head Office', $this->city);
        $this->fieldCenter = $this->center('Field Center', $this->city);
        $this->hope = $this->program('HC');
        $this->micro = $this->program('MP');
        $this->management = Department::query()->create(['name' => 'Management', 'code' => 'MGT']);
        $this->communication = Department::query()->create(['name' => 'Communication', 'code' => 'COM']);
        $this->frontDesk = Department::query()->create(['name' => 'Front Desk', 'code' => 'FD']);
    }

    public function test_grade_one_sees_all_management_in_the_city(): void
    {
        $ceo = $this->seat('ceo', 1, $this->hope, $this->management, $this->headOffice);
        $programManager = $this->seat('program-manager', 1, $this->micro, $this->management);
        $projectManager = $this->seat('project-manager', 3, $this->micro, $this->management, $this->fieldCenter);
        $itManager = $this->seat('it-manager', 5, $this->hope, $this->communication, $this->headOffice);
        $this->seat('officer', 6, $this->hope, $this->management, $this->headOffice);
        $this->seat('keeper', 8, $this->hope, $this->management, $this->headOffice);
        $this->seat('elsewhere', 3, $this->micro, $this->management, city: $this->otherCity);

        $this->assertAlternatives($ceo, [$programManager, $projectManager, $itManager]);
    }

    public function test_grade_two_adds_team_grades_of_their_program_and_center(): void
    {
        $associate = $this->seat('associate', 2, $this->hope, $this->communication, $this->headOffice);
        $ceo = $this->seat('ceo', 1, $this->hope, $this->management, $this->headOffice);
        $officer = $this->seat('officer', 6, $this->hope, $this->communication, $this->headOffice);
        $trainer = $this->seat('trainer', 7, $this->hope, $this->frontDesk, $this->headOffice);
        $this->seat('keeper', 8, $this->hope, $this->management, $this->headOffice);
        $this->seat('other-program', 6, $this->micro, $this->frontDesk, $this->headOffice);
        $this->seat('other-center', 6, $this->hope, $this->communication, $this->fieldCenter);

        $this->assertAlternatives($associate, [$ceo, $officer, $trainer]);
    }

    public function test_grade_three_with_a_center_sees_their_program_and_their_center(): void
    {
        $projectManager = $this->seat('project-manager', 3, $this->micro, $this->management, $this->fieldCenter);
        $officer = $this->seat('officer', 6, $this->micro, $this->frontDesk, $this->fieldCenter);
        $programElsewhere = $this->seat('program-elsewhere', 6, $this->micro, $this->frontDesk, $this->headOffice);
        $centerColleague = $this->seat('center-colleague', 6, $this->hope, $this->communication, $this->fieldCenter);
        $this->seat('department-manager', 5, $this->micro, $this->management, $this->fieldCenter);
        $this->seat('keeper', 8, $this->micro, $this->frontDesk, $this->fieldCenter);
        $this->seat('other-program-and-center', 6, $this->hope, $this->frontDesk, $this->headOffice);

        $this->assertAlternatives($projectManager, [$officer, $programElsewhere, $centerColleague]);
    }

    public function test_grade_three_without_a_center_sees_their_program_in_the_city(): void
    {
        $projectManager = $this->seat('project-manager', 3, $this->micro, $this->management);
        $member = $this->seat('member', 6, $this->micro, $this->frontDesk);
        $this->seat('other-program', 6, $this->hope, $this->frontDesk);
        $this->seat('other-city', 6, $this->micro, $this->frontDesk, city: $this->otherCity);

        $this->assertAlternatives($projectManager, [$member]);
    }

    public function test_grade_four_also_sees_the_holders_of_their_manager_positions(): void
    {
        $directManager = $this->seat('direct-manager', 3, $this->hope, $this->management, $this->headOffice);
        $techManager = $this->seat('tech-manager', 1, $this->hope, $this->management, $this->headOffice);
        $assistant = $this->seat('assistant', 4, $this->micro, $this->frontDesk, managers: [
            'direct_manager_id' => $directManager->contracts->first()->position_id,
            'tech_manager_id' => $techManager->contracts->first()->position_id,
        ]);
        $member = $this->seat('member', 6, $this->micro, $this->frontDesk);

        $this->assertAlternatives($assistant, [$directManager, $techManager, $member]);
    }

    public function test_grade_five_sees_their_department_in_the_city(): void
    {
        $manager = $this->seat('manager', 5, $this->hope, $this->communication, $this->headOffice);
        $associate = $this->seat('associate', 2, $this->hope, $this->communication, $this->headOffice);
        $fieldOfficer = $this->seat('field-officer', 6, $this->micro, $this->communication, $this->fieldCenter);
        $this->seat('other-department', 6, $this->hope, $this->frontDesk, $this->headOffice);
        $this->seat('other-city', 6, $this->hope, $this->communication, city: $this->otherCity);

        $this->assertAlternatives($manager, [$associate, $fieldOfficer]);
    }

    public function test_team_grades_see_their_department_within_their_program(): void
    {
        $officer = $this->seat('officer', 6, $this->micro, $this->frontDesk, $this->fieldCenter);
        $colleague = $this->seat('colleague', 7, $this->micro, $this->frontDesk, $this->headOffice);
        $this->seat('other-program', 6, $this->hope, $this->frontDesk, $this->fieldCenter);
        $this->seat('other-department', 6, $this->micro, $this->communication, $this->fieldCenter);
        $this->seat('project-manager', 3, $this->micro, $this->management, $this->fieldCenter);

        $this->assertAlternatives($officer, [$colleague]);
    }

    public function test_the_only_member_of_a_department_sees_nobody(): void
    {
        $accountant = $this->seat('accountant', 6, $this->hope, $this->communication, $this->headOffice);
        $this->seat('officer', 6, $this->hope, $this->frontDesk, $this->headOffice);

        $this->assertAlternatives($accountant, []);
    }

    public function test_an_employee_without_an_active_contract_sees_nobody(): void
    {
        $employee = $this->makeEmployee($this->city, 'no-contract');
        $this->seat('officer', 6, $this->hope, $this->frontDesk, $this->headOffice);

        $this->assertAlternatives($employee, []);
    }

    public function test_an_expired_contract_does_not_make_someone_an_alternative(): void
    {
        $officer = $this->seat('officer', 6, $this->hope, $this->frontDesk, $this->headOffice);
        $former = $this->seat('former', 6, $this->hope, $this->frontDesk, $this->headOffice);
        $former->contracts()->update(['end_date' => now()->subDay()->toDateString()]);

        $this->assertAlternatives($officer, []);
    }

    /**
     * @param  list<Employee>  $expected
     */
    private function assertAlternatives(Employee $requester, array $expected): void
    {
        $actual = app(LeaveAlternativeEmployees::class)->for($requester->fresh())->pluck('username')->sort()->values()->all();

        $this->assertSame(collect($expected)->pluck('username')->sort()->values()->all(), $actual);
    }

    private function seat(
        string $username,
        int $grade,
        Program $program,
        Department $department,
        ?Center $center = null,
        ?City $city = null,
        array $managers = [],
    ): Employee {
        $city ??= $this->city;
        $employee = $this->makeEmployee($city, $username, ['first_name_en' => $username]);

        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'center_id' => $center?->id,
            'city_id' => $city->id,
            'job_level_id' => JobLevel::query()->firstOrCreate(
                ['grade' => $grade],
                ['name_en' => 'Grade '.$grade, 'name_ar' => 'درجة '.$grade],
            )->id,
            'department_id' => $department->id,
            'title_en' => $username,
            'title_ar' => $username,
            'max_emp' => 1,
            'is_active' => true,
            ...$managers,
        ]);

        Contract::query()->create([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(
                ['name_en' => 'Volunteer Contract'],
                ['name_ar' => 'عقد تطوّع'],
            )->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'currency' => 2,
            'salary_per' => 1,
            'salary' => 1500,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        return $employee;
    }

    private function center(string $name, City $city): Center
    {
        return Center::query()->create([
            'name_en' => $name,
            'name_ar' => $name,
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => '5',
        ]);
    }

    private function program(string $code): Program
    {
        return Program::query()->create([
            'name' => $code,
            'code' => $code,
            'slug' => strtolower($code),
            'is_active' => true,
        ]);
    }
}
