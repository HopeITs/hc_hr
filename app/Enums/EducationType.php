<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class EducationType extends Enum
{
    const Unknown = 0;

    const Primary_School = 1;

    const High_School = 2;

    const Institute = 3;

    const University = 4;

    const Master = 5;

    const PHD = 6;

    public static function getLocalizedName(int $value): string
    {
        $result = match ($value) {
            self::Primary_School => ['en' => 'Primary School', 'ar' => 'تعليم أساسي'],
            self::High_School => ['en' => 'High School', 'ar' => 'تعليم ثانوي'],
            self::Institute => ['en' => 'Institute', 'ar' => 'معهد'],
            self::University => ['en' => 'University', 'ar' => 'جامعة'],
            self::Master => ['en' => 'Master', 'ar' => 'ماجستير'],
            self::PHD => ['en' => 'PhD', 'ar' => 'دكتوراه'],
            default => ['en' => 'Unknown', 'ar' => 'غير معروف'],
        };

        return $result[app()->getLocale()] ?? '';
    }
}
