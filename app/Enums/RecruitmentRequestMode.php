<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Which path a request takes (D15).

 * Normal walks the full three-step approval path. DirectHr is for openings
 * arranged outside the system, where the approvals are documented as external
 * confirmations rather than collected electronically.
 */
final class RecruitmentRequestMode extends Enum
{
    const Normal = 1;

    const DirectHr = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::DirectHr => ['text' => ['en' => 'Direct HR opening', 'ar' => 'فتح مباشر'], 'color' => 'primary'],
            default => ['text' => ['en' => 'Normal', 'ar' => 'عادي'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
