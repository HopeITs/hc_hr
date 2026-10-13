<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * How an approval given outside the system was communicated (D43).
 */
final class ExternalConfirmationMethod extends Enum
{
    const Phone = 1;

    const Email = 2;

    const Meeting = 3;

    const Other = 4;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Phone => ['text' => ['en' => 'Phone', 'ar' => 'هاتف'], 'color' => 'secondary-light'],
            self::Email => ['text' => ['en' => 'Email', 'ar' => 'بريد إلكتروني'], 'color' => 'secondary-light'],
            self::Meeting => ['text' => ['en' => 'Meeting', 'ar' => 'اجتماع'], 'color' => 'secondary-light'],
            self::Other => ['text' => ['en' => 'Other', 'ar' => 'أخرى'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
