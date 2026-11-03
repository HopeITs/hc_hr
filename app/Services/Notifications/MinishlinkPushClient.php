<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push through minishlink/web-push with the VAPID pair from
 * config('webpush.vapid'). The WebPush object is built on first use, not in
 * the constructor: it validates the keys, and an environment without them
 * must still be able to resolve this class (WebPushSender::configured()
 * guards the actual sending).
 */
class MinishlinkPushClient implements PushClient
{
    private ?WebPush $webPush = null;

    public function send(PushSubscription $subscription, string $payload, array $options = []): array
    {
        try {
            $report = $this->webPush()->sendOneNotification(
                new Subscription(
                    $subscription->endpoint,
                    $subscription->public_key,
                    $subscription->auth_token,
                    $subscription->content_encoding ?: 'aes128gcm',
                ),
                $payload,
                $options,
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'expired' => false];
        }

        return $this->normalise($report);
    }

    /** @return array{ok: bool, status: ?int, error: ?string, expired: bool} */
    private function normalise(MessageSentReport $report): array
    {
        $status = $report->getResponse()?->getStatusCode();

        return [
            'ok' => $report->isSuccess(),
            'status' => $status,
            'error' => $report->isSuccess() ? null : ($report->getReason() ?: 'push_failed'),
            'expired' => $report->isSubscriptionExpired(),
        ];
    }

    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush(
            ['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]],
            ['TTL' => config('webpush.ttl')],
            // Guzzle has no timeout of its own: one slow push service must
            // not hold a queue worker for minutes.
            new Client(['timeout' => 10, 'connect_timeout' => 5]),
        );
    }
}
