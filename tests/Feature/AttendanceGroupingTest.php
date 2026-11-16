<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Timesheets;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Program;
use App\Services\Attendance\Grouping;
use App\Services\Attendance\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The timesheet page and the printout group people for each city by the project (program) or the
 * department they work in, and print one group on its own.
 */
class AttendanceGroupingTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    public function test_each_city_is_grouped_by_project_then_by_department(): void
    {
        $this->world();
        $month = CarbonImmutable::create(2026, 9, 1);
        $items = Employee::query()->with('city')->get()->map(fn (Employee $e) => [
            'employee' => $e, 'sheet' => app(Timesheet::class)->forEmployee($e, $month),
        ]);

        $byProject = app(Grouping::class)->group($items, Grouping::PROJECT);

        $this->assertSame(['ALP', 'DMS'], $byProject->pluck('name')->all());
        $this->assertSame(['Education', 'Health'], $byProject[0]['groups']->pluck('name')->all());
        $this->assertSame([1, 1], $byProject[0]['groups']->pluck('totals.people')->all());
        $this->assertSame(['Education'], $byProject[1]['groups']->pluck('name')->all());

        $byDepartment = app(Grouping::class)->group($items, Grouping::DEPARTMENT);

        $this->assertSame(['Teaching'], $byDepartment[0]['groups']->pluck('name')->all());
        $this->assertSame(2, $byDepartment[0]['groups'][0]['totals']['people']);
        $this->assertSame(['Admin'], $byDepartment[1]['groups']->pluck('name')->all());
    }

    public function test_the_page_shows_the_groups_and_switches_between_project_and_department(): void
    {
        $this->world();

        Livewire::actingAs($this->hr())->test(Timesheets::class)
            ->set('month', '2026-09')
            ->assertSee('Education')
            ->assertSee('Health')
            ->assertSee('Anna Alpha')
            ->assertSee('Carla Charlie')
            ->assertDontSee('Admin')
            ->set('groupBy', 'department')
            ->assertSee('Teaching')
            ->assertSee('Admin')
            ->assertDontSee('Health');
    }

    public function test_a_city_filter_and_a_search_narrow_the_page(): void
    {
        $this->world();

        Livewire::actingAs($this->hr())->test(Timesheets::class)
            ->set('month', '2026-09')
            ->set('cityId', City::query()->where('slug', 'DMS')->value('id'))
            ->assertSee('Carla Charlie')
            ->assertDontSee('Anna Alpha')
            ->set('cityId', null)
            ->set('search', 'bravo')
            ->assertSee('Basil Bravo')
            ->assertDontSee('Anna Alpha');
    }

    public function test_the_month_buttons_move_by_one_month_and_a_group_can_be_folded(): void
    {
        $this->world();
        $aleppo = City::query()->where('slug', 'ALP')->value('id');
        $education = Program::query()->where('code', 'EDUCATION')->value('id');

        Livewire::actingAs($this->hr())->test(Timesheets::class)
            ->set('month', '2026-12')
            ->call('shiftMonth', 1)
            ->assertSet('month', '2027-01')
            ->call('shiftMonth', -1)
            ->call('shiftMonth', -1)
            ->assertSet('month', '2026-11')
            ->set('month', '2026-09')
            ->assertSee('Anna Alpha')
            ->call('toggleGroup', "$aleppo:program-$education")
            ->assertDontSee('Anna Alpha')
            ->assertSee('Basil Bravo');
    }

    public function test_one_group_prints_on_its_own_in_the_order_of_the_page(): void
    {
        $this->world();
        $this->withoutVite();
        $education = Program::query()->where('code', 'EDUCATION')->value('id');
        $hr = $this->hr();

        $one = $this->actingAs($hr)->get(route('attendance.print', [
            'month' => '2026-09', 'group_by' => 'program', 'group' => $education,
        ]));

        $one->assertOk()->assertSee('Anna Alpha')->assertSee('Carla Charlie')->assertDontSee('Basil Bravo');

        // Aleppo before Damascus, then by name.
        $all = $this->actingAs($hr)->get(route('attendance.print', ['month' => '2026-09', 'group_by' => 'department']))->getContent();

        $this->assertLessThan(strpos($all, 'Carla Charlie'), strpos($all, 'Anna Alpha'));
        $this->assertLessThan(strpos($all, 'Basil Bravo'), strpos($all, 'Anna Alpha'));

        $this->actingAs($hr)->get(route('attendance.print', ['month' => '2026-09', 'group_by' => 'program', 'group' => 999999]))->assertNotFound();
        $this->actingAs($hr)->get(route('attendance.print', ['month' => '2026-09', 'group' => 'x']))->assertSessionHasErrors('group');
    }

    private function person(string $key, City $city, string $project, string $department, string $first, string $last): Employee
    {
        $program = Program::query()->firstOrCreate(['code' => strtoupper($project)], [
            'name' => $project, 'slug' => strtolower($project), 'is_active' => true,
        ]);
        $dept = Department::query()->firstOrCreate(['code' => strtoupper($department)], ['name' => $department]);
        $position = $this->makePosition($city, ['program_id' => $program->id, 'department_id' => $dept->id]);
        $position->center->update(['weekends' => '0,6']);

        $employee = $this->makeEmployee($city, $key, [
            'first_name_en' => $first, 'last_name_en' => $last, 'first_name_ar' => $first, 'last_name_ar' => $last,
        ]);
        $this->makeContractFor($employee, ['position_id' => $position->id]);

        return $this->people[$key] = $employee;
    }

    private function world(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $damascus = $this->makeCity('DMS', '002');

        $this->person('a1', $aleppo, 'Education', 'Teaching', 'Anna', 'Alpha');
        $this->person('a2', $aleppo, 'Health', 'Teaching', 'Basil', 'Bravo');
        $this->person('d1', $damascus, 'Education', 'Admin', 'Carla', 'Charlie');
    }

    private function hr(array $permissions = ['attendance.view']): \App\Models\User
    {
        return $this->makeUser(City::query()->where('slug', 'ALP')->first(), 'HR-SYR', $permissions);
    }
}
