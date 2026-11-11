<?php

namespace App\Livewire\Statistics;

use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Models\Center;
use App\Models\City;
use App\Models\Program;
use App\Services\Statistics\ContractStatsCards;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ContractStatistics extends Component
{
    use ScopesToAuthorizedCity;

    public ?int $selectedCity = null;

    public ?int $selectedProgram = null;

    public $cities = [];

    public $programs = [];

    public $cards = [];

    public string $cardType = 'program';

    public function mount(): void
    {
        $cityIds = $this->authorizedCityIds();

        $this->cities = City::where('is_active', true)
            ->when($cityIds !== null, fn ($q) => $q->whereKey($cityIds))
            ->get();

        $this->programs = Program::where('is_active', true)
            ->orderby('name')
            ->get();

        $this->enforceCityScope();
    }

    public function loadData(): void
    {
        $this->enforceCityScope();

        $this->cards = [];
        $stats = app(ContractStatsCards::class);
        $city = $this->selectedCity ?: null;

        if (! $this->selectedProgram) {
            // One card per program (in the chosen city, if any).
            $this->cardType = 'program';
            $programs = Program::where('is_active', true)
                ->when($city, fn ($q) => $q->whereHas('positions', fn ($q) => $q->where('city_id', $city)))
                ->get();

            foreach ($programs as $program) {
                $this->cards[] = [
                    // Every city: the short slug, as the page always showed; one city: the name.
                    'name' => $city ? $program->name : $program->slug,
                    'program_slug' => $program->slug,
                    'tile' => DashboardLayout::contractStatKey($program->id, 0, $city ?? 0),
                ] + $stats->program($program->id, $city);
            }

            return;
        }

        // A program chosen: one card per center it runs in (in the chosen city, if any).
        $this->cardType = 'center';
        $centers = Center::where('is_active', true)
            ->when($city, fn ($q) => $q->where('city_id', $city))
            ->whereHas('positions', fn ($q) => $q->where('program_id', $this->selectedProgram))
            ->get();

        foreach ($centers as $center) {
            $this->cards[] = [
                'name' => $center->name,
                'center_id' => $center->id,
                'tile' => DashboardLayout::contractStatKey($this->selectedProgram, $center->id, $city ?? 0),
            ] + $stats->center($center->id, $this->selectedProgram, $city);
        }
    }

    public function resetFilters(): void
    {
        $this->selectedCity = null;
        $this->selectedProgram = null;

        // "Clear filters" must not become a way back to nationwide figures.
        $this->enforceCityScope();
    }

    public function render(): View
    {
        $this->loadData();

        return view('livewire.dashboard.contract-statistics', [
            'cards' => $this->cards,
            'cities' => $this->cities,
            'programs' => $this->programs,
            'cardType' => $this->cardType,
        ]);
    }

    /**
     * Pin the city filter for regionally scoped roles.
     *
     * selectedCity is a public property, so it is client-writable, and null
     * meant "every city" — the branch a regional user landed on by default.
     * That produced nationwide contract, employee, and salary totals for a
     * user entitled to one city. Re-applied at the top of loadData() so a
     * tampered value is overwritten before any aggregate is computed, not just
     * at mount time.
     */
    private function enforceCityScope(): void
    {
        $cityIds = $this->authorizedCityIds();

        // A role covering several cities may switch between them, one at a
        // time; anything else falls back to the first of its own.
        if ($cityIds !== null && ! in_array($this->selectedCity, $cityIds, true)) {
            $this->selectedCity = $cityIds[0] ?? 0;
        }
    }
}
