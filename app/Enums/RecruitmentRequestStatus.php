<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * The lifecycle of a recruitment request.

 * Values are frozen: they are in the database and carry no ordering meaning,
 * so nothing may compare them with > or <. Draft = 0 is the default, the same
 * shape as LeaveRequestStatus::Pending.
 */
final class RecruitmentRequestStatus extends Enum
{
    const Draft = 0;

    const Submitted = 1;

    const UnderReview = 2;

    const ChangesRequested = 3;

    const Resubmitted = 4;

    const Approved = 5;

    const Rejected = 6;

    const Cancelled = 7;

    const Applied = 8;

    const FailedApplication = 9;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Submitted => ['text' => ['en' => 'Submitted', 'ar' => 'مُرسَل'], 'color' => 'secondary-medium'],
            self::UnderReview => ['text' => ['en' => 'Under review', 'ar' => 'قيد المراجعة'], 'color' => 'primary'],
            self::ChangesRequested => ['text' => ['en' => 'Changes requested', 'ar' => 'مطلوب تعديل'], 'color' => 'secondary-medium'],
            self::Resubmitted => ['text' => ['en' => 'Resubmitted', 'ar' => 'أُعيد الإرسال'], 'color' => 'secondary-medium'],
            self::Approved => ['text' => ['en' => 'Approved', 'ar' => 'موافَق عليه'], 'color' => 'primary'],
            self::Rejected => ['text' => ['en' => 'Rejected', 'ar' => 'مرفوض'], 'color' => 'secondary-medium'],
            self::Cancelled => ['text' => ['en' => 'Cancelled', 'ar' => 'ملغى'], 'color' => 'secondary-light'],
            self::Applied => ['text' => ['en' => 'Applied', 'ar' => 'مُطبَّق'], 'color' => 'primary'],
            self::FailedApplication => ['text' => ['en' => 'Application failed', 'ar' => 'فشل التطبيق'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Draft', 'ar' => 'مسودة'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
