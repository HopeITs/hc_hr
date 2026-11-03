<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;

/**
 * The wire to the browser's push service, kept behind an interface so the
 * tests can swap in a fake and never reach the network
 * (MinishlinkPushClient is the real one).
 */
interface PushClient
{
    /**
     * Send one encrypted message to one subscription.
     *
     * @param  array<string, mixed>  $options  TTL / urgency, as minishlink takes them
     * @return array{ok: bool, status: ?int, error: ?string, expired: bool}
     */
    public function send(PushSubscription $subscription, string $payload, array $options = []): array;
}
