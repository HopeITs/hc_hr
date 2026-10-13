<?php

namespace App\Enums;

class LeaveType
{
    const GENDER_ANY = 0;

    const GENDER_MALE = 1;

    const GENDER_FEMALE = 2;

    const MARITAL_STATUS_ANY = 0;

    const MARITAL_STATUS_SINGLE = 1;

    const MARITAL_STATUS_MARRIED = 2;

    public static function getGender(?int $status): array
    {

        $val = match ($status) {
            self::GENDER_MALE => ['text' => ['en' => 'Male', 'ar' => 'ذكر'], 'color' => 'blue'],
            self::GENDER_FEMALE => ['text' => ['en' => 'Female', 'ar' => 'انثى'], 'color' => 'yellow'],
            default => ['text' => ['en' => 'All', 'ar' => 'الكل '], 'color' => 'green'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;

    }

    public static function getMaritalStatus(?int $status): array
    {

        $val = match ($status) {
            self::MARITAL_STATUS_SINGLE => ['text' => ['en' => 'Single', 'ar' => 'أعزب'], 'color' => 'yellow'],
            self::MARITAL_STATUS_MARRIED => ['text' => ['en' => 'Married', 'ar' => 'متزوج'], 'color' => 'gray'],
            default => ['text' => ['en' => 'All', 'ar' => 'الكل '], 'color' => 'green'],
        };
        $result = [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];

        return $result;

    }
}
