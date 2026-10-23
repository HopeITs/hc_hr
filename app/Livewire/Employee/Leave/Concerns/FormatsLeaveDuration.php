<?php

namespace App\Livewire\Employee\Leave\Concerns;

use App\Services\LeaveRequestPricing;

/**
 * Locale-aware wording for leave durations ("3 days", "يومان", "ساعة واحدة و30 دقيقة").
 *
 * Presentation only: callers keep computing the numbers exactly as before.
 * Counts go through trans_choice with integers only, so Arabic gets its full
 * plural set and fractional values never reach the plural selector.
 */
trait FormatsLeaveDuration
{
    protected function formatLeaveDays(int|float|string|null $days): string
    {
        if (! is_numeric($days)) {
            return (string) $days;
        }

        $days = (float) $days;

        if (floor($days) !== $days) {
            return __('leaves.duration.days-fraction', ['count' => $this->trimNumber($days)]);
        }

        return trans_choice('leaves.duration.days', (int) $days, ['count' => (int) $days]);
    }

    protected function formatLeaveHours(int|float|string|null $hours): string
    {
        if (! is_numeric($hours)) {
            return (string) $hours;
        }

        $hours = (float) $hours;
        $wholeHours = (int) floor($hours);
        $minutes = (int) round(($hours - $wholeHours) * 60);

        if ($minutes >= 60) {
            $wholeHours += intdiv($minutes, 60);
            $minutes %= 60;
        }

        $hoursText = trans_choice('leaves.duration.hours', $wholeHours, ['count' => $wholeHours]);

        if ($minutes === 0) {
            return $hoursText;
        }

        $minutesText = trans_choice('leaves.duration.minutes', $minutes, ['count' => $minutes]);

        if ($wholeHours === 0) {
            return $minutesText;
        }

        return __('leaves.duration.and', ['first' => $hoursText, 'second' => $minutesText]);
    }

    /**
     * An amount of hours as whole days plus the remaining hours and minutes
     * ("2 days and 3 hours"), with $hoursPerDay as the day length. Without a
     * day length (irregular or unknown schedule) it stays in hours.
     */
    protected function formatLeaveSplit(int|float|string|null $hours, ?float $hoursPerDay): string
    {
        [$days, $rest] = LeaveRequestPricing::splitDays((float) $hours, $hoursPerDay);

        if ($days === 0) {
            return $this->formatLeaveHours($rest);
        }

        $daysText = $this->formatLeaveDays($days);

        if ($rest === 0.0) {
            return $daysText;
        }

        return __('leaves.duration.and', ['first' => $daysText, 'second' => $this->formatLeaveHours($rest)]);
    }

    protected function formatLeaveAmount(int|float|string|null $amount, string $unit): string
    {
        return $unit === 'hour'
            ? $this->formatLeaveHours($amount)
            : $this->formatLeaveDays($amount);
    }

    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
