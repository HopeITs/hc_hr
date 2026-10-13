<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * The outcome recorded for one external confirmation (D43).

 * NotApplicable means this kind of approval does not apply to this case at all
 * — an unpaid volunteer role has no financial approval to document — not that
 * it is still outstanding. It carries a reason and no confirmer.
 */
final class ExternalConfirmationDecision extends Enum
{
    const Approved = 1;

    const NotApplicable = 2;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Approved => ['text' => ['en' => 'Approved', 'ar' => 'موافَق عليه'], 'color' => 'primary'],
            self::NotApplicable => ['text' => ['en' => 'Not applicable', 'ar' => 'لا ينطبق'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Unknown', 'ar' => 'غير معروف'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
