<?php

namespace Tests\Feature;

use App\Livewire\Basics\HolidayModule;
use App\Livewire\Team\Calendar;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Holidays on the team calendar: every viewer sees the names of their own
 * holidays; someone who sets holidays (§6.1) also sees the ones they look
 * after and can add one from a day.
 */
class TeamCalendarHolidaysTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $syr;

    private City $alp;

    private Program $studyZone;

    private Position $headPosition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->syr = $this->makeCity(City::NATIONAL_SLUG, '000');
        $this->alp = $this->makeCity('ALP', '001');
        $this->studyZone = Program::query()->forceCreate(['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'sz', 'is_active' => true]);
        $this->headPosition = $this->position($this->syr, 2);
        $this->position($this->alp, 3, $this->headPosition->id);

        Holiday::query()->create(['name_en' => 'Independence Day', 'name_ar' => 'عيد الاستقلال', 'start_date' => '2026-11-17', 'end_date' => '2026-11-17', 'scope' => 'national']);
        $project = Holiday::query()->create(['name_en' => 'Study Zone Aleppo Day', 'name_ar' => 'يوم ستدي زون حلب', 'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'scope' => 'projects']);
        $project->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->alp->id]);
    }

    public function test_the_month_picker_jumps_to_any_month_keeping_the_day(): void
    {
        $user = $this->holder($this->position($this->alp, 6), 'employee');

        Livewire::actingAs($user)->test(Calendar::class)
            ->assertSeeHtml('data-testid="month-picker"')
            ->call('jumpTo', 2024, 2)
            ->assertSet('date', '2024-02-10')
            ->set('date', '2026-01-31')
            ->call('jumpTo', 2025, 2)
            ->assertSet('date', '2025-02-28')
            ->call('jumpTo', 2025, 0)
            ->assertSet('date', '2025-02-28');
    }

    public function test_a_volunteer_sees_their_holiday_names_but_cannot_add(): void
    {
        $user = $this->holder($this->position($this->alp, 6), 'employee');

        Livewire::actingAs($user)->test(Calendar::class)
            ->assertSee('Independence Day')
            ->assertSee('Study Zone Aleppo Day')
            ->assertDontSee(__('team_calendar.add_holiday'))
            ->assertDontSeeLivewire(HolidayModule::class);
    }

    public function test_a_volunteer_elsewhere_does_not_see_another_projects_holiday(): void
    {
        $user = $this->holder($this->position($this->syr, 6), 'employee');

        Livewire::actingAs($user)->test(Calendar::class)
            ->assertSee('Independence Day')
            ->assertDontSee('Study Zone Aleppo Day');
    }

    public function test_a_program_manager_sees_their_project_holidays_and_can_add(): void
    {
        $pm = $this->holder($this->headPosition, 'manager');

        Livewire::actingAs($pm)->test(Calendar::class)
            ->assertSee('Study Zone Aleppo Day')
            ->assertSee(__('team_calendar.add_holiday'))
            ->assertSeeLivewire(HolidayModule::class);
    }

    public function test_national_hr_can_add_from_the_calendar(): void
    {
        $hr = $this->makeUser($this->syr, 'HR-SYR', ['leaves.view-own']);

        Livewire::actingAs($hr)->test(Calendar::class)
            ->assertSee('Independence Day')
            ->assertSee('Study Zone Aleppo Day')
            ->assertSeeLivewire(HolidayModule::class);
    }

    public function test_a_day_opens_the_dialog_on_that_date(): void
    {
        $hr = $this->makeUser($this->syr, 'HR-SYR');

        Livewire::actingAs($hr)->test(HolidayModule::class, ['embedded' => true])
            ->call('createOn', '2026-11-24')
            ->assertSet('showModal', true)
            ->assertSet('item.start_date', '2026-11-24')
            ->assertSet('item.end_date', '2026-11-24')
            ->set('item.name_en', 'Added')
            ->set('item.name_ar', 'مضافة')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('holidaysChanged');

        $this->assertSame(1, Holiday::query()->where('name_en', 'Added')->count());
    }

    public function test_the_embedded_dialog_still_refuses_people_who_set_no_holidays(): void
    {
        Livewire::actingAs($this->makeUser($this->alp, 'HR-ALP'))
            ->test(HolidayModule::class, ['embedded' => true])
            ->assertForbidden();
    }

    private function position(City $city, int $grade, ?int $managerId = null): Position
    {
        return Position::query()->forceCreate([
            'department_id' => Department::query()->firstOrCreate(['code' => 'MGMT'], ['name' => 'Management'])->id,
            'city_id' => $city->id,
            'job_level_id' => JobLevel::query()->firstOrCreate(['grade' => $grade], ['name_en' => "G{$grade}", 'name_ar' => "G{$grade}"])->id,
            'program_id' => $this->studyZone->id,
            'direct_manager_id' => $managerId,
            'title_en' => "P {$city->slug} {$grade}", 'title_ar' => 'P',
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
        ]);
    }

    private function holder(Position $position, string $role): User
    {
        $employee = $this->makeEmployee($this->syr, 'h-'.uniqid());
        Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);

        return $this->makeUser($this->syr, $role, ['leaves.view-own', 'leaves.view-all'], $employee);
    }
}
