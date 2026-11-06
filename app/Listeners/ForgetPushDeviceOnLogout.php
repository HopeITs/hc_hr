<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Logout;

/**
 * Signing out of a browser stops its pushes: the next person on a shared
 * computer must not get the previous one's notifications. The browser is
 * found by the hopehr_device cookie the subscribe endpoint sets (pipeline
 * step 7); only that user's rows for that device are removed.
 *
 * forget(), not handle(): see ApplyNotificationPreferences.
 */
class ForgetPushDeviceOnLogout
{
    public const COOKIE = 'hopehr_device';

    public function forget(Logout $event): void
    {
        $deviceId = request()->cookie(self::COOKIE);

        if (! $event->user instanceof User || ! is_string($deviceId) || $deviceId === '' || strlen($deviceId) > 36) {
            return;
        }

        $event->user->pushSubscriptions()->where('device_id', $deviceId)->delete();
    }
}
