<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat: private (exactly two people) or a group.
 *
 * Who may read it is decided by membership alone (ChatConversationPolicy):
 * no role, including super-admin and HR, can see a conversation they are not
 * a current member of.
 */
class ChatConversation extends Model
{
    public const TYPE_PRIVATE = 'private';

    public const TYPE_GROUP = 'group';

    // "messenger_", not "chat_": an earlier, unrelated chat experiment left
    // chat_* tables in some databases.
    protected $table = 'messenger_conversations';

    protected $fillable = ['type', 'title', 'owner_id', 'direct_key', 'last_message_id', 'last_message_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /** The key that makes a private chat between two people unique. */
    public static function directKey(int $userA, int $userB): string
    {
        return min($userA, $userB).':'.max($userA, $userB);
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isPrivate(): bool
    {
        return $this->type === self::TYPE_PRIVATE;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Everyone who was ever a member, including those who left. */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    /** The current membership row of this user, or null. */
    public function participantFor(User|int $user): ?ChatParticipant
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('participants')) {
            return $this->participants->first(fn (ChatParticipant $p) => $p->user_id === $userId && $p->left_at === null);
        }

        return $this->activeParticipants()->where('user_id', $userId)->first();
    }

    /** In a private chat: the other person. */
    public function otherParticipantFor(User|int $user): ?ChatParticipant
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->participants()->where('user_id', '!=', $userId)->first();
    }

    /** Conversations this user is currently a member of. */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->whereHas('participants', fn (Builder $p) => $p->where('user_id', $userId)->whereNull('left_at'));
    }
}
