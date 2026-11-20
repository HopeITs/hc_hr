<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's membership of a chat: their role, their administrator rights
 * in a group, how far they have read, and their own settings (mute, pin).
 */
class ChatParticipant extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    /** The administrator rights a group admin can hold, as their columns. */
    public const RIGHTS = [
        'can_change_info',
        'can_delete_messages',
        'can_remove_members',
        'can_add_members',
        'can_add_admins',
    ];

    protected $table = 'messenger_participants';

    protected $fillable = [
        'conversation_id', 'user_id', 'role',
        'can_change_info', 'can_delete_messages', 'can_remove_members', 'can_add_members', 'can_add_admins',
        'promoted_by', 'last_read_message_id', 'muted_until', 'pinned_at', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'can_change_info' => 'boolean',
        'can_delete_messages' => 'boolean',
        'can_remove_members' => 'boolean',
        'can_add_members' => 'boolean',
        'can_add_admins' => 'boolean',
        'muted_until' => 'datetime',
        'pinned_at' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Whether this member holds one administrator right. The owner holds them
     * all; a plain member holds none.
     */
    public function hasRight(string $right): bool
    {
        if (! in_array($right, self::RIGHTS, true)) {
            return false;
        }

        return match ($this->role) {
            self::ROLE_OWNER => true,
            self::ROLE_ADMIN => (bool) $this->{$right},
            default => false,
        };
    }

    /** @return array<string, bool> every right, as this member holds it */
    public function rights(): array
    {
        return collect(self::RIGHTS)->mapWithKeys(fn (string $r) => [$r => $this->hasRight($r)])->all();
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
