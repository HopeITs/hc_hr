<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address on a mailing list. 'active' means they are already in the group
 * at the mail provider's control panel; 'pending' means they were added here
 * and still wait for the person who holds the control panel to add them there
 * and confirm it (mail-lists.confirm).
 */
class MailListMember extends Model
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    protected $fillable = ['mail_list_id', 'email', 'status', 'added_by_user_id', 'confirmed_by_user_id', 'confirmed_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function mailList(): BelongsTo
    {
        return $this->belongsTo(MailList::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
