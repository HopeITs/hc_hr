<?php

namespace App\Enums;

/**
 * A reviewer's own recommendation after an interview (Phase 2 Task 8).
 *
 * Advisory only: it is one participant's opinion and never the hiring
 * decision, which is made — and recorded — in the decision panel
 * (RecruitmentApplicationStatusService). Nothing reads this to move an
 * application.
 */
final class RecruitmentEvaluationRecommendation
{
    /**
     * Retired scale points. They are no longer offered or accepted, but
     * evaluation history written while they existed still carries them, so
     * they keep a label and a colour.
     */
    public const StrongYes = 'strong_yes';

    public const StrongNo = 'strong_no';

    public const Yes = 'yes';

    public const Maybe = 'maybe';

    public const No = 'no';

    /** @return array<int, string> in favour first */
    public static function values(): array
    {
        return [self::Yes, self::Maybe, self::No];
    }

    public static function classes(?string $value): string
    {
        return match ($value) {
            self::StrongYes => 'bg-primary text-white',
            self::Yes => 'bg-white text-primary ring-1 ring-inset ring-primary',
            self::Maybe => 'bg-neutral-100 text-secondary-dark',
            self::No => 'bg-white text-secondary-dark ring-1 ring-inset ring-secondary-light',
            self::StrongNo => 'bg-secondary-dark text-white',
            default => 'bg-neutral-100 text-secondary-dark',
        };
    }
}
