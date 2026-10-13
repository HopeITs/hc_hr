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
            self::FullTime => ['text' => ['en' => 'Full Time', 'ar' => 'دوام كامل'], 'color' => 'secondary-dark'],
            self::PartTime => ['text' => ['en' => 'Part Time', 'ar' => 'دوام جزئي'], 'color' => 'secondary-medium'],
            self::Freelance => ['text' => ['en' => 'Freelance', 'ar' => 'دوام حر'], 'color' => 'secondary-medium'],
            self::Draft => ['text' => ['en' => 'Draft', 'ar' => 'مسودة'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
