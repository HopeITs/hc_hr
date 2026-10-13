<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * What an approver decided on a step.
 */
final class ApprovalDecision extends Enum
{
    const Approve = 1;

    const Reject = 2;

    const RequestChanges = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Approve => ['text' => ['en' => 'Approved', 'ar' => 'موافقة'], 'color' => 'primary'],
            self::Reject => ['text' => ['en' => 'Rejected', 'ar' => 'رفض'], 'color' => 'secondary-medium'],
            self::RequestChanges => ['text' => ['en' => 'Changes requested', 'ar' => 'طلب تعديل'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
