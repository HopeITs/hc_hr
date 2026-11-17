<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class SessionDuration extends Enum
{
    const Unknown = 0;

    const Hour = 1;

    const HourAndHalf = 2;

    const TwoHours = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Hour => ['text' => ['en' => 'Hour', 'ar' => 'ساعة'], 'color' => 'secondary-dark'],
            self::HourAndHalf => ['text' => ['en' => 'Hour and a Half', 'ar' => 'ساعة ونصف'], 'color' => 'secondary-medium'],
            self::TwoHours => ['text' => ['en' => 'Two Hours', 'ar' => 'ساعتان'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
