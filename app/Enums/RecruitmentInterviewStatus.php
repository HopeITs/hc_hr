<?php

namespace App\Enums;

/**
 * Where an interview stands (Phase 2 Task 7).
 *
 * Scheduled and Rescheduled are the two open states: the interview is still
 * going to happen, reminders go out, and it can be moved or cancelled.
 * Cancelled, Completed and NoShow are final — an interview is never deleted,
 * and one that ended is never reopened; a new interview is scheduled instead.
 */
final class RecruitmentInterviewStatus
{
    public const Scheduled = 'scheduled';

    public const Rescheduled = 'rescheduled';

    public const Cancelled = 'cancelled';

    public const Completed = 'completed';

    public const NoShow = 'no_show';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::Scheduled => [self::Rescheduled, self::Cancelled, self::Completed, self::NoShow],
        self::Rescheduled => [self::Rescheduled, self::Cancelled, self::Completed, self::NoShow],
    ];

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::Scheduled, self::Rescheduled, self::Cancelled, self::Completed, self::NoShow];
    }

    /** @return array<int, string> */
    public static function open(): array
    {
        return [self::Scheduled, self::Rescheduled];
    }

    public static function isOpen(?string $status): bool
    {
        return in_array($status, self::open(), true);
    }

    public static function canMove(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Tailwind classes for the status badge. Brand colours only: the two
     * open states carry the red (Rescheduled as an outline, so the pair
     * stays apart), and the final states step down the greys.
     */
    public static function classes(?string $status): string
    {
        return match ($status) {
            self::Scheduled => 'bg-primary text-white',
            self::Rescheduled => 'bg-white text-primary ring-1 ring-inset ring-primary',
            self::Completed => 'bg-secondary-dark text-white',
            self::NoShow => 'bg-neutral-100 text-secondary-dark',
            default => 'bg-neutral-100 text-secondary-medium',
        };
    }

    /** The same scheme as classes(), for a status dot or round marker. */
    public static function dotClasses(?string $status): string
    {
        return match ($status) {
            self::Scheduled => 'bg-primary',
            self::Rescheduled => 'bg-white ring-2 ring-inset ring-primary',
            self::Completed => 'bg-secondary-dark',
            self::NoShow => 'bg-secondary-light',
            default => 'bg-secondary-soft',
        };
    }
}
