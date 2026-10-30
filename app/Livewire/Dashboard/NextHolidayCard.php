<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Holiday;
use App\Services\LeaveRequestPricing;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * The next official holiday for the viewer's contract, with a day countdown.
 * The holidays are LeaveRequestPricing's: the national ones and those of the
 * viewer's project and governorate, exactly the days the team calendar names
 * and the leave quote skips (the birthday holiday is personal and stays out).
 * Nothing within WINDOW days: the tile says so quietly instead of naming a
 * far-off day.
 */
class NextHolidayCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public const WINDOW = 60;

    public function render()
    {
        return view('livewire.dashboard.next-holiday-card', [
            'holiday' => $this->guardedLoad(fn () => $this->nextHoliday()),
        ]);
    }

    /** @return array{name: string, date: CarbonImmutable, last: CarbonImmutable, days: int, length: int}|null */
    protected function nextHoliday(): ?array
    {
        $position = auth()->user()?->profile?->latestActiveContract()?->position;

        if (! $position) {
            return null;
        }

        $today = CarbonImmutable::today();
        $cityId = $position->center?->city_id ?? $position->city_id;
        $byDate = app(LeaveRequestPricing::class)->holidaysOn(
            $position->program_id ? (int) $position->program_id : null,
            $cityId !== null ? (int) $cityId : null,
            $today,
            $today->addDays(self::WINDOW),
        );

        $first = array_key_first($byDate);

        if ($first === null) {
            return null;
        }

        /** @var Holiday $holiday */
        $holiday = $byDate[$first][0];
        $date = CarbonImmutable::parse($first);

        // A holiday of several days: count the run of consecutive dates it covers.
        $last = $date;
        while (collect($byDate[$last->addDay()->toDateString()] ?? [])->contains('id', $holiday->id)) {
            $last = $last->addDay();
        }

        return [
            'name' => (string) $holiday->name,
            'date' => $date,
            'last' => $last,
            'days' => (int) $today->diffInDays($date),
            'length' => (int) $date->diffInDays($last) + 1,
        ];
    }
}
