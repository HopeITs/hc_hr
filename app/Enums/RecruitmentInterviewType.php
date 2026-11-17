<?php

namespace App\Enums;

/**
 * Why the interview is held (Phase 2 Task 7): an HR screening, a technical
 * assessment, or the final one. A candidate can have one of each, or more.
 */
final class RecruitmentInterviewType
{
    public const Hr = 'hr';

    public const Technical = 'technical';

    public const Final = 'final';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::Hr, self::Technical, self::Final];
    }
}
