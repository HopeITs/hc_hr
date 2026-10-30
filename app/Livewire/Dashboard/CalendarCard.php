<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Services\Dashboard\CalendarFeed;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Component;
use Throwable;

/**
 * The calendar tile: a month of the viewer's HR events (CalendarFeed, which
 * gates each type), a dialog per day, and from 6×4 up a "Next 7 days" list
 * and a legend (container queries in dashboard.css, "calendar tile").
 *
 * Only the month is state, and it is client input: render() reads it back
 * through shown(), so a forged value can only show this month.
 */
class CalendarCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    /** The month shown, Y-m. */
    public string $month = '';

    public function mount(): void
    {
        $this->month = CarbonImmutable::today()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = $this->shown()->addMonthNoOverflow()->format('Y-m');
    }

    public function prev(): void
    {
        $this->month = $this->shown()->subMonthNoOverflow()->format('Y-m');
    }

    /** Straight to a month picked from the month picker. */
    public function jumpTo(int $year, int $month): void
    {
        if ($year >= 1900 && $year <= 2100 && $month >= 1 && $month <= 12) {
            $this->month = sprintf('%04d-%02d', $year, $month);
        }
    }

    public function goToday(): void
    {
        $this->month = CarbonImmutable::today()->format('Y-m');
    }

    public function render()
    {
        $user = auth()->user();
        $feed = app(CalendarFeed::class);
        $month = $this->shown();
        $today = CarbonImmutable::today();
        $weekEnd = $today->addDays(6);
        [$from, $to] = CalendarFeed::gridOf($month);

        [$days, $upcoming, $types] = $this->guardedLoad(function () use ($feed, $user, $month, $today, $weekEnd, $from, $to) {
            $days = $feed->forMonth($user, $month);
            // The grid usually holds the next seven days already; read them apart only when it does not.
            $source = $today->gte($from) && $weekEnd->lte($to) ? $days : $feed->forRange($user, $today, $weekEnd);
            $upcoming = array_filter($source, fn (string $date) => $date >= $today->toDateString() && $date <= $weekEnd->toDateString(), ARRAY_FILTER_USE_KEY);

            return [$days, $upcoming, $feed->typesFor($user)];
        }, [[], [], []]);

        $locale = app()->getLocale();
        $cells = [];
        $labels = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $events = $days[$date] ?? [];
            $long = $day->locale($locale)->translatedFormat('l j F Y');
            $labels[$date] = $long;

            $cells[] = [
                'date' => $date,
                'day' => $day->day,
                'inMonth' => $day->month === $month->month,
                'isToday' => $day->isSameDay($today),
                'markers' => array_values(array_unique(array_column($events, 'type'))),
                'label' => trans_choice('dashboard_calendar.day-label', count($events), ['date' => $long])
                    .($day->isSameDay($today) ? ' ('.__('dashboard_calendar.today-marker').')' : ''),
            ];
        }

        return view('livewire.dashboard.calendar-card', [
            'shown' => $month,
            'isCurrentMonth' => $month->format('Y-m') === $today->format('Y-m'),
            'today' => $today->toDateString(),
            'weekdays' => $this->weekdays(),
            'cells' => $cells,
            'days' => $days,
            'labels' => $labels,
            'upcoming' => $upcoming,
            'types' => $types,
            'canRequestLeave' => $feed->canRequestLeave($user),
            'requestUrl' => route('leaves.index', ['new' => 1, 'date' => '__DATE__']),
        ]);
    }

    /** The month in $month, or this month for anything else. */
    private function shown(): CarbonImmutable
    {
        try {
            return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1
                ? CarbonImmutable::createFromFormat('!Y-m', $this->month)
                : CarbonImmutable::today()->startOfMonth();
        } catch (Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }

    /**
     * Column headers, Monday first to match CalendarFeed::gridOf(). The
     * abbreviation comes from the lang file (Carbon's Arabic minimal names
     * mix one- and two-letter forms); the full name from Carbon.
     *
     * @return list<array{short: string, name: string}>
     */
    private function weekdays(): array
    {
        $monday = Carbon::now()->locale(app()->getLocale())->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))
            ->map(function (int $offset) use ($monday) {
                $day = $monday->copy()->addDays($offset);

                return [
                    'short' => __('dashboard.weekdays-min.'.$day->dayOfWeekIso),
                    'name' => $day->dayName,
                ];
            })
            ->all();
    }
}
