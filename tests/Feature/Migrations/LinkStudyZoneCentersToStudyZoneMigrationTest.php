<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkStudyZoneCentersToStudyZoneMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_study_and_science_zone_centers_are_linked_to_study_zone_only(): void
    {
        $cityId = $this->insert('cities', ['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $studyZone = $this->insert('programs', ['name' => 'Study Zone', 'code' => '006', 'slug' => 'SZ']);
        $micro = $this->insert('programs', ['name' => 'Micro Project', 'code' => '002', 'slug' => 'MP']);

        $armenian = $this->center('Study Zone Armenian', 'ستادي زون الأرمن', $cityId);
        $science = $this->center('Science Zone', 'ستادي زون ساينس حمص', $cityId);
        $closed = $this->center('Study Zone Al-Nouzha', 'ستادي زون النزهة', $cityId, active: false);
        $arabicOnly = $this->center('SZ Inshaat', 'ستادي زون الانشائات', $cityId);
        $hope = $this->center('Christian Hope Center', 'مركز الرجاء', $cityId);

        // Set by hand before the migration, and a link to another program.
        DB::table('center_program')->insert([
            ['center_id' => $armenian, 'program_id' => $studyZone],
            ['center_id' => $hope, 'program_id' => $micro],
        ]);

        $this->migration()->up();
        $this->migration()->up(); // Running twice adds nothing.

        $linked = DB::table('center_program')->where('program_id', $studyZone)->pluck('center_id')->sort()->values()->all();

        $this->assertSame([$armenian, $science, $closed, $arabicOnly], $linked);
        $this->assertSame(5, DB::table('center_program')->count());
        $this->assertDatabaseHas('center_program', ['center_id' => $hope, 'program_id' => $micro]);
    }

    public function test_nothing_happens_without_the_study_zone_program(): void
    {
        $cityId = $this->insert('cities', ['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $this->center('Study Zone Armenian', 'ستادي زون الأرمن', $cityId);

        $this->migration()->up();

        $this->assertSame(0, DB::table('center_program')->count());
    }

    private function center(string $en, string $ar, int $cityId, bool $active = true): int
    {
        return $this->insert('centers', [
            'name_en' => $en, 'name_ar' => $ar, 'city_id' => $cityId, 'is_active' => $active,
            'weekends' => '5', 'open_at' => '09:00:00', 'close_at' => '17:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_13_000002_link_study_zone_centers_to_study_zone.php');
    }
}
