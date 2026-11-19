<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message. The text is encrypted at rest; "body" reads back decrypted.
 */
class ChatMessage extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_FILE = 'file';

    public const TYPE_SERVICE = 'service';

    // Service message kinds (meta.event).
    public const EVENT_GROUP_CREATED = 'group_created';

    public const EVENT_MEMBERS_ADDED = 'members_added';

    public const EVENT_MEMBER_REMOVED = 'member_removed';

    public const EVENT_MEMBER_LEFT = 'member_left';

    public const EVENT_TITLE_CHANGED = 'title_changed';

    public const EVENT_OWNERSHIP_PASSED = 'ownership_passed';

    protected $table = 'messenger_messages';

    protected $fillable = [
        'conversation_id', 'sender_id', 'type', 'body', 'meta', 'reply_to_id',
        'forwarded_from_user_id', 'forwarded_from_message_id', 'forward_hidden', 'edited_at',
    ];

    protected $casts = [
        'body' => 'encrypted',
        'meta' => 'array',
        'forward_hidden' => 'boolean',
        'edited_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** The original author shown as "Forwarded from", unless hidden. */
    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_from_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function isForwarded(): bool
    {
        return $this->forwarded_from_user_id !== null;
    }

    /** Messages this user has not deleted for themself. */
    public function scopeVisibleTo(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->whereNotExists(function ($hidden) use ($userId) {
            $hidden->selectRaw('1')
                ->from('messenger_message_hidden')
                ->whereColumn('messenger_message_hidden.message_id', 'messenger_messages.id')
                ->where('messenger_message_hidden.user_id', $userId);
        });
    }
}
