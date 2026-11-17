<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rejection email for one application: whether it went, and when.
 *
 * Deliberately carries no reason and no note — the mail is built from this
 * row and the application's public facts only.
 */
class RecruitmentRejectionNotice extends Model
{
    public const STATUS_QUEUED = 'queued';

    /** Claimed by a worker; the mail may or may not have gone out. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** The application was reopened before the mail went out. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'application_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicationDecision::class, 'decision_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** Queued, sending or sent: another request must not send a second one. */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_SENT], true);
    }

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
