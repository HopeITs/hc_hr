<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The chat's per-person channel (ChatConversationUpdated): ids only, and only
// the person themself may listen.
Broadcast::channel('chat.user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// One conversation's members, for "typing…" (sent browser to browser, never
// stored). Membership only, through the conversation policy: no role exception.
Broadcast::channel('chat.conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = ChatConversation::query()->find($conversationId);

    return $conversation !== null && Gate::forUser($user)->allows('view', $conversation);
});

// Who is online now: every signed-in employee, while the chat is on. Only
// the user id is shared; names come from the site.
Broadcast::channel('chat.online', function (User $user) {
    return config('chat.enabled') && $user->profile?->is_active ? ['id' => $user->id] : false;
});
