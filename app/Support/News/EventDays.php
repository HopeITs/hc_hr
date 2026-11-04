<?php

namespace App\Support\News;

use App\Models\News;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The calendar days an event covers inside a window, in the app timezone,
 * with the time worth printing on each: the start (and the end, when it
 * finishes the same day) on its first day, nothing on the days after, and
 * nothing at all for an event that starts at midnight (a whole-day event).
 *
 * Both calendars read events through here (Dashboard\CalendarFeed and the
 * team calendar), so a multi-day event is spread the same way on each.
 */
final class EventDays
{
    /**
     * @return list<array{date: string, time: ?string, first: bool, last: bool}>
     */
    public static function within(News $event, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $event->event_starts_at) {
            return [];
        }

        $tz = config('app.timezone');
        $start = CarbonImmutable::instance($event->event_starts_at)->timezone($tz);
        $end = CarbonImmutable::instance($event->event_ends_at ?? $event->event_starts_at)->timezone($tz);
        $end = $end->lt($start) ? $start : $end;

        $firstDay = $start->startOfDay();
        $lastDay = $end->startOfDay();
        $windowFrom = CarbonImmutable::parse($from->toDateString());
        $windowTo = CarbonImmutable::parse($to->toDateString());

        $day = $firstDay->lt($windowFrom) ? $windowFrom : $firstDay;
        $last = $lastDay->gt($windowTo) ? $windowTo : $lastDay;
        $days = [];

        for (; $day->lte($last); $day = $day->addDay()) {
            $isFirst = $day->equalTo($firstDay);

            $days[] = [
                'date' => $day->toDateString(),
                'time' => $isFirst ? self::time($start, $end) : null,
                'first' => $isFirst,
                'last' => $day->equalTo($lastDay),
            ];
        }

        return $days;
    }

    /** "09:00" or "09:00–11:30"; null for a whole-day (midnight) start. */
    private static function time(CarbonImmutable $start, CarbonImmutable $end): ?string
    {
        if ($start->format('H:i') === '00:00') {
            return null;
        }

        $sameDayEnd = $end->isSameDay($start) && $end->gt($start) && $end->format('H:i') !== '00:00';

        return $start->format('H:i').($sameDayEnd ? '–'.$end->format('H:i') : '');
    }
}
