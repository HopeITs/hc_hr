<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Which step of the approval path this is.

 * There is no approver slot: every step has exactly one approver (D16).
 */
final class WorkflowStepKey extends Enum
{
    const ManagerApproval = 1;

    const FinanceApproval = 2;

    const GovernorateHrApproval = 3;

    const HrSyriaDirectOpenApproval = 4;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::ManagerApproval => ['text' => ['en' => 'Manager approval', 'ar' => 'موافقة المدير'], 'color' => 'primary'],
            self::FinanceApproval => ['text' => ['en' => 'Financial approval', 'ar' => 'الموافقة المالية'], 'color' => 'primary'],
            self::GovernorateHrApproval => ['text' => ['en' => 'Governorate HR approval', 'ar' => 'موافقة HR المحافظة'], 'color' => 'primary'],
            self::HrSyriaDirectOpenApproval => ['text' => ['en' => 'HR Syria direct-open approval', 'ar' => 'موافقة HR سوريا على الفتح المباشر'], 'color' => 'primary'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
