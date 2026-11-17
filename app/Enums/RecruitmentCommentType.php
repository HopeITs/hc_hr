<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Who a comment on a request is addressed to.
 */
final class RecruitmentCommentType extends Enum
{
    const General = 1;

    const DecisionReason = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::DecisionReason => ['text' => ['en' => 'Decision reason', 'ar' => 'سبب القرار'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'General', 'ar' => 'عام'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
