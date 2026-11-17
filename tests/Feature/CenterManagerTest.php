<?php

namespace Tests\Feature;

use App\Livewire\Position\PositionTable;
use App\Livewire\ProgramManagement\Center\CenterCreate;
use App\Livewire\ProgramManagement\Center\CenterDetails;
use App\Livewire\ProgramManagement\Center\CentersModule;
use App\Livewire\ProgramManagement\Center\CentersTable;
use App\Livewire\ProgramManagement\Program\Detail;
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

    public function test_the_head_of_the_center_is_its_most_senior_person_reporting_to_no_one_there(): void
    {
        $headOffice = $this->makeCenter('Head Office');
        $regional = $this->makeCenter('Regional Center');
        $topLevel = JobLevel::query()->forceCreate(['name_en' => 'Program Manager', 'name_ar' => 'مدير برنامج', 'grade' => 1]);

        // The CEO reports to no one; a program manager reports to the CEO; a project
        // manager of one program reports to that program manager: all in the head office.
        $ceo = $this->makePosition($headOffice, $topLevel);
        $programManager = $this->makePosition($headOffice, $topLevel, attributes: ['direct_manager_id' => $ceo->id]);
        $projectManager = $this->makePosition($headOffice, $this->projectManager, attributes: ['direct_manager_id' => $programManager->id]);
        $this->makeContract($this->employee('fredy', 'Fredy', 'Youssef'), $ceo);
        $this->makeContract($this->employee('antoine', 'Antoine', 'Arbaji'), $programManager);
        $this->makeContract($this->employee('fadi', 'Fadi', 'Dabbagh'), $projectManager);

        // A regional center's project manager reports to the head office: still heads their center,
        // and a senior accountant there (grade 5) reporting elsewhere does not.
        $this->makeContract($this->employee('toni', 'Toni', 'Azar'), $this->makePosition($regional, $this->projectManager, attributes: ['direct_manager_id' => $programManager->id]));
        $accountant = JobLevel::query()->forceCreate(['name_en' => 'Department Manager', 'name_ar' => 'مدير قسم', 'grade' => 5]);
        $this->makeContract($this->employee('basem', 'Basem', 'Ibrahim'), $this->makePosition($regional, $accountant, attributes: ['direct_manager_id' => $ceo->id]));

        $this->assertSame('Fredy Youssef', $headOffice->managerNames());
        $this->assertSame('Toni Azar', $regional->managerNames());

        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.view']);
        Livewire::actingAs($hr)->test(CentersTable::class)
            ->assertSee('Fredy Youssef')
            ->assertDontSee('Fadi Dabbagh');
    }

    public function test_for_a_program_the_center_shows_that_programs_manager_else_its_head(): void
    {
        $headOffice = $this->makeCenter('Head Office');
        $topLevel = JobLevel::query()->forceCreate(['name_en' => 'Program Manager', 'name_ar' => 'مدير برنامج', 'grade' => 1]);
        $hopeCenter = Program::query()->forceCreate(['name' => 'Hope Center', 'slug' => 'HC', 'code' => 'HC', 'is_active' => true]);

        $ceo = $this->makePosition($headOffice, $topLevel, attributes: ['program_id' => $hopeCenter->id]);
        $programManager = $this->makePosition($headOffice, $topLevel, attributes: ['direct_manager_id' => $ceo->id]);
        $this->makeContract($this->employee('fredy', 'Fredy', 'Youssef'), $ceo);
        $this->makeContract($this->employee('antoine', 'Antoine', 'Arbaji'), $programManager);
        $this->makeContract($this->employee('fadi', 'Fadi', 'Dabbagh'), $this->makePosition($headOffice, $this->projectManager, attributes: ['direct_manager_id' => $programManager->id]));

        // Micro Project ($this->program) has its own project manager here; Hope Center has none.
        $this->assertSame('Fadi Dabbagh', $headOffice->managerNamesFor($this->program));
        $this->assertSame('Fredy Youssef', $headOffice->managerNamesFor($hopeCenter));
        $this->assertSame('Fredy Youssef', $headOffice->managerNames());
    }

    public function test_a_center_serving_a_program_counts_its_shared_volunteers_and_the_card_counts_people(): void
    {
        $scienceZone = $this->makeCenter('Science Zone');
        $scienceZone->programs()->attach($this->program);
        $unlinked = $this->makeCenter('Hope Center');
        $otherCity = $this->makeCity('HMS', '002');

        // Study Zone positions have no center: shared by the program's centers of their city.
        $layla = $this->employee('layla', 'Layla', 'Volunteer');
        $this->makeContract($layla, $this->makePosition(null, $this->officer));
        $this->makeContract($layla, $this->makePosition(null, $this->officer));
        $this->makeContract($this->employee('homs', 'Homs', 'Volunteer'), $this->makePosition(null, $this->officer, $otherCity));

        $this->assertSame([$layla->id], $scienceZone->activeEmployeesQuery()->pluck('id')->all());
        $this->assertSame(0, $unlinked->activeEmployeesQuery()->count());

        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.view', 'employees.view']);
        Livewire::actingAs($hr)->test(CenterDetails::class, ['center' => $scienceZone])
            ->assertViewHas('totalEmployees', 1)
            ->assertViewHas('totalAssignments', 2);

        // Layla holds two of the program's positions: one person on its card.
        $card = Program::query()->withCardCounts()->findOrFail($this->program->id);
        $this->assertSame(2, (int) $card->employees_count);
        $this->assertSame(3, (int) $card->contracts_count);
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

    public function test_a_center_serving_a_program_shows_that_programs_project_manager_in_its_city(): void
    {
        $studyZone = $this->makeCenter('Study Zone Armenian');
        $studyZone->programs()->attach($this->program);
        $notLinked = $this->makeCenter('Hope Center');

        // The program's manager position has no center (one manager per city).
        $this->makeContract($this->employee('waad', 'Waad', 'Ishak'), $this->makePosition(null, $this->projectManager));
        // Not a manager, and another city's manager of the same program.
        $this->makeContract($this->employee('team', 'Team', 'Member'), $this->makePosition(null, $this->officer));
        $otherCity = $this->makeCity('HMS', '002');
        $this->makeContract($this->employee('homs', 'Homs', 'Manager'), $this->makePosition(null, $this->projectManager, $otherCity));

        $this->assertSame('Waad Ishak', $studyZone->fresh()->managerNames());
        $this->assertSame('—', $notLinked->managerNames());
    }

    public function test_a_center_lists_its_own_manager_and_the_programs_manager_once_each(): void
    {
        $center = $this->makeCenter('Hope Center');
        $center->programs()->attach($this->program);
        $rami = $this->employee('rami', 'Rami', 'Haddad');

        $this->makeContract($rami, $this->makePosition($center, $this->projectManager));
        $this->makeContract($rami, $this->makePosition(null, $this->projectManager));

        $this->assertSame('Rami Haddad', $center->fresh()->managerNames());
    }

    public function test_centers_table_shows_and_finds_the_programs_manager(): void
    {
        $studyZone = $this->makeCenter('Study Zone Armenian');
        $studyZone->programs()->attach($this->program);
        $this->makeCenter('Hope Center');
        $this->makeContract($this->employee('waad', 'Waad', 'Ishak'), $this->makePosition(null, $this->projectManager));

        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.view']);

        Livewire::actingAs($hr)
            ->test(CentersTable::class)
            ->assertSee('Waad Ishak')
            ->set('search.center_manager', 'Waad')
            ->assertSee('Study Zone Armenian')
            ->assertDontSee('Hope Center');
    }

    public function test_the_center_dialog_saves_and_loads_its_programs(): void
    {
        $center = $this->makeCenter('Study Zone Armenian');
        $micro = Program::query()->forceCreate(['name' => 'Micro Project', 'slug' => 'micro', 'code' => 'MIC', 'is_active' => true]);
        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.create', 'centers.update']);

        Livewire::actingAs($hr)
            ->test(CentersModule::class)
            ->call('edit', $center->id)
            ->assertSet('item.programs', [])
            ->set('item.programs', [(string) $this->program->id, (string) $micro->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$this->program->id, $micro->id], $center->programs()->pluck('programs.id')->all());

        Livewire::actingAs($hr)
            ->test(CentersModule::class)
            ->call('edit', $center->id)
            ->assertSet('item.programs', array_map('strval', [$this->program->id, $micro->id]))
            ->set('item.programs', [(string) $micro->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$micro->id], $center->programs()->pluck('programs.id')->all());
    }

    public function test_the_create_page_saves_the_programs(): void
    {
        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.create']);

        Livewire::actingAs($hr)
            ->test(CenterCreate::class)
            ->set('item.name_en', 'Study Zone New')
            ->set('item.name_ar', 'ستادي زون')
            ->set('item.city_id', $this->city->id)
            ->set('item.weekends', ['5'])
            ->set('item.open_at', '08:00')
            ->set('item.close_at', '21:00')
            ->set('item.programs', [(string) $this->program->id])
            ->call('save')
            ->assertHasNoErrors();

        $center = Center::query()->where('name_en', 'Study Zone New')->sole();
        $this->assertSame([$this->program->id], $center->programs()->pluck('programs.id')->all());
    }

    public function test_an_unknown_program_is_refused(): void
    {
        $center = $this->makeCenter('Study Zone Armenian');
        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.update']);

        Livewire::actingAs($hr)
            ->test(CentersModule::class)
            ->call('edit', $center->id)
            ->set('item.programs', ['999999'])
            ->call('save')
            ->assertHasErrors('item.programs.0');

        $this->assertSame(0, $center->programs()->count());
    }

    public function test_a_programs_positions_without_a_center_serve_every_linked_center_in_its_city(): void
    {
        $studyZone = $this->makeCenter('Study Zone Armenian');
        $studyZone->programs()->attach($this->program);
        $own = $this->makePosition($studyZone, $this->officer);
        $shared = $this->makePosition(null, $this->officer);
        // Another program's position without a center, and another city's.
        $other = Program::query()->forceCreate(['name' => 'Micro', 'slug' => 'micro', 'code' => 'MIC', 'is_active' => true]);
        $elsewhere = $this->makePosition(null, $this->officer);
        $elsewhere->update(['program_id' => $other->id]);
        $this->makePosition(null, $this->officer, $this->makeCity('HMS', '002'));
        $hope = $this->makeCenter('Hope Center');

        $this->assertEqualsCanonicalizing(
            [$own->id, $shared->id],
            Position::query()->servingCenter($studyZone)->pluck('id')->all(),
        );
        $this->assertSame([], Position::query()->servingCenter($hope)->pluck('id')->all());

        $hr = $this->makeUser($this->city, 'HR-ALP', ['centers.view', 'positions.view']);

        Livewire::actingAs($hr)
            ->test(CentersTable::class)
            ->assertSee(trans_choice('general.shared-positions', 1, ['count' => 1]));

        Livewire::actingAs($hr)
            ->test(PositionTable::class, ['centerId' => $studyZone->id])
            ->assertViewHas('paginator', fn ($paginator) => collect($paginator->items())->pluck('id')->sort()->values()->all() === collect([$own->id, $shared->id])->sort()->values()->all());
    }

    public function test_the_program_page_lists_its_linked_centers_with_their_manager(): void
    {
        $studyZone = $this->makeCenter('Study Zone Armenian');
        $studyZone->programs()->attach($this->program);
        $this->makeCenter('Hope Center');
        $this->makeContract($this->employee('waad', 'Waad', 'Ishak'), $this->makePosition(null, $this->projectManager));

        $hr = $this->makeUser($this->city, 'HR-ALP', ['programs.view']);

        Livewire::actingAs($hr)
            ->test(Detail::class, ['program' => $this->program])
            ->assertViewHas('centers', fn ($centers) => $centers->pluck('id')->all() === [$studyZone->id])
            ->assertSee('Study Zone Armenian')
            ->assertSee('Waad Ishak')
            ->assertDontSee('No centers associated');
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

    private function makePosition(?Center $center, JobLevel $jobLevel, ?City $city = null, array $attributes = []): Position
    {
        return Position::query()->forceCreate([...[
            'center_id' => $center?->id,
            'city_id' => ($city ?? $this->city)->id,
            'department_id' => $this->department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $this->program->id,
            'title_en' => $jobLevel->name_en,
            'title_ar' => $jobLevel->name_ar,
            'is_active' => true,
        ], ...$attributes]);
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
