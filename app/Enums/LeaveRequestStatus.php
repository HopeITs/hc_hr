<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class LeaveRequestStatus extends Enum
{
    const Pending = 0;

    const Accepted = 1;

    const Rejected = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Accepted => ['key' => 'leaves.status.accepted', 'color' => 'primary'],
            self::Rejected => ['key' => 'leaves.status.rejected', 'color' => 'secondary-medium'],
            default => ['key' => 'leaves.status.pending', 'color' => 'secondary-light'],
        };
        $result = [
            'value' => __($val['key']),
            'color' => $val['color'],
        ];

        return $result;
    }
}
