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
            self::GENDER_MALE => ['key' => 'leaves.gender.male', 'color' => 'secondary-medium'],
            self::GENDER_FEMALE => ['key' => 'leaves.gender.female', 'color' => 'secondary-medium'],
            default => ['key' => 'leaves.gender.any', 'color' => 'secondary-dark'],
        };
        $result = [
            'value' => __($val['key']),
            'color' => $val['color'],
        ];

        return $result;

    }

    public static function getMaritalStatus(?int $status): array
    {

        $val = match ($status) {
            self::MARITAL_STATUS_SINGLE => ['key' => 'leaves.marital.single', 'color' => 'secondary-medium'],
            self::MARITAL_STATUS_MARRIED => ['key' => 'leaves.marital.married', 'color' => 'secondary-light'],
            default => ['key' => 'leaves.marital.any', 'color' => 'secondary-dark'],
        };
        $result = [
            'value' => __($val['key']),
            'color' => $val['color'],
        ];

        return $result;

    }
}
