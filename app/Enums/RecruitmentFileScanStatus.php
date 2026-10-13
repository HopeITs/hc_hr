<?php

namespace App\Enums;

final class RecruitmentFileScanStatus
{
    public const Pending = 'pending';

    public const Clean = 'clean';

    public const Quarantined = 'quarantined';

    public const ScanFailed = 'scan_failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::Pending, self::Clean, self::Quarantined, self::ScanFailed];
    }
}
