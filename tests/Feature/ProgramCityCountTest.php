<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgramCityCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_card_counts_distinct_cities_from_centers(): void
    {
        $aleppo = $this->createCity('Aleppo', 'ALP', '001');
        $homs = $this->createCity('Homs', 'HMS', '002');
        $damascus = $this->createCity('Damascus', 'DMS', '003');

        $aleppoCenterOne = $this->createCenter('Aleppo One', $aleppo);
        $aleppoCenterTwo = $this->createCenter('Aleppo Two', $aleppo);
        $homsCenter = $this->createCenter('Homs One', $homs);

        $program = Program::query()->create([
            'name' => 'Children University',
            'code' => 'CU',
            'slug' => 'children-university',
        ]);
        $department = Department::query()->create([
            'name' => 'Programs',
            'code' => 'PRG',
        ]);
        $jobLevel = JobLevel::query()->create([
            'name_en' => 'Officer',
            'name_ar' => 'مسؤول',
        ]);

        $this->createPosition($program, $aleppoCenterOne, $aleppo, $department, $jobLevel, 'First');
        $this->createPosition($program, $aleppoCenterTwo, $damascus, $department, $jobLevel, 'Second');
        $this->createPosition($program, $homsCenter, $homs, $department, $jobLevel, 'Third');

        $programWithCounts = Program::query()->withCardCounts()->findOrFail($program->id);

        $this->assertSame(2, $programWithCounts->cities_count);

        $this->assertCardCountWorksWithTablePrefix($program);
    }

    public function test_centers_linked_to_the_program_count_their_cities(): void
    {
        $aleppo = $this->createCity('Aleppo', 'ALP', '001');
        $homs = $this->createCity('Homs', 'HMS', '002');
        $program = Program::query()->create(['name' => 'Study Zone', 'code' => '006', 'slug' => 'SZ']);

        // No position has a center: only the links say where the program is.
        $this->createCenter('Study Zone Armenian', $homs)->programs()->attach($program);
        $this->createCenter('Science Zone', $homs)->programs()->attach($program);
        $this->createCenter('Science Zone Aleppo', $aleppo)->programs()->attach($program);
        $this->createCenter('Hope Center', $aleppo);

        $this->assertSame(2, Program::query()->withCardCounts()->findOrFail($program->id)->cities_count);
    }

    private function createCity(string $name, string $slug, string $code): City
    {
        return City::query()->forceCreate([
            'name_en' => $name,
            'name_ar' => $name,
            'slug' => $slug,
            'code' => $code,
        ]);
    }

    private function createCenter(string $name, City $city): Center
    {
        return Center::query()->create([
            'name_en' => $name,
            'name_ar' => $name,
            'city_id' => $city->id,
        ]);
    }

    private function createPosition(
        Program $program,
        Center $center,
        City $directCity,
        Department $department,
        JobLevel $jobLevel,
        string $title,
    ): Position {
        return Position::query()->create([
            'program_id' => $program->id,
            'center_id' => $center->id,
            'city_id' => $directCity->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'title_en' => $title,
            'title_ar' => $title,
        ]);
    }

    private function assertCardCountWorksWithTablePrefix(Program $program): void
    {
        foreach (['programs', 'centers', 'positions', 'contracts', 'center_program'] as $table) {
            DB::statement("create temporary view hchr_{$table} as select * from {$table}");
        }

        $connection = DB::connection();
        $originalPrefix = $connection->getTablePrefix();

        try {
            $connection->setTablePrefix('hchr_');
            $programWithCounts = Program::query()->withCardCounts()->findOrFail($program->id);
        } finally {
            $connection->setTablePrefix($originalPrefix);
        }

        $this->assertSame(2, $programWithCounts->cities_count);
    }
}
