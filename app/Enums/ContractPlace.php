<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class ContractPlace extends Enum
{
    const Unknown = 0;

    const Onsite = 1;

    const Online = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Onsite => ['text' => ['en' => 'Onsite', 'ar' => 'بالموقع'], 'color' => 'green'],
            self::Online => ['text' => ['en' => 'Online', 'ar' => 'عن بعد'], 'color' => 'blue'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'purple'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;
    }
}
