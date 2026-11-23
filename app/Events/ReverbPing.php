<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A health check for the realtime connection: sent by `reverb:ping` to one
 * user's private channel, logged by that user's open pages.
 *
 * Broadcast now rather than queued: the queue only runs once a minute (from
 * the scheduler), which would make the check meaningless.
 */
class ReverbPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'reverb.ping';
    }

    /** @return array{sent_at: string} */
    public function broadcastWith(): array
    {
        return ['sent_at' => now()->toIso8601String()];
    }
}
