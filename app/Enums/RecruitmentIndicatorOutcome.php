<?php

namespace App\Enums;

/**
 * What one matching indicator found.
 *
 * Unknown and NotApplicable are not misses: missing data, an older form
 * version that never asked the question, or a vacancy with no requirement
 * recorded are all left out of the count rather than held against anyone.
 * Info is for flags that must never count either way.
 */
final class RecruitmentIndicatorOutcome
{
    public const Met = 'met';

    public const Partial = 'partial';

    public const NotMet = 'not_met';

    public const Unknown = 'unknown';

    public const NotApplicable = 'not_applicable';

    public const Info = 'info';

    /** Outcomes that count towards "assessed". */
    public static function isAssessed(string $outcome): bool
    {
        return in_array($outcome, [self::Met, self::Partial, self::NotMet], true);
    }

    /** Whole class names, so Tailwind's scanner finds them. */
    public static function classes(string $outcome): string
    {
        return match ($outcome) {
            self::Met => 'bg-primary text-white',
            self::Partial => 'bg-white text-primary ring-1 ring-inset ring-primary',
            self::NotMet => 'bg-secondary-dark text-white',
            self::Info => 'bg-neutral-100 text-secondary-medium',
            default => 'bg-neutral-100 text-secondary-dark',
        };
    }
}
