<?php

namespace App\Services\Chat;

use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells a conversation's members, in realtime, that it changed.
 *
 * Sent only once the change is committed, so nobody is told about a message
 * that then rolls back. If the realtime server is down the change is still
 * saved: the failure is reported, and open chats fall back to asking the
 * site every few seconds until the connection is back.
 */
class ChatNotifier
{
    public function changed(ChatConversation $conversation, ?int $messageId, string $kind, ?int $byUserId = null): void
    {
        DB::afterCommit(function () use ($conversation, $messageId, $kind, $byUserId) {
            $userIds = $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            if ($userIds === []) {
                return;
            }

            try {
                event(new ChatConversationUpdated($userIds, $conversation->id, $messageId, $kind, $byUserId));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
