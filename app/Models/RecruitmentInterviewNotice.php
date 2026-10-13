<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email to the candidate about one interview: that it was scheduled,
 * moved or cancelled, or a reminder before it.
 *
 * Like RecruitmentRejectionNotice it carries no note, reason or evaluation —
 * the mail is built from the interview's public facts only. scheduled_for is
 * the slot the email describes; a worker that finds the interview has moved
 * or been cancelled since then cancels the email instead of sending it.
 */
class RecruitmentInterviewNotice extends Model
{
    public const KIND_SCHEDULED = 'scheduled';

    public const KIND_RESCHEDULED = 'rescheduled';

    public const KIND_CANCELLED = 'cancelled';

    public const KIND_REMINDER = 'reminder';

    public const STATUS_QUEUED = 'queued';

    /** Claimed by a worker; the mail may or may not have gone out. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Superseded before it went out: the interview moved or was cancelled. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'offset_minutes' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
