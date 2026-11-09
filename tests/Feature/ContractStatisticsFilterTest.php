<?php

namespace Tests\Feature;

use App\Livewire\Statistics\ContractStatistics;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractStatisticsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_filters_reach_the_server_when_chosen(): void
    {
        // select2-input entangles its wire:model, and @entangle only syncs on
        // .live: with .lazy the choice never left the browser.
        $view = file_get_contents(resource_path('views/livewire/dashboard/contract-statistics.blade.php'));

        $this->assertStringContainsString('wire:model.live="selectedCity"', $view);
        $this->assertStringContainsString('wire:model.live="selectedProgram"', $view);
    }

    public function test_the_city_filter_counts_positions_placed_through_their_center(): void
    {
        $aleppo = City::query()->forceCreate(['name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001']);
        $homs = City::query()->forceCreate(['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $damascus = City::query()->forceCreate(['name_en' => 'Damascus', 'name_ar' => 'دمشق', 'slug' => 'DMS', 'code' => '003']);
        $center = Center::query()->create(['name_en' => 'Aleppo One', 'name_ar' => 'حلب ١', 'city_id' => $aleppo->id]);

        $program = Program::query()->create(['name' => 'Hope Center', 'code' => 'HC', 'slug' => 'hc']);
        $department = Department::query()->create(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->create(['name_en' => 'Officer', 'name_ar' => 'مسؤول']);

        // Recorded under Homs, but its center is in Aleppo: Position::inCity
        // counts it in either city.
        $position = Position::query()->create([
            'program_id' => $program->id,
            'center_id' => $center->id,
            'city_id' => $homs->id,
            'department_id' => $department->id,
            'job_level_id' => $jobLevel->id,
            'title_en' => 'Teacher',
            'title_ar' => 'معلم',
        ]);
        $type = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual Contract', 'name_ar' => 'عقد سنوي']);
        Contract::factory()->create(['position_id' => $position->id, 'contract_type_id' => $type->id, 'is_active' => true]);

        $this->actingAs(User::factory()->create());

        $cards = fn ($component) => collect($component->get('cards'))->pluck('employees', 'name')->all();

        $component = Livewire::test(ContractStatistics::class)->set('selectedCity', $aleppo->id);
        $this->assertSame(['Hope Center' => 1], $cards($component));

        $component->set('selectedCity', $damascus->id);
        $this->assertSame([], $cards($component));
    }
}
