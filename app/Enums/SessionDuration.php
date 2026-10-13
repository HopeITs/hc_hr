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
            self::Hour => ['text' => ['en' => 'Hour', 'ar' => 'ساعة'], 'color' => 'green'],
            self::HourAndHalf => ['text' => ['en' => 'Hour and a Half', 'ar' => 'ساعة ونصف'], 'color' => 'blue'],
            self::TwoHours => ['text' => ['en' => 'Two Hours', 'ar' => 'ساعتان'], 'color' => 'yellow'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'gray'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
