<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

final class RecruitmentCampaignLinkMode extends Enum
{
    /** Staff only: applicants sign in to HC-HR and apply as themselves. */
    const Internal = 'internal';

    const PublicOpen = 'public_open';

    const InvitationOnly = 'invitation_only';

    public static function getLocalizedName(string $value): array
    {
        $label = match ($value) {
            self::InvitationOnly => ['en' => 'Invitation only', 'ar' => 'للمدعوين فقط'],
            self::Internal => ['en' => 'Staff only', 'ar' => 'للموظفين فقط'],
            default => ['en' => 'Public link', 'ar' => 'رابط عام'],
        };

        return ['value' => $label[app()->getLocale()]];
    }
}
