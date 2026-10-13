<?php

namespace App\Enums;

/**
 * Where an interview happens: at an address, or online through a meeting
 * link. The link lives in its own column so it can be withheld from anyone
 * not authorized to join; the address is ordinary interview detail.
 */
final class RecruitmentInterviewLocationType
{
    public const InPerson = 'in_person';

    public const Online = 'online';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::InPerson, self::Online];
    }
}
