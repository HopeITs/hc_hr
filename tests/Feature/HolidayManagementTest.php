<?php

namespace Tests\Feature;

use App\Livewire\Basics\HolidayModule;
use App\Livewire\Basics\HolidayTable;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
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
 * The holidays screen (team-coverage-contract §6.1, H-1 … H-6).
 *
 * National HR (HR-SYR) sets national and project holidays, grants and the
 * birthday holiday. A program manager — whose position heads project-manager
 * positions — sets project holidays and grants for those projects only.
 */
class HolidayManagementTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $syr;

    private City $alp;

    private City $hms;

    private Program $studyZone;

    private Program $leap;

    private User $hr;

    /** Head of Study Zone: its ALP and HMS project managers report to them. */
    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syr = $this->makeCity(City::NATIONAL_SLUG, '000');
        $this->alp = $this->makeCity('ALP', '001');
        $this->hms = $this->makeCity('HMS', '002');
        $this->studyZone = Program::query()->forceCreate(['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'study-zone', 'is_active' => true]);
        $this->leap = Program::query()->forceCreate(['name' => 'LEAP', 'code' => 'LP', 'slug' => 'leap', 'is_active' => true]);

        $this->hr = $this->makeUser($this->syr, 'HR-SYR');

        $headPosition = $this->position($this->studyZone, $this->syr, grade: 2);
        $this->pm = $this->holder($headPosition, 'manager');

        // Study Zone × ALP and × HMS report to the head; LEAP × ALP does not.
        $this->position($this->studyZone, $this->alp, grade: 3, managerId: $headPosition->id);
        $this->position($this->studyZone, $this->hms, grade: 3, managerId: $headPosition->id);
        $this->position($this->leap, $this->alp, grade: 3);
    }

    public function test_national_hr_adds_a_national_holiday(): void
    {
        Livewire::actingAs($this->hr)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'Eid')
            ->set('item.name_ar', 'العيد')
            ->set('item.scope', 'national')
            ->set('item.start_date', '2027-03-10')
            ->set('item.end_date', '2027-03-12')
            ->call('save')
            ->assertHasNoErrors();

        $holiday = Holiday::sole();
        $this->assertTrue($holiday->isNational());
        $this->assertSame($this->hr->id, $holiday->created_by);
        $this->assertSame(0, $holiday->projects()->count());
    }

    public function test_national_hr_links_a_holiday_to_several_projects(): void
    {
        Livewire::actingAs($this->hr)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'Shared')
            ->set('item.name_ar', 'مشتركة')
            ->set('item.scope', 'projects')
            ->set('item.projects', [$this->key($this->studyZone, $this->alp), $this->key($this->leap, $this->alp)])
            ->set('item.start_date', '2027-06-01')
            ->set('item.end_date', '2027-06-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [[$this->studyZone->id, $this->alp->id], [$this->leap->id, $this->alp->id]],
            Holiday::sole()->projects->map(fn ($l) => [$l->program_id, $l->city_id])->all(),
        );
    }

    public function test_the_picker_groups_projects_by_governorate(): void
    {
        $groups = Livewire::actingAs($this->hr)->test(HolidayModule::class)
            ->call('create')
            ->set('item.scope', 'projects')
            ->viewData('projectGroups');

        $alp = collect($groups)->firstWhere('id', $this->alp->id);

        // The governorate header ticks exactly these: every project there, and only those.
        $this->assertEqualsCanonicalizing(
            [$this->key($this->leap, $this->alp), $this->key($this->studyZone, $this->alp)],
            array_column($alp['options'], 'value'),
        );
        $this->assertEqualsCanonicalizing(['LEAP', 'Study Zone'], array_column($alp['options'], 'label'));
    }

    public function test_program_manager_sees_only_their_projects_in_the_picker(): void
    {
        $groups = Livewire::actingAs($this->pm)->test(HolidayModule::class)
            ->call('create')
            ->viewData('projectGroups');

        $this->assertEqualsCanonicalizing(
            [$this->key($this->studyZone, $this->alp), $this->key($this->studyZone, $this->hms)],
            collect($groups)->flatMap(fn ($g) => array_column($g['options'], 'value'))->all(),
        );
    }

    public function test_program_manager_adds_a_grant_to_their_own_project(): void
    {
        Livewire::actingAs($this->pm)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'Team day')
            ->set('item.name_ar', 'يوم الفريق')
            ->set('item.kind', 'grant')
            ->set('item.projects', [$this->key($this->studyZone, $this->hms)])
            ->set('item.start_date', '2027-02-01')
            ->set('item.end_date', '2027-02-01')
            ->set('item.is_recurring', true)
            ->call('save')
            ->assertHasNoErrors();

        $holiday = Holiday::sole();
        $this->assertSame(Holiday::KIND_GRANT, $holiday->kind);
        $this->assertSame(Holiday::SCOPE_PROJECTS, $holiday->scope);
        // A one-off grant never repeats, whatever was posted.
        $this->assertFalse($holiday->is_recurring);
    }

    public function test_program_manager_cannot_reach_another_managers_project_or_national(): void
    {
        $component = Livewire::actingAs($this->pm)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'X')
            ->set('item.name_ar', 'X')
            ->set('item.start_date', '2027-02-01')
            ->set('item.end_date', '2027-02-01');

        $component->set('item.projects', [$this->key($this->leap, $this->alp)])->call('save')->assertHasErrors(['item.projects.0']);
        $component->set('item.scope', 'national')->set('item.projects', [])->call('save')->assertHasErrors(['item.scope']);
        $component->set('item.scope', 'projects')->set('item.kind', 'birthday')
            ->set('item.projects', [$this->key($this->studyZone, $this->alp)])->call('save')->assertHasErrors(['item.kind']);

        $this->assertSame(0, Holiday::count());
    }

    public function test_program_manager_cannot_change_national_or_shared_holidays(): void
    {
        $national = Holiday::query()->create(['name_en' => 'N', 'name_ar' => 'N', 'start_date' => '2027-05-01', 'end_date' => '2027-05-01', 'scope' => 'national']);
        $shared = Holiday::query()->create(['name_en' => 'S', 'name_ar' => 'S', 'start_date' => '2027-05-02', 'end_date' => '2027-05-02', 'scope' => 'projects']);
        $shared->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->alp->id]);
        $shared->projects()->create(['program_id' => $this->leap->id, 'city_id' => $this->alp->id]);

        Livewire::actingAs($this->pm)->test(HolidayModule::class)->call('edit', $national->id)->assertForbidden();
        Livewire::actingAs($this->pm)->test(HolidayModule::class)->call('edit', $shared->id)->assertForbidden();
        Livewire::actingAs($this->pm)->test(HolidayTable::class)->call('delete', ['id' => $shared->id])->assertForbidden();

        $this->assertNotNull($shared->fresh());
    }

    public function test_program_manager_table_shows_national_and_their_projects_only(): void
    {
        Holiday::query()->create(['name_en' => 'Nationwide Day', 'name_ar' => 'N', 'start_date' => '2027-05-01', 'end_date' => '2027-05-01', 'scope' => 'national']);
        $mine = Holiday::query()->create(['name_en' => 'Study Zone Day', 'name_ar' => 'S', 'start_date' => '2027-05-02', 'end_date' => '2027-05-02', 'scope' => 'projects']);
        $mine->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->hms->id]);
        $other = Holiday::query()->create(['name_en' => 'LEAP Day', 'name_ar' => 'L', 'start_date' => '2027-05-03', 'end_date' => '2027-05-03', 'scope' => 'projects']);
        $other->projects()->create(['program_id' => $this->leap->id, 'city_id' => $this->alp->id]);

        Livewire::actingAs($this->pm)->test(HolidayTable::class)
            ->assertSee('Nationwide Day')
            ->assertSee('Study Zone Day')
            ->assertDontSee('LEAP Day');
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        Livewire::actingAs($this->hr)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'Bad')
            ->set('item.name_ar', 'Bad')
            ->set('item.start_date', '2027-03-12')
            ->set('item.end_date', '2027-03-10')
            ->call('save')
            ->assertHasErrors(['item.end_date']);
    }

    public function test_plain_employees_and_managers_without_projects_cannot_open_the_screen(): void
    {
        Livewire::actingAs($this->makeUser($this->alp, 'employee'))
            ->test(HolidayModule::class)->assertForbidden();

        Livewire::actingAs($this->makeUser($this->alp, 'general-manager'))
            ->test(HolidayModule::class)->assertForbidden();
    }

    // --- City HR (HR-033): its own city's holidays only -----------------

    public function test_city_hr_opens_the_page_and_picks_only_its_own_citys_projects(): void
    {
        $this->actingAs($this->cityHr())->get(route('holidays.index'))->assertOk();

        $groups = Livewire::actingAs($this->cityHr())->test(HolidayModule::class)
            ->call('create')
            ->viewData('projectGroups');

        $this->assertSame([$this->alp->id], array_column($groups, 'id'));
        $this->assertEqualsCanonicalizing(
            [$this->key($this->studyZone, $this->alp), $this->key($this->leap, $this->alp)],
            array_column($groups[0]['options'], 'value'),
        );
    }

    public function test_city_hr_adds_edits_and_deletes_a_holiday_of_its_own_city(): void
    {
        $hr = $this->cityHr();

        Livewire::actingAs($hr)->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'Aleppo Day')
            ->set('item.name_ar', 'يوم حلب')
            ->set('item.projects', [$this->key($this->studyZone, $this->alp), $this->key($this->leap, $this->alp)])
            ->set('item.start_date', '2027-04-01')
            ->set('item.end_date', '2027-04-01')
            ->call('save')
            ->assertHasNoErrors();

        $holiday = Holiday::sole();
        $this->assertSame(Holiday::SCOPE_PROJECTS, $holiday->scope);
        $this->assertSame(2, $holiday->projects()->count());

        Livewire::actingAs($hr)->test(HolidayModule::class)
            ->call('edit', $holiday->id)
            ->set('item.name_en', 'Aleppo Day (moved)')
            ->set('item.start_date', '2027-04-02')
            ->set('item.end_date', '2027-04-02')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Aleppo Day (moved)', $holiday->fresh()->name_en);

        Livewire::actingAs($hr)->test(HolidayTable::class)->call('delete', ['id' => $holiday->id])->assertOk();
        $this->assertNull($holiday->fresh());
    }

    public function test_city_hr_is_refused_another_city_even_by_a_forged_call(): void
    {
        $component = Livewire::actingAs($this->cityHr())->test(HolidayModule::class)
            ->call('create')
            ->set('item.name_en', 'X')
            ->set('item.name_ar', 'X')
            ->set('item.start_date', '2027-02-01')
            ->set('item.end_date', '2027-02-01');

        // A project of Homs posted by hand, alone or next to one of Aleppo.
        $component->set('item.projects', [$this->key($this->studyZone, $this->hms)])->call('save')->assertHasErrors(['item.projects.0']);
        $component->set('item.projects', [$this->key($this->leap, $this->alp), $this->key($this->studyZone, $this->hms)])
            ->call('save')->assertHasErrors(['item.projects.1']);
        // Nor a national holiday, nor the birthday holiday.
        $component->set('item.scope', 'national')->set('item.projects', [])->call('save')->assertHasErrors(['item.scope']);
        $component->set('item.scope', 'projects')->set('item.kind', 'birthday')
            ->set('item.projects', [$this->key($this->leap, $this->alp)])->call('save')->assertHasErrors(['item.kind']);

        $this->assertSame(0, Holiday::count());

        // An existing Homs holiday: no edit, no save over it, no delete.
        $homs = Holiday::query()->create(['name_en' => 'Homs Day', 'name_ar' => 'H', 'start_date' => '2027-05-01', 'end_date' => '2027-05-01', 'scope' => 'projects']);
        $homs->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->hms->id]);

        Livewire::actingAs($this->cityHr())->test(HolidayModule::class)->call('edit', $homs->id)->assertForbidden();
        Livewire::actingAs($this->cityHr())->test(HolidayModule::class)
            ->set('item.id', $homs->id)
            ->set('item.name_en', 'Hijacked')
            ->set('item.name_ar', 'X')
            ->set('item.projects', [$this->key($this->leap, $this->alp)])
            ->set('item.start_date', '2027-05-01')
            ->set('item.end_date', '2027-05-01')
            ->call('save')
            ->assertForbidden();
        Livewire::actingAs($this->cityHr())->test(HolidayTable::class)->call('confirmDelete', $homs->id)->assertForbidden();
        Livewire::actingAs($this->cityHr())->test(HolidayTable::class)->call('delete', ['id' => $homs->id])->assertForbidden();

        $this->assertSame('Homs Day', $homs->fresh()->name_en);
    }

    public function test_city_hr_reads_organisation_wide_and_other_city_holidays_but_cannot_change_them(): void
    {
        $national = Holiday::query()->create(['name_en' => 'Nationwide Day', 'name_ar' => 'N', 'start_date' => '2027-05-01', 'end_date' => '2027-05-01', 'scope' => 'national']);
        $shared = Holiday::query()->create(['name_en' => 'Shared Day', 'name_ar' => 'S', 'start_date' => '2027-05-02', 'end_date' => '2027-05-02', 'scope' => 'projects']);
        $shared->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->alp->id]);
        $shared->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->hms->id]);
        $homs = Holiday::query()->create(['name_en' => 'Homs Day', 'name_ar' => 'H', 'start_date' => '2027-05-03', 'end_date' => '2027-05-03', 'scope' => 'projects']);
        $homs->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->hms->id]);

        Livewire::actingAs($this->cityHr())->test(HolidayTable::class)
            ->assertSee('Nationwide Day')
            ->assertSee('Shared Day')
            ->assertSee('Homs Day');

        foreach ([$national, $shared, $homs] as $holiday) {
            Livewire::actingAs($this->cityHr())->test(HolidayModule::class)->call('edit', $holiday->id)->assertForbidden();
            Livewire::actingAs($this->cityHr())->test(HolidayTable::class)->call('delete', ['id' => $holiday->id])->assertForbidden();
        }

        $this->assertSame(3, Holiday::count());
    }

    public function test_national_hr_and_project_managers_keep_their_reach(): void
    {
        $homs = Holiday::query()->create(['name_en' => 'Homs Day', 'name_ar' => 'H', 'start_date' => '2027-05-03', 'end_date' => '2027-05-03', 'scope' => 'projects']);
        $homs->projects()->create(['program_id' => $this->studyZone->id, 'city_id' => $this->hms->id]);

        Livewire::actingAs($this->hr)->test(HolidayModule::class)->call('edit', $homs->id)->assertOk();
        Livewire::actingAs($this->pm)->test(HolidayModule::class)->call('edit', $homs->id)->assertOk();
    }

    private function cityHr(): User
    {
        return $this->makeUser($this->alp, 'HR-ALP');
    }

    private function key(Program $program, City $city): string
    {
        return $program->id.':'.$city->id;
    }

    private function position(Program $program, City $city, int $grade, ?int $managerId = null): Position
    {
        $level = JobLevel::query()->firstOrCreate(['grade' => $grade], ['name_en' => "Grade {$grade}", 'name_ar' => "درجة {$grade}"]);

        return Position::query()->forceCreate([
            'department_id' => Department::query()->firstOrCreate(['code' => 'MGMT'], ['name' => 'Management'])->id,
            'city_id' => $city->id,
            'job_level_id' => $level->id,
            'program_id' => $program->id,
            'direct_manager_id' => $managerId,
            'title_en' => "{$program->name} {$city->slug} G{$grade}",
            'title_ar' => "{$program->name} {$city->slug}",
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }

    private function holder(Position $position, string $role): User
    {
        $employee = $this->makeEmployee($this->syr, 'holder-'.$position->id);
        $this->contract($employee, $position);

        return $this->makeUser($this->syr, $role, [], $employee);
    }

    private function contract(Employee $employee, Position $position): Contract
    {
        return Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);
    }
}
