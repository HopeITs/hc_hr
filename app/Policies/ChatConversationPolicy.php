<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;

/**
 * Who can see and act in a chat.
 *
 * Membership is the only key. There is deliberately no exception for any role
 * (super-admin, HR, a manager): nobody reads a private chat they are not in,
 * and a group is read by its current members only. Do not add a Gate::before
 * or a role check here.
 */
class ChatConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $conversation->participantFor($user) !== null;
    }

    /**
     * Send a message. In a private chat both people must still be active
     * employees: nobody can write to someone whose account is deactivated.
     */
    public function send(User $user, ChatConversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        if ($conversation->isPrivate()) {
            $other = $conversation->otherParticipantFor($user)?->user;

            return $other !== null && (bool) $other->profile?->is_active;
        }

        return true;
    }

    public function changeInfo(User $user, ChatConversation $conversation): bool
    {
        return $this->right($user, $conversation, 'can_change_info');
    }

    public function deleteOthersMessages(User $user, ChatConversation $conversation): bool
    {
        return $this->right($user, $conversation, 'can_delete_messages');
    }

    public function removeMembers(User $user, ChatConversation $conversation): bool
    {
        return $this->right($user, $conversation, 'can_remove_members');
    }

    public function addMembers(User $user, ChatConversation $conversation): bool
    {
        return $this->right($user, $conversation, 'can_add_members');
    }

    public function addAdmins(User $user, ChatConversation $conversation): bool
    {
        return $this->right($user, $conversation, 'can_add_admins');
    }

    /** Group administrator rights exist only in groups. */
    private function right(User $user, ChatConversation $conversation, string $right): bool
    {
        if (! $conversation->isGroup()) {
            return false;
        }

        $participant = $conversation->participantFor($user);

        return $participant instanceof ChatParticipant && $participant->hasRight($right);
    }
}
