<?php

namespace App\Enums;

/**
 * Where a notification can reach someone. Desktop and Mobile are both Web
 * Push underneath: the split is by the device a subscription belongs to
 * (PushSubscription::channel()), so a person can keep their phone quiet and
 * their laptop loud.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Email = 'email';

    public function label(): string
    {
        return __('notification-settings.channels.'.$this->value.'.label');
    }

    public function isPush(): bool
    {
        return $this === self::Desktop || $this === self::Mobile;
    }
}
