<?php

namespace Tests\Feature\Notifications\Fakes;

use App\Models\PushSubscription;
use App\Services\Notifications\PushClient;

/**
 * Stands in for the push service: records what would have been sent and
 * answers with a status chosen per endpoint (201 by default).
 */
class FakePushClient implements PushClient
{
    /** @var array<int, array{subscription_id: int, endpoint: string, message: array<string, mixed>, options: array<string, mixed>}> */
    public array $sent = [];

    /** @var array<string, int> */
    private array $statuses = [];

    public function respondWith(string $endpoint, int $status): self
    {
        $this->statuses[$endpoint] = $status;

        return $this;
    }

    public function send(PushSubscription $subscription, string $payload, array $options = []): array
    {
        $this->sent[] = [
            'subscription_id' => $subscription->id,
            'endpoint' => $subscription->endpoint,
            'message' => json_decode($payload, true),
            'options' => $options,
        ];

        $status = $this->statuses[$subscription->endpoint] ?? 201;
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? null : "HTTP {$status}",
            'expired' => in_array($status, [404, 410], true),
        ];
    }

    /** @return array<int, int> */
    public function sentTo(): array
    {
        return array_column($this->sent, 'subscription_id');
    }
}
