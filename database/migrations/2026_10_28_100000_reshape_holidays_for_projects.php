<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Holidays apply to the whole country or to projects (team-coverage-contract
 * §6.1, D-4 refinement): a project is a program in one governorate, so the
 * City/Program morph is replaced by a scope and a holiday_project link table.
 *
 * Existing rows are converted, never dropped:
 *  - attached to the national City (SYR)  → scope national;
 *  - attached to another City             → that city's projects;
 *  - attached to a Program                → that program's projects.
 * A converted row whose city/program has no project yet keeps scope
 * "projects" with no link, so it applies to nobody until HR picks projects —
 * narrowing, never widening, what it used to cover.
 *
 * Order matters on SQLite: dropping a column rebuilds `holidays`, and the
 * rebuild would cascade-delete holiday_project rows. So the old columns are
 * read into memory and dropped first; the link table is created after.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nationalCityId = DB::table('cities')->where('slug', 'SYR')->value('id');
        $grade = (int) config('team-coverage.project_manager_grade', 3);
        $projects = $this->projects($grade);

        $converted = [];

        foreach (DB::table('holidays')->get() as $row) {
            $links = [];
            $scope = 'projects';

            if (str_ends_with((string) $row->holidayable_type, 'City')) {
                if ((int) $row->holidayable_id === (int) $nationalCityId) {
                    $scope = 'national';
                } else {
                    $links = array_values(array_filter($projects, fn ($p) => $p['city_id'] === (int) $row->holidayable_id));
                }
            } elseif (str_ends_with((string) $row->holidayable_type, 'Program')) {
                $links = array_values(array_filter($projects, fn ($p) => $p['program_id'] === (int) $row->holidayable_id));
            }

            $converted[$row->id] = ['name' => $row->name, 'scope' => $scope, 'links' => $links];
        }

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropMorphs('holidayable');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->string('name_en')->default('');
            $table->string('name_ar')->default('');
            $table->string('scope', 16)->default('national')->index();
            $table->string('kind', 16)->default('official')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('holiday_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->unique(['holiday_id', 'program_id', 'city_id']);
            $table->index(['program_id', 'city_id']);
        });

        foreach ($converted as $id => $data) {
            DB::table('holidays')->where('id', $id)->update([
                'name_en' => (string) $data['name'],
                'name_ar' => (string) $data['name'],
                'scope' => $data['scope'],
            ]);

            foreach ($data['links'] as $link) {
                DB::table('holiday_project')->insert([
                    'holiday_id' => $id,
                    'program_id' => $link['program_id'],
                    'city_id' => $link['city_id'],
                ]);
            }
        }
    }

    /**
     * Restores the morph shape only while no row depends on the new one:
     * a project link or a grant/birthday cannot be expressed by the old
     * columns, and guessing would widen or drop a holiday.
     */
    public function down(): void
    {
        if (DB::table('holidays')->where('scope', '!=', 'national')->orWhere('kind', '!=', 'official')->exists()) {
            throw new RuntimeException('holidays has project, grant or birthday rows; they cannot be expressed by the old holidayable columns.');
        }

        $nationalCityId = DB::table('cities')->where('slug', 'SYR')->value('id');
        $names = DB::table('holidays')->pluck('name_en', 'id');

        Schema::dropIfExists('holiday_project');

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex(['scope']);
            $table->dropIndex(['kind']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_ar', 'scope', 'kind']);
        });

        Schema::table('holidays', function (Blueprint $table) use ($nationalCityId) {
            $table->string('name')->nullable();
            $table->string('holidayable_type')->default('App\\Models\\City');
            $table->unsignedBigInteger('holidayable_id')->default((int) $nationalCityId);
            $table->index(['holidayable_type', 'holidayable_id']);
        });

        foreach ($names as $id => $name) {
            DB::table('holidays')->where('id', $id)->update(['name' => $name]);
        }
    }

    /**
     * Program × governorate pairs headed by a project-manager position, the
     * governorate being the center's city, else the position's city.
     *
     * @return list<array{program_id: int, city_id: int}>
     */
    private function projects(int $grade): array
    {
        return DB::table('positions')
            ->join('job_levels', 'job_levels.id', '=', 'positions.job_level_id')
            ->leftJoin('centers', 'centers.id', '=', 'positions.center_id')
            ->where('job_levels.grade', $grade)
            ->whereNotNull('positions.program_id')
            // Plain selects, not selectRaw: the builder adds the table prefix
            // (hchr_ on MySQL) only to identifiers it parses itself.
            ->select('positions.program_id', 'positions.city_id as position_city_id', 'centers.city_id as center_city_id')
            ->get()
            ->map(fn ($p) => ['program_id' => (int) $p->program_id, 'city_id' => $p->center_city_id ?? $p->position_city_id])
            ->filter(fn ($p) => $p['city_id'] !== null)
            ->map(fn ($p) => ['program_id' => $p['program_id'], 'city_id' => (int) $p['city_id']])
            ->unique(fn ($p) => $p['program_id'].':'.$p['city_id'])
            ->values()
            ->all();
    }
};
