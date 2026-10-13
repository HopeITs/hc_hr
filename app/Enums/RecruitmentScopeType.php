<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * How wide a user's reach into recruitment data is.
 *
 * Scope answers "where", separately from the role that answers "what" and the
 * job level that answers "may they raise a request at all". All three are
 * checked; holding a permission without the scope to match it grants nothing.
 */
final class RecruitmentScopeType extends Enum
{
    /** Sees nothing. The default for anyone the resolver cannot place. */
    const None = 0;

    /** Own requests plus those of direct reports, via positions.direct_manager_id. */
    const DirectReports = 1;

    /** One governorate, resolved through Position::scopeInCity. */
    const City = 2;

    /** Every request in the organization. */
    const Organization = 3;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::DirectReports => ['text' => ['en' => 'Direct reports', 'ar' => 'المرؤوسون المباشرون'], 'color' => 'secondary-light'],
            self::City => ['text' => ['en' => 'Governorate', 'ar' => 'المحافظة'], 'color' => 'primary'],
            self::Organization => ['text' => ['en' => 'Organization', 'ar' => 'المنظمة'], 'color' => 'secondary-medium'],
            default => ['text' => ['en' => 'No access', 'ar' => 'لا وصول'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
