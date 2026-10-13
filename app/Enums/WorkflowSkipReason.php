<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Why a step was skipped.

 * Value 3 (NoManagerOnPosition) is retired and must not be reused: a position
 * with no manager whose holder does not hold submit-without-manager has its
 * submission rejected rather than the step skipped (D28).
 */
final class WorkflowSkipReason extends Enum
{
    const SelfApprovalEscalated = 1;

    const TopLevelRequester = 2;

    /** The requester is the only person who could have decided this step. */
    const RequesterIsApprover = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::SelfApprovalEscalated => ['text' => ['en' => "Escalated to the approver's manager", 'ar' => 'صُعِّدت لمدير الموافِق'], 'color' => 'secondary-medium'],
            self::TopLevelRequester => ['text' => ['en' => 'Requester is at the top of the org chart', 'ar' => 'المُقدِّم على رأس الهيكل'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
