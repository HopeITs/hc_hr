<?php

namespace App\Support\Leave;

use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The period a leave request asks for, as LeaveRequestPricing reads it.
 *
 * Either a day range (start date to end date, end inclusive) or an hourly
 * window (one date plus a start and an end time). `exceptional` is the
 * requester's December exception flag (A3-D D-7).
 *
 * Immutable and free of any model: it can be built from form input before a
 * request exists. It does not validate the order of its bounds on purpose —
 * the pricing service reports an inverted window as `invalid_window`, so a
 * live preview never throws on half-typed input.
 */
final class LeaveWindow
{
    public const DAY = 'day';

    public const HOUR = 'hour';

    private function __construct(
        public readonly string $type,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly bool $exceptional,
    ) {}

    /**
     * A full-day range; both dates are counted (end inclusive).
     */
    public static function days(CarbonInterface|string $start, CarbonInterface|string $end, bool $exceptional = false): self
    {
        return new self(
            self::DAY,
            self::date($start),
            self::date($end),
            null,
            null,
            $exceptional,
        );
    }

    /**
     * An hourly window on one date. Times are "H:i" or "H:i:s".
     */
    public static function hours(CarbonInterface|string $date, string $startTime, string $endTime, bool $exceptional = false): self
    {
        $day = self::date($date);

        return new self(
            self::HOUR,
            $day,
            $day,
            self::time($startTime),
            self::time($endTime),
            $exceptional,
        );
    }

    /**
     * The window a saved request covers.
     */
    public static function fromRequest(LeaveRequest $request): self
    {
        $exceptional = (bool) ($request->is_exceptional ?? false);

        if ($request->type === self::HOUR) {
            return self::hours(
                $request->start_at,
                $request->start_at->format('H:i'),
                $request->end_at->format('H:i'),
                $exceptional,
            );
        }

        return self::days($request->start_at, $request->end_at, $exceptional);
    }

    public function isHourly(): bool
    {
        return $this->type === self::HOUR;
    }

    /**
     * Start of an hourly window as a moment (the date itself for a day range).
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->isHourly()
            ? $this->startDate->setTimeFromTimeString($this->startTime)
            : $this->startDate;
    }

    /**
     * End of an hourly window as a moment (the end date for a day range).
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->isHourly()
            ? $this->endDate->setTimeFromTimeString($this->endTime)
            : $this->endDate;
    }

    /**
     * Length of an hourly window in whole minutes (0 for a day range or an
     * inverted window).
     */
    public function minutes(): int
    {
        if (! $this->isHourly()) {
            return 0;
        }

        return max(0, self::minuteOfDay($this->endTime) - self::minuteOfDay($this->startTime));
    }

    /**
     * Is the window's end before its start (hourly: not after it)?
     */
    public function isInverted(): bool
    {
        if ($this->isHourly()) {
            return self::minuteOfDay($this->endTime) <= self::minuteOfDay($this->startTime);
        }

        return $this->endDate->lt($this->startDate);
    }

    /**
     * Minutes since midnight of an "H:i[:s]" string.
     */
    public static function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return $hours * 60 + $minutes;
    }

    private static function date(CarbonInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->startOfDay();
    }

    private static function time(string $value): string
    {
        [$hours, $minutes] = array_map('intval', array_pad(explode(':', trim($value)), 2, '0'));

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
