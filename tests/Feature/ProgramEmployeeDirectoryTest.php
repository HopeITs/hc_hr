<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\AddToDashboard;
use App\Livewire\ProgramManagement\Program\Detail;
use App\Livewire\ProgramManagement\Program\ProgramEmployees;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The program page's "Employees" tab (ProgramEmployees): a card per person
 * with name and position, ordered and filtered by place, paged by Livewire so a page
 * link never reloads the program page.
 */
class ProgramEmployeeDirectoryTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_cards_show_name_position_and_center_of_current_program_contracts_only(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);

        Livewire::actingAs($hr)
            ->test(ProgramEmployees::class, ['programId' => $f['program']->id])
            ->assertViewHas('totalEmployees', 1)
            ->assertViewHas('employees', function ($employees) use ($f): bool {
                $only = $employees->first();

                return $employees->total() === 1
                    && $only->is($f['active'])
                    // Only this program's contract, not the one in the other program.
                    && $only->allContracts->pluck('position_id')->all() === [$f['position']->id];
            })
            ->assertSee('Layla Hope')
            ->assertSee('Center keeper')
            ->assertSee('Hope Center')
            ->assertDontSee('Other program role')
            ->assertDontSee('Expired Person');
    }

    public function test_it_pages_under_its_own_name_and_search_goes_back_to_the_first_page(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);

        foreach (range(1, 13) as $i) {
            $this->makeContract($this->makeEmployee($f['city'], 'extra'.$i), $f['position'], $f['type']);
        }

        Livewire::actingAs($hr)
            ->test(ProgramEmployees::class, ['programId' => $f['program']->id])
            ->assertViewHas('employees', fn ($employees): bool => $employees->getPageName() === ProgramEmployees::PAGE_NAME
                && $employees->total() === 14 && $employees->lastPage() === 2)
            ->call('gotoPage', 2, ProgramEmployees::PAGE_NAME)
            ->assertViewHas('employees', fn ($employees): bool => $employees->currentPage() === 2 && $employees->count() === 2)
            ->set('search', 'Layla')
            ->assertViewHas('employees', fn ($employees): bool => $employees->currentPage() === 1 && $employees->total() === 1);
    }

    public function test_head_office_comes_first_then_cities_by_code_each_by_job_level_and_filters_narrow(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);
        $national = $this->makeCity('SYR', '000');
        $latakia = $this->makeCity('LAT', '004');
        $manager = JobLevel::query()->forceCreate(['name_en' => 'Program Manager', 'name_ar' => 'مدير برنامج', 'grade' => 1]);
        $finance = Department::query()->forceCreate(['name' => 'Finance', 'code' => 'FIN']);
        $latCenter = Center::query()->create([
            'name_en' => 'Latakia Center', 'name_ar' => 'مركز اللاذقية', 'city_id' => $latakia->id, 'is_active' => true,
            'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);

        $hq = Position::query()->forceCreate([
            'city_id' => $national->id, 'department_id' => $finance->id, 'job_level_id' => $f['jobLevel']->id,
            'program_id' => $f['program']->id, 'title_en' => 'Accountant', 'title_ar' => 'محاسب', 'is_active' => true,
        ]);
        $alpManager = $this->makePosition($f['center'], $f['department'], $manager, $f['program'], 'Project Manager');
        $latOfficer = $this->makePosition($latCenter, $f['department'], $manager, $f['program'], 'Latakia Manager');

        $zed = $this->makeEmployee($f['city'], 'zed.hq', ['first_name_en' => 'Zed', 'last_name_en' => 'Head']);
        $amal = $this->makeEmployee($f['city'], 'amal.manager', ['first_name_en' => 'Amal', 'last_name_en' => 'Manager']);
        $aaron = $this->makeEmployee($f['city'], 'aaron.lat', ['first_name_en' => 'Aaron', 'last_name_en' => 'Lat']);
        $this->makeContract($zed, $hq, $f['type']);
        $this->makeContract($amal, $alpManager, $f['type']);
        $this->makeContract($aaron, $latOfficer, $f['type']);

        $ids = fn ($employees) => $employees->pluck('id')->all();

        $component = Livewire::actingAs($hr)
            ->test(ProgramEmployees::class, ['programId' => $f['program']->id])
            // Head office, then Aleppo's manager before its officer (Layla), then Latakia.
            ->assertViewHas('employees', fn ($employees) => $ids($employees) === [$zed->id, $amal->id, $f['active']->id, $aaron->id])
            ->assertViewMissing('cities');

        $component->set('departmentId', (string) $finance->id)
            ->assertViewHas('employees', fn ($employees) => $ids($employees) === [$zed->id]);

        $component->call('resetFilters')->set('centerId', (string) $latCenter->id)
            ->assertViewHas('employees', fn ($employees) => $ids($employees) === [$aaron->id]);

        $component->call('resetFilters')->set('jobLevelId', (string) $manager->id)
            ->assertViewHas('employees', fn ($employees) => $ids($employees) === [$amal->id, $aaron->id]);

        $component->call('resetFilters')->set('centerId', (string) $f['center']->id)
            ->assertViewHas('employees', fn ($employees) => $ids($employees) === [$amal->id, $f['active']->id]);
    }

    public function test_the_program_page_reopens_the_employees_tab_on_a_later_page(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);

        Livewire::actingAs($hr)->test(Detail::class, ['program' => $f['program']])
            ->assertViewHas('initialTab', 'details')
            ->assertViewHas('totalEmployees', 1);

        Livewire::withQueryParams([ProgramEmployees::PAGE_NAME => 2])
            ->actingAs($hr)->test(Detail::class, ['program' => $f['program']])
            ->assertViewHas('initialTab', 'employees');
    }

    public function test_the_center_tab_names_each_centers_manager(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);
        $pm = JobLevel::query()->forceCreate(['name_en' => 'Project Manager', 'name_ar' => 'مدير مشروع', 'grade' => JobLevel::PROJECT_MANAGER_GRADE]);
        $manager = $this->makeEmployee($f['city'], 'rami.manager', ['first_name_en' => 'Rami', 'last_name_en' => 'Manager']);
        $this->makeContract($manager, $this->makePosition($f['center'], $f['department'], $pm, $f['program'], 'Center manager'), $f['type']);

        Livewire::actingAs($hr)->test(Detail::class, ['program' => $f['program']])
            ->assertSeeInOrder(['Hope Center', __('general.manager'), 'Rami Manager']);
    }

    public function test_the_tab_needs_employees_view(): void
    {
        $f = $this->fixtures();
        $viewer = $this->makeUser($f['city'], 'manager', ['programs.view']);

        Livewire::actingAs($viewer)->test(Detail::class, ['program' => $f['program']])
            ->assertViewHas('canViewEmployees', false)
            ->assertDontSee(__('general.program-employees'));

        Livewire::actingAs($viewer)
            ->test(ProgramEmployees::class, ['programId' => $f['program']->id])
            ->assertStatus(403);
    }

    public function test_add_to_dashboard_from_a_card_stores_the_employee_contact_key(): void
    {
        $f = $this->fixtures();
        $hr = $this->makeUser($f['city'], 'HR-ALP', ['employees.view']);
        $key = DashboardLayout::recordKey('contact', $f['active']->id);

        $html = Livewire::actingAs($hr)
            ->test(ProgramEmployees::class, ['programId' => $f['program']->id])
            ->html();
        $this->assertStringContainsString('atd-contact-'.$f['active']->id, $html);

        Livewire::actingAs($hr)->test(AddToDashboard::class, ['tile' => $key, 'compact' => true])->call('toggle');

        $saved = SavedDashboardLayout::query()->where('user_id', $hr->id)->value('layout');
        $this->assertContains($key, array_column($saved, 'key'));
    }

    private function fixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id, 'is_active' => true,
            'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'SZ', 'code' => 'SZ', 'is_active' => true]);
        $other = Program::query()->forceCreate(['name' => 'Other', 'slug' => 'OT', 'code' => 'OT', 'is_active' => true]);
        $type = ContractType::query()->forceCreate(['name_en' => 'Full Annual', 'name_ar' => 'سنوي كامل']);

        $position = $this->makePosition($center, $department, $jobLevel, $program, 'Center keeper');
        $otherPosition = $this->makePosition($center, $department, $jobLevel, $other, 'Other program role');

        $active = $this->makeEmployee($city, 'layla.hope', [
            'first_name_en' => 'Layla', 'first_name_ar' => 'ليلى', 'last_name_en' => 'Hope', 'last_name_ar' => 'رجاء',
        ]);
        $expired = $this->makeEmployee($city, 'expired.person', ['first_name_en' => 'Expired', 'last_name_en' => 'Person']);

        $this->makeContract($active, $position, $type);
        $this->makeContract($active, $otherPosition, $type);
        $this->makeContract($expired, $position, $type, ['start_date' => now()->subYears(2), 'end_date' => now()->subYear()]);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'position', 'type', 'active');
    }

    private function makePosition(Center $center, Department $department, JobLevel $jobLevel, Program $program, string $title): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $center->city_id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'title_en' => $title, 'title_ar' => $title, 'is_active' => true,
        ]);
    }

    private function makeContract(Employee $employee, Position $position, ContractType $type, array $attributes = []): Contract
    {
        return Contract::query()->forceCreate(array_merge([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => $type->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'weekly_hours' => 40, 'is_active' => true,
        ], $attributes));
    }
}
