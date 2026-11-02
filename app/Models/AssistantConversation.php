<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat with the AI assistant (App\Livewire\Assistant\Chat). The messages
 * are encrypted: an answer may quote the employee's own record or pay.
 */
class AssistantConversation extends Model
{
    /** Chats kept per user; the oldest beyond this are deleted. */
    public const KEEP = 50;

    protected $fillable = ['user_id', 'title', 'messages'];

    protected $casts = [
        'messages' => 'encrypted:array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
