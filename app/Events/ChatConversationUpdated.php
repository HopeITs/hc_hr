<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something changed in chat N": sent to each current member's own channel.
 *
 * Ids only, never text: the browser fetches the change from the site, where
 * the conversation policy checks it again. So the realtime server (and
 * anyone watching its traffic) never sees what was written.
 *
 * Broadcast now, not queued: the queue only runs once a minute.
 */
class ChatConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public const KIND_MESSAGE = 'message';

    public const KIND_EDITED = 'edited';

    public const KIND_DELETED = 'deleted';

    public const KIND_READ = 'read';

    /** @param list<int> $userIds */
    public function __construct(
        public readonly array $userIds,
        public readonly int $conversationId,
        public readonly ?int $messageId,
        public readonly string $kind,
        public readonly ?int $byUserId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('chat.user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }

    /** @return array{conversation_id: int, message_id: ?int, kind: string, by: ?int} */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'kind' => $this->kind,
            'by' => $this->byUserId,
        ];
    }
}
