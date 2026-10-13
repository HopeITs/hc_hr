<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Campaign state.

 * Published and Open are deliberately separate (D3): Published means the advert
 * is visible through the public link, Open means the application window is
 * actually accepting submissions. Draft and Disabled are the only two the
 * public never sees (D48).
 */
final class HiringCampaignStatus extends Enum
{
    const Draft = 0;

    const Published = 1;

    const Open = 2;

    const Closed = 3;

    const Expired = 4;

    const Disabled = 5;

    public static function getLocalizedName(int $value): array
    {
        $val = match ($value) {
            self::Published => ['text' => ['en' => 'Published', 'ar' => 'منشورة'], 'color' => 'secondary-medium'],
            self::Open => ['text' => ['en' => 'Open', 'ar' => 'مفتوحة'], 'color' => 'primary'],
            self::Closed => ['text' => ['en' => 'Closed', 'ar' => 'مغلقة'], 'color' => 'secondary-medium'],
            self::Expired => ['text' => ['en' => 'Expired', 'ar' => 'منتهية'], 'color' => 'secondary-medium'],
            self::Disabled => ['text' => ['en' => 'Disabled', 'ar' => 'معطّلة'], 'color' => 'secondary-light'],
            default => ['text' => ['en' => 'Draft', 'ar' => 'مسودة'], 'color' => 'secondary-light'],
        };

        return [
            'value' => $val['text'][app()->getLocale()],
            'color' => $val['color'],
        ];
    }
}
