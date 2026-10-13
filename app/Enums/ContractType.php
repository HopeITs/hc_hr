<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class ContractType extends Enum
{
    const Unknown = 0;

    const FullTime = 1;

    const PartTime = 2;

    const Freelance = 3;

    const Draft = 4;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::FullTime => ['text' => ['en' => 'Full Time', 'ar' => 'دوام كامل'], 'color' => 'green'],
            self::PartTime => ['text' => ['en' => 'Part Time', 'ar' => 'دوام جزئي'], 'color' => 'blue'],
            self::Freelance => ['text' => ['en' => 'Freelance', 'ar' => 'دوام حر'], 'color' => 'yellow'],
            self::Draft => ['text' => ['en' => 'Draft', 'ar' => 'مسودة'], 'color' => 'gray'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'gray'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
