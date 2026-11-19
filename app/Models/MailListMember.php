<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address on a mailing list. 'active' means they are already in the group
 * at the mail provider's control panel; 'pending' means they were added here
 * and still wait for the person who holds the control panel to add them there
 * and confirm it (mail-lists.confirm). 'leaving' is the other way round: the person left, and
 * the holder removes them from the group there and confirms it.
 */
class MailListMember extends Model
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    /** Left the organisation: still in the group at the control panel until the confirmer removes them. */
    public const LEAVING = 'leaving';

    protected $fillable = ['mail_list_id', 'email', 'status', 'added_by_user_id', 'confirmed_by_user_id', 'confirmed_at'];

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

    public function scopeLeaving(Builder $query): Builder
    {
        return $query->where('status', self::LEAVING);
    }

    public function isLeaving(): bool
    {
        return $this->status === self::LEAVING;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
