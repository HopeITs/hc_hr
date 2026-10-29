<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * 2026_10_28_100000_reshape_holidays_for_projects converts rows written in
 * the old City/Program morph shape, never drops them, and never widens one.
 */
class HolidayMigrationTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_old_rows_become_national_or_project_holidays(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_reshape_holidays_for_projects.php');
        $choices = require database_path('migrations/2026_10_28_100100_create_birthday_holiday_choices_table.php');

        $syr = $this->makeCity(City::NATIONAL_SLUG, '000');
        $alp = $this->makeCity('ALP', '001');
        $hms = $this->makeCity('HMS', '002');
        $sz = Program::query()->forceCreate(['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'sz', 'is_active' => true]);
        $leap = Program::query()->forceCreate(['name' => 'LEAP', 'code' => 'LP', 'slug' => 'lp', 'is_active' => true]);
        $empty = Program::query()->forceCreate(['name' => 'Empty', 'code' => 'EM', 'slug' => 'em', 'is_active' => true]);

        foreach ([[$sz, $alp], [$sz, $hms], [$leap, $alp]] as [$program, $city]) {
            $this->projectManager($program, $city);
        }

        $choices->down();
        $migration->down();
        $this->assertTrue(Schema::hasColumn('holidays', 'holidayable_type'));

        $old = fn (string $name, string $type, int $id) => DB::table('holidays')->insertGetId([
            'name' => $name, 'start_date' => '2027-01-01', 'end_date' => '2027-01-01',
            'holidayable_type' => $type, 'holidayable_id' => $id, 'is_recurring' => false,
        ]);
        $national = $old('National', City::class, $syr->id);
        $aleppo = $old('Aleppo', City::class, $alp->id);
        $studyZone = $old('Study Zone', Program::class, $sz->id);
        $orphan = $old('Orphan', Program::class, $empty->id);

        $migration->up();
        $choices->up();

        $row = fn (int $id) => DB::table('holidays')->find($id);
        $links = fn (int $id) => DB::table('holiday_project')->where('holiday_id', $id)
            ->orderBy('program_id')->orderBy('city_id')->get(['program_id', 'city_id'])
            ->map(fn ($l) => [(int) $l->program_id, (int) $l->city_id])->all();

        $this->assertSame('national', $row($national)->scope);
        $this->assertSame('National', $row($national)->name_en);
        $this->assertSame('National', $row($national)->name_ar);

        $this->assertSame('projects', $row($aleppo)->scope);
        $this->assertSame([[$sz->id, $alp->id], [$leap->id, $alp->id]], $links($aleppo));

        $this->assertSame([[$sz->id, $alp->id], [$sz->id, $hms->id]], $links($studyZone));

        // No project for that program: kept, but applies to nobody (narrowed, not widened).
        $this->assertSame('projects', $row($orphan)->scope);
        $this->assertSame([], $links($orphan));

        $this->assertSame(4, DB::table('holidays')->count());
    }

    public function test_down_refuses_while_project_rows_exist(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_reshape_holidays_for_projects.php');

        DB::table('holidays')->insert(['name_en' => 'P', 'name_ar' => 'P', 'start_date' => '2027-01-01', 'end_date' => '2027-01-01', 'scope' => 'projects']);

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function projectManager(Program $program, City $city): void
    {
        Position::query()->forceCreate([
            'department_id' => Department::query()->firstOrCreate(['code' => 'MGMT'], ['name' => 'Management'])->id,
            'city_id' => $city->id,
            'job_level_id' => JobLevel::query()->firstOrCreate(['grade' => 3], ['name_en' => 'Project Manager', 'name_ar' => 'مدير المشروع'])->id,
            'program_id' => $program->id,
            'title_en' => 'PM', 'title_ar' => 'PM', 'max_emp' => 1, 'exist_emp' => 0, 'is_active' => true,
        ]);
    }
}
