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
            self::Month => ['text' => ['en' => 'Month',     'ar' => 'شهر'],   'color' => 'secondary-dark'],
            self::Hour => ['text' => ['en' => 'Hour',      'ar' => 'ساعة'],   'color' => 'secondary-medium'],
            self::Session => ['text' => ['en' => 'Session', 'ar' => 'جلسة'],   'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Unknown',     'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
