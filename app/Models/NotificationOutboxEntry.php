<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push or email still to send for one notification, and the earliest time
 * it may go (quiet hours, working days and digests move it later).
 * channel is 'push' or 'email'; payload is {title, body, url} with title and
 * body as {en, ar} arrays (or plain strings), as the notification stored them.
 */
class NotificationOutboxEntry extends Model
{
    public const PUSH = 'push';

    public const EMAIL = 'email';

    protected $table = 'notification_outbox';

    protected $fillable = [
        'user_id', 'notification_id', 'channel', 'category', 'urgent', 'payload', 'deliver_after',
    ];

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'payload' => 'array',
            'deliver_after' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoryEnum(): NotificationCategory
    {
        return NotificationCategory::tryFrom($this->category) ?? NotificationCategory::System;
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->whereNull('failed_at')->where('deliver_after', '<=', now());
    }
}
