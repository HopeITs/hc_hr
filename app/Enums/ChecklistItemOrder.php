<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class ChecklistItemOrder extends Enum
{
    const Unknown = 0;

    const ProbationContract = 1;

    const AnnualContract = 2;

    const Resignation = 3;

    public static function getLocalizedName(int $value): array
    {

        $val = match ($value) {
            self::ProbationContract => ['text' => ['en' => 'Probation Contract', 'ar' => 'عقد تجريبي'], 'color' => 'green'],
            self::AnnualContract => ['text' => ['en' => 'Annual Contract', 'ar' => 'عقد سنوي (بعد التثبيت)'], 'color' => 'blue'],
            self::Resignation => ['text' => ['en' => 'Resignation / End of Service', 'ar' => 'استقالة / إنهاء خدمة'], 'color' => 'primary'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'gray'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
