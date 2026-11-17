<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\City;
use App\Services\Dashboard\WeatherFeed;
use App\Support\Dashboard\WorkdayClock;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * "Today at a glance": the weather now in the viewer's work city, an analogue
 * clock, the date and the time, as four small tiles in one card. The time is
 * Damascus time and ticks in the browser (resources/js/dashboard/clock.js,
 * the clock tile's own code): the server draws the first frame only. The
 * weather comes from WeatherFeed (cached per city, null when unavailable); the
 * city is the one of the viewer's current position, else their own record's.
 */
class GlanceCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        $now = CarbonImmutable::now(WorkdayClock::TIMEZONE);
        $city = $this->guardedLoad(fn () => $this->city());

        return view('livewire.dashboard.glance-card', [
            'config' => [
                'locale' => app()->getLocale() === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB',
                'zone' => WorkdayClock::TIMEZONE,
                'day' => null,
                'text' => ['aria' => __('dashboard.clock.aria')],
            ],
            // Syrian Arabic month names (تشرين الثاني), as the clock in the browser writes them: no change on the first tick.
            'now' => $now->locale(app()->getLocale() === 'ar' ? 'ar_SY' : app()->getLocale()),
            'city' => $city?->name,
            'weather' => $city ? rescue(fn () => app(WeatherFeed::class)->now($city), null, false) : null,
            'angles' => [
                'h' => ($now->hour % 12 + $now->minute / 60) * 30,
                'm' => ($now->minute + $now->second / 60) * 6,
                's' => $now->second * 6,
            ],
        ]);
    }

    private function city(): ?City
    {
        $employee = auth()->user()?->profile;
        $position = $employee?->latestActiveContract()?->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id ?? $employee?->city_id;

        return $cityId ? City::query()->find($cityId) : null;
    }
}
