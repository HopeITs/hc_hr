<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class SalaryPer extends Enum
{
    const Unknown = 0;

    const Month = 1;

    const Hour = 2;

    const Session = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Month => ['text' => ['en' => 'Month',     'ar' => 'شهر'],   'color' => 'green'],
            self::Hour => ['text' => ['en' => 'Hour',      'ar' => 'ساعة'],   'color' => 'blue'],
            self::Session => ['text' => ['en' => 'Session', 'ar' => 'جلسة'],   'color' => 'yellow'],
            default => ['text' => ['en' => 'Unknown',     'ar' => 'غير معروف'], 'color' => 'gray'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
