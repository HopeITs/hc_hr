<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class ScheduleMode extends Enum
{
    const Unknown = 0;

    const Fixed = 1;

    const Flexible = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Fixed => ['text' => ['en' => 'Fixed', 'ar' => 'ثابت'], 'color' => 'green'],
            self::Flexible => ['text' => ['en' => 'Flexible', 'ar' => 'مرن'], 'color' => 'blue'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'gray'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
