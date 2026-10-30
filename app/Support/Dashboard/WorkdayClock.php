<?php

namespace App\Support\Dashboard;

use App\Enums\ScheduleMode;
use App\Models\Contract;
use App\Services\LeaveRequestPricing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The clock tile's HR line: today's working hours from the viewer's active
 * contract, in Damascus time, and what they mean right now ("Your day ends in
 * 2:15"). Only a fixed schedule with its day range and hours filled in is
 * read; anything else (flexible, unknown, half-filled, a night shift) is no
 * schedule at all, so the tile says nothing rather than guess.
 *
 * The browser keeps the line current from the same day (resources/js/dashboard/
 * clock.js, workLine), so the countdown never asks the server. A big clock
 * also draws how far through the day it is (progress) and the hours (hours);
 * the browser keeps the bar current the same way (clock.js, workProgress).
 */
class WorkdayClock
{
    public const TIMEZONE = 'Asia/Damascus';

    public function __construct(private LeaveRequestPricing $pricing) {}

    /**
     * Today, for this contract: the date (Y-m-d in Damascus), whether it is a
     * day off (outside the contract's day range, or an official holiday), the
     * working periods as [start, end] minutes of the day, and the name of the
     * official holiday it is, if any. Null when the schedule is unknown.
     *
     * @return array{date: string, off: bool, periods: list<array{0: int, 1: int}>, holiday: ?string}|null
     */
    public function today(?Contract $contract, ?CarbonInterface $now = null): ?array
    {
        if (! $contract || (int) $contract->schedule_mode !== ScheduleMode::Fixed) {
            return null;
        }

        $from = $contract->work_day_from;
        $to = $contract->work_day_to;
        $periods = $this->periods($contract);

        if ($from === null || $to === null || $from < 0 || $from > 6 || $to < 0 || $to > 6 || $periods === null) {
            return null;
        }

        $today = CarbonImmutable::instance($now ?? now())->setTimezone(self::TIMEZONE);
        $date = $today->toDateString();
        $day = CarbonImmutable::parse($date);   // the Damascus date, as a plain day

        // Sunday (0) to Saturday (6); a range like Saturday to Thursday wraps.
        $dow = $today->dayOfWeek;
        $working = $from <= $to ? $dow >= $from && $dow <= $to : $dow >= $from || $dow <= $to;

        // The same holidays as officialHolidayDates() (national and project ones,
        // never the birthday), read with their rows so the day can be named.
        $position = $contract->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id;
        $holiday = $this->pricing->holidaysOn(
            $position?->program_id ? (int) $position->program_id : null,
            $cityId !== null ? (int) $cityId : null,
            $day,
            $day,
        )[$date][0] ?? null;

        return [
            'date' => $date,
            'off' => ! $working || $holiday !== null,
            'periods' => $periods,
            'holiday' => $holiday ? (string) $holiday->name : null,
        ];
    }

    /**
     * What the day means at $minute (minutes since midnight, Damascus):
     * starts / resumes (before a period), ends / break (inside one; "ends"
     * for the last), outside (after the last), off. $left is the minutes to
     * go, for the first four.
     *
     * @param  array{date: string, off: bool, periods: list<array{0: int, 1: int}>, holiday?: ?string}|null  $day
     * @return array{key: string, left: ?int}|null
     */
    public static function line(?array $day, int $minute): ?array
    {
        if ($day === null) {
            return null;
        }

        if ($day['off']) {
            return ['key' => 'off', 'left' => null];
        }

        $last = count($day['periods']) - 1;

        foreach ($day['periods'] as $i => [$start, $end]) {
            if ($minute < $start) {
                return ['key' => $i === 0 ? 'starts' : 'resumes', 'left' => $start - $minute];
            }

            if ($minute < $end) {
                return ['key' => $i === $last ? 'ends' : 'break', 'left' => $end - $minute];
            }
        }

        return ['key' => 'outside', 'left' => null];
    }

    /**
     * How far through the working day $minute is, for the big clock's bar:
     * `done` working minutes so far (breaks not counted), `total` in the day,
     * `pct` of it (0 before the first period, 100 after the last). Null on a
     * day off or without a schedule.
     *
     * @param  array{date: string, off: bool, periods: list<array{0: int, 1: int}>, holiday?: ?string}|null  $day
     * @return array{done: int, total: int, pct: float}|null
     */
    public static function progress(?array $day, int $minute): ?array
    {
        if ($day === null || $day['off']) {
            return null;
        }

        $total = 0;
        $done = 0;

        foreach ($day['periods'] as [$start, $end]) {
            $total += $end - $start;
            $done += max(0, min($minute, $end) - $start);
        }

        return ['done' => $done, 'total' => $total, 'pct' => $total > 0 ? round($done / $total * 100, 1) : 0.0];
    }

    /**
     * The working hours as text, Latin digits: "09:00–16:30", or both periods
     * of a split day ("09:00–13:00 · 15:00–18:00"). Null without a schedule.
     *
     * @param  array{date: string, off: bool, periods: list<array{0: int, 1: int}>, holiday?: ?string}|null  $day
     */
    public static function hours(?array $day): ?string
    {
        if ($day === null) {
            return null;
        }

        $hhmm = fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

        return implode(' · ', array_map(fn (array $p): string => $hhmm($p[0]).'–'.$hhmm($p[1]), $day['periods']));
    }

    /** The line in the current language, e.g. "Your day starts in 1:20". */
    public static function text(?array $line): ?string
    {
        if ($line === null) {
            return null;
        }

        $time = $line['left'] === null ? '' : intdiv($line['left'], 60).':'.str_pad((string) ($line['left'] % 60), 2, '0', STR_PAD_LEFT);

        return __('dashboard.clock.'.$line['key'], ['time' => $time]);
    }

    /** @return list<array{0: int, 1: int}>|null */
    private function periods(Contract $contract): ?array
    {
        $first = $this->period($contract->work_hours_from, $contract->work_hours_to);

        if ($first === null) {
            return null;
        }

        if (blank($contract->work_hours_2_from) && blank($contract->work_hours_2_to)) {
            return [$first];
        }

        $second = $this->period($contract->work_hours_2_from, $contract->work_hours_2_to);

        // A half-filled or overlapping second period is not a schedule we can read.
        if ($second === null || $second[0] < $first[1]) {
            return null;
        }

        return [$first, $second];
    }

    /** "09:00[:00]" to "17:00" → [540, 1020]; null unless both are times and it ends later the same day. */
    private function period(?string $from, ?string $to): ?array
    {
        $start = $this->minute($from);
        $end = $this->minute($to);

        return $start !== null && $end !== null && $end > $start ? [$start, $end] : null;
    }

    private function minute(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
