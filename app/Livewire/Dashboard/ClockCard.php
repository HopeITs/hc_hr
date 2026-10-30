<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Support\Dashboard\WorkdayClock;
use Carbon\CarbonImmutable;
use IntlDateFormatter;
use Livewire\Component;

/**
 * Damascus time, digital or analogue (the look is the tile's style, chosen in
 * Customise), with the viewer's working day under it when their active
 * contract has a fixed schedule (WorkdayClock). The server draws the first
 * frame; from then on it ticks in the browser only (resources/js/dashboard/
 * clock.js), so the clock never calls the server.
 *
 * Made big (ported from media-request's big clock), it also draws how far
 * through the working day it is (a bar, time at work, the hours, the holiday's
 * name on an official holiday) and, taller still, who of the viewer's own
 * scope is online now (ClockPresence, which alone polls, once a minute while
 * on screen). On a phone it stays a one-row digital clock.
 */
class ClockCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        $now = CarbonImmutable::now(WorkdayClock::TIMEZONE);
        $day = $this->guardedLoad(fn () => app(WorkdayClock::class)->today(
            auth()->user()?->profile?->latestActiveContract(),
            $now,
        ));
        $minute = $now->hour * 60 + $now->minute;
        $line = WorkdayClock::line($day, $minute);
        $progress = WorkdayClock::progress($day, $minute);

        // Latin digits in both languages; weekday and month in the page's language.
        $intl = app()->getLocale() === 'ar' ? 'ar_SY@numbers=latn' : 'en_GB';
        $format = fn (string $pattern): string => (string) (new IntlDateFormatter($intl, IntlDateFormatter::FULL, IntlDateFormatter::NONE, WorkdayClock::TIMEZONE, null, $pattern))->format($now->getTimestamp());

        return view('livewire.dashboard.clock-card', [
            'config' => [
                'locale' => app()->getLocale() === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB',
                'zone' => WorkdayClock::TIMEZONE,
                'day' => $day,
                'text' => [
                    'aria' => __('dashboard.clock.aria'),
                    ...collect(['starts', 'resumes', 'ends', 'break', 'outside', 'off'])
                        ->mapWithKeys(fn (string $key) => [$key => __('dashboard.clock.'.$key)])->all(),
                    'done' => __('dashboard_clock.work.done'),
                    'notStarted' => __('dashboard_clock.work.not-started'),
                    'over' => __('dashboard_clock.work.over'),
                ],
            ],
            'time' => $now->format('H:i'),
            'weekday' => $format('EEEE'),
            'date' => $format('d MMMM y'),
            'line' => WorkdayClock::text($line),
            'progress' => $progress,
            'progressText' => match (true) {
                $progress === null => null,
                $minute < $day['periods'][0][0] => __('dashboard_clock.work.not-started'),
                $minute >= $day['periods'][array_key_last($day['periods'])][1] => __('dashboard_clock.work.over'),
                default => __('dashboard_clock.work.done', ['time' => intdiv($progress['done'], 60).':'.str_pad((string) ($progress['done'] % 60), 2, '0', STR_PAD_LEFT)]),
            },
            'hours' => WorkdayClock::hours($day),
            'angles' => [
                'h' => ($now->hour % 12 + $now->minute / 60) * 30,
                'm' => ($now->minute + $now->second / 60) * 6,
                's' => $now->second * 6,
            ],
        ]);
    }
}
