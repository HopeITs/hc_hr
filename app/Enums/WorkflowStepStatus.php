<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * The state of one step inside a workflow run.
 */
final class WorkflowStepStatus extends Enum
{
    const Pending = 0;

    const Active = 1;

    const Approved = 2;

    const Rejected = 3;

    const Skipped = 4;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Active => ['text' => ['en' => 'Active', 'ar' => 'نشطة'], 'color' => 'primary'],
            self::Approved => ['text' => ['en' => 'Approved', 'ar' => 'موافَق عليها'], 'color' => 'primary'],
            self::Rejected => ['text' => ['en' => 'Rejected', 'ar' => 'مرفوضة'], 'color' => 'secondary-medium'],
            self::Skipped => ['text' => ['en' => 'Skipped', 'ar' => 'متجاوَزة'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Pending', 'ar' => 'بانتظار الدور'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
