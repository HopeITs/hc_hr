<?php

namespace App\Livewire\Dashboard;

use App\Services\LeaveRequestPricing;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Component;

class CalendarCard extends Component
{
    public Carbon $current;

    /** @var array<string, true> Holiday dates on the viewer's own calendar, keyed Y-m-d. */
    private array $holidayDates = [];

    public function mount()
    {
        $this->current = Carbon::now();
    }

    public function next()
    {
        $this->current = $this->current->clone()->addMonth();
    }

    public function prev()
    {
        $this->current = $this->current->clone()->subMonth();
    }

    public function render()
    {
        $this->holidayDates = $this->loadHolidayDates();

        $startOfMonth = $this->current->clone()->startOfMonth();
        $startWeekday = $startOfMonth->dayOfWeekIso;
        $daysInMonth = $this->current->daysInMonth;

        $totalCells = 42;
        $prevDays = $startWeekday - 1;

        $calendar = collect();

        $prevMonth = $this->current->clone()->subMonth();
        $prevMonthDays = $prevMonth->daysInMonth;

        for ($i = $prevDays; $i > 0; $i--) {
            $date = $prevMonth->clone()->day($prevMonthDays - $i + 1);
            $calendar->push($this->formatDay($date, false));
        }

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $this->current->clone()->day($d);
            $calendar->push($this->formatDay($date, true));
        }

        $remaining = $totalCells - $calendar->count();
        $nextMonth = $this->current->clone()->addMonth();

        for ($i = 1; $i <= $remaining; $i++) {
            $date = $nextMonth->clone()->day($i);
            $calendar->push($this->formatDay($date, false));
        }

        return view('livewire.dashboard.calendar-card', [
            'calendar' => $calendar,
            'weekdays' => $this->weekdays(),
        ]);
    }

    /**
     * Column headers, Monday first to match the ISO offset used above.
     * The abbreviation comes from the lang file (Carbon's Arabic minimal
     * names mix one- and two-letter forms); the full name from Carbon.
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

    /**
     * National, city and program holidays for the viewer's active contract
     * across the six weeks the grid shows — the same rule leave pricing uses,
     * so a day marked here is a day a leave request will skip.
     *
     * @return array<string, true>
     */
    private function loadHolidayDates(): array
    {
        $contract = auth()->user()?->profile?->latestActiveContract();

        if (! $contract) {
            return [];
        }

        $from = CarbonImmutable::parse($this->current->toDateString())->startOfMonth()->subDays(7);
        $to = $from->addDays(55);

        return app(LeaveRequestPricing::class)->holidayDates($contract, $from, $to);
    }

    private function formatDay(Carbon $date, bool $isCurrentMonth)
    {
        $holidays = $this->holidayDates;
        $fakeEvents = config('samples.calendar.events', []);
        $fakeSaints = config('samples.calendar.saints', []);

        $iso = $date->toDateString();

        $isHoliday = isset($holidays[$iso]);
        $isSaint = array_key_exists($iso, $fakeSaints);
        $hasEvent = array_key_exists($iso, $fakeEvents);
        $isToday = $date->isToday();

        $numberColor = $isCurrentMonth ? 'text-secondary-light' : 'text-secondary-soft';
        $fontWeight = 'font-normal';
        $underline = false;
        $underlineColor = 'border-secondary-light';

        $circleFill = null;
        $circle = false;
        $textColorOverride = null;

        if ($isToday) {

            $circleFill = 'calendar-today-fill';
            $textColorOverride = 'text-white';

            if ($isSaint && ! $isHoliday) {
                $circleFill = 'bg-secondary-light';
                $fontWeight = 'font-extrabold';
            }

            if ($isHoliday) {
                $circleFill = 'bg-primary';
                $fontWeight = 'font-extrabold';
            }

            if ($hasEvent) {
                $underline = true;
                $underlineColor = 'border-white';
            }

        } else {

            if ($isHoliday) {
                $numberColor = 'text-primary';
                $fontWeight = 'font-extrabold';

                if ($hasEvent) {
                    $underline = true;
                }
            } elseif ($isSaint) {
                $numberColor = 'text-secondary-dark';
                $fontWeight = 'font-extrabold';

                if ($hasEvent) {
                    $underline = true;
                }
            } elseif ($hasEvent) {
                $underline = true;
            }
        }

        $tooltip = [];

        if ($isHoliday) {
            $tooltip[] = __('holidays.title');
        }

        if ($isSaint) {
            foreach ($fakeSaints[$iso] as $s) {
                $tooltip[] = $s;
            }
        }

        if ($hasEvent) {
            $events = is_array($fakeEvents[$iso]) ? $fakeEvents[$iso] : [$fakeEvents[$iso]];
            foreach ($events as $e) {
                $tooltip[] = $e;
            }
        }

        return [
            'date' => $date,
            'day' => $date->day,
            'isToday' => $isToday,

            'numberColor' => $textColorOverride ?? $numberColor,
            'fontWeight' => $fontWeight,

            'circle' => false,
            'circleFill' => $circleFill,

            'underline' => $underline,
            'underlineColor' => $underlineColor,

            'tooltip' => $tooltip,
        ];
    }
}
