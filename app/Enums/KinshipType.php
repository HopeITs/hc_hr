<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class KinshipType extends Enum
{
    const Other = 0;

    const Parents = 1;

    const Spouse = 2;

    const Siblings = 3;

    const Children = 4;

    public static function getLocalizedName(int $value): string
    {
        $res = match ($value) {
            self::Parents => ['en' => 'Parents', 'ar' => 'الوالدان'],
            self::Spouse => ['en' => 'Spouse', 'ar' => 'الزوج/الزوجة'],
            self::Siblings => ['en' => 'Siblings', 'ar' => 'الإخوة'],
            self::Children => ['en' => 'Children', 'ar' => 'الأولاد'],
            default => ['en' => 'Other', 'ar' => 'آخر'],
        };

        return $res[app()->getLocale()] ?? '';
    }
}
