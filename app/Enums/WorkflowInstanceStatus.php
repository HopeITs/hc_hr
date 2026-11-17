<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * The state of one workflow run.

 * There is no Blocked value. It was dropped (D17) because it added nothing over
 * "the step has not been decided yet": a step with no assigned approver simply
 * stays active and the request stays under review.
 */
final class WorkflowInstanceStatus extends Enum
{
    const Running = 0;

    const Completed = 1;

    const Rejected = 2;

    const Cancelled = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Completed => ['text' => ['en' => 'Completed', 'ar' => 'مكتمل'], 'color' => 'primary'],
            self::Rejected => ['text' => ['en' => 'Rejected', 'ar' => 'مرفوض'], 'color' => 'secondary-medium'],
            self::Cancelled => ['text' => ['en' => 'Cancelled', 'ar' => 'ملغى'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Running', 'ar' => 'جارٍ'], 'color' => 'primary'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
