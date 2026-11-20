<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Models\Center;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractComposerStudyZoneTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    /**
     * The Study Zone program is identified by data (slug === 'SZ'), not by
     * name — locationOptions() must hand that slug down so
     * contract-composer.js::isStudyZoneProgram can key off it without a
     * second request.
     */
    public function test_location_options_include_each_programs_slug(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(function (array $options) use ($fixtures): bool {
                $studyZone = collect($options['programs'])->firstWhere('value', $fixtures['studyZoneProgram']->id);
                $core = collect($options['programs'])->firstWhere('value', $fixtures['program']->id);

                return $studyZone !== null && $studyZone['slug'] === 'SZ'
                    && $core !== null && $core['slug'] === 'core-program';
            });
    }

    /**
     * A Study Zone position has no center: the composer names the active
     * centers linked to the program in the city instead of "city-level".
     */
    public function test_a_position_without_a_center_carries_its_programs_centers_in_the_city(): void
    {
        $fixtures = $this->makeFixtures();
        $city = $fixtures['city'];
        $linked = fn (string $name, bool $active = true, $cityId = null) => Center::query()->create([
            'name_en' => $name, 'name_ar' => $name, 'city_id' => $cityId ?? $city->id, 'is_active' => $active,
        ])->programs()->attach($fixtures['studyZoneProgram']);

        $linked('Study Zone Armenian');
        $linked('Science Zone');
        $linked('Study Zone Closed', active: false);
        $linked('Study Zone Homs', cityId: $this->makeCity('HMS', '002')->id);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $city->id)
            ->assertReturned(function (array $options) use ($fixtures): bool {
                $positions = collect($options['positions']);

                return $positions->firstWhere('value', $fixtures['studyZonePosition']->id)['sharedCenters']
                        === __('general.shared-centers', ['centers' => 'Science Zone, Study Zone Armenian'])
                    && $positions->firstWhere('value', $fixtures['position']->id)['sharedCenters'] === null;
            });
    }

    public function test_without_linked_centers_a_position_stays_city_level(): void
    {
        $fixtures = $this->makeFixtures();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractCreate::class)
            ->call('locationOptions', $fixtures['city']->id)
            ->assertReturned(fn (array $options): bool => collect($options['positions'])
                ->firstWhere('value', $fixtures['studyZonePosition']->id)['sharedCenters'] === null);
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');

        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $studyZoneProgram = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'SZ', 'code' => 'SZ', 'is_active' => true]);

        $position = Position::query()->forceCreate([
            'center_id' => $center->id,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $program->id,
            'title_en' => 'Program Officer',
            'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        // Study Zone positions are city-level: no center.
        $studyZonePosition = Position::query()->forceCreate([
            'center_id' => null,
            'city_id' => $city->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'program_id' => $studyZoneProgram->id,
            'title_en' => 'Study Zone Coordinator',
            'title_ar' => 'منسق مساحة الدراسة',
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);

        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'studyZoneProgram', 'position', 'studyZonePosition', 'hr');
    }
}
