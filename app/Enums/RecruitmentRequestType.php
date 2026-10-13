<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * What a recruitment request asks for.

 * Unknown = 0 is reserved and never stored; it exists so that an unset column
 * cannot pass for a real request type.
 */
final class RecruitmentRequestType extends Enum
{
    const Unknown = 0;

    const FillVacancy = 1;

    const IncreaseHeadcount = 2;

    const DecreaseHeadcount = 3;

    const CreatePosition = 4;

    const ClosePosition = 5;

    const CloseVacancy = 6;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::FillVacancy => ['text' => ['en' => 'Fill vacancy', 'ar' => 'ملء شاغر'], 'color' => 'primary'],
            self::IncreaseHeadcount => ['text' => ['en' => 'Increase headcount', 'ar' => 'زيادة الملاك'], 'color' => 'primary'],
            self::DecreaseHeadcount => ['text' => ['en' => 'Reduce approved headcount', 'ar' => 'تخفيض الملاك المعتمد'], 'color' => 'secondary-medium'],
            self::CreatePosition => ['text' => ['en' => 'Create position', 'ar' => 'إنشاء منصب'], 'color' => 'primary'],
            self::ClosePosition => ['text' => ['en' => 'Close position', 'ar' => 'إغلاق منصب'], 'color' => 'secondary-medium'],
            self::CloseVacancy => ['text' => ['en' => 'Close vacancy', 'ar' => 'إغلاق شاغر'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير محدد'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
