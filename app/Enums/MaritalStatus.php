<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class MaritalStatus extends Enum
{
    const Unknown = 0;

    const Single = 1;

    const Engaged = 2;

    const Married = 3;

    const Divorced = 4;

    const Widowed = 5;

    const Separated = 6;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Single => [
                'text' => ['en' => 'Single', 'ar' => 'أعزب'],
                'color' => 'green',
            ],
            self::Engaged => [
                'text' => ['en' => 'Engaged', 'ar' => 'خاطب'],
                'color' => 'blue',
            ],
            self::Married => [
                'text' => ['en' => 'Married', 'ar' => 'متزوج'],
                'color' => 'blue',
            ],
            self::Divorced => [
                'text' => ['en' => 'Divorced', 'ar' => 'مطلق'],
                'color' => 'blue',
            ],
            self::Widowed => [
                'text' => ['en' => 'Widowed', 'ar' => 'أرمل'],
                'color' => 'blue',
            ],
            self::Separated => [
                'text' => ['en' => 'Separated', 'ar' => 'منفصل'],
                'color' => 'blue',
            ],
            default => [
                'text' => ['en' => 'Unknown', 'ar' => 'غير معروف'],
                'color' => 'blue',
            ],
        };

        return [
            'value' => $val['text'][app()->getLocale()] ?? $val['text']['en'],
            'color' => $val['color'],
        ];
    }
}
