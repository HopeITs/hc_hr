<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecruitmentCampaignInvite extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_USED = 'used';

    public const STATUS_CANCELLED = 'cancelled';

    /** Why the last email did not arrive; the screens translate these. */
    public const FAILURE_MAILBOX_MISSING = 'mailbox_missing';

    public const FAILURE_REJECTED = 'rejected';

    public const FAILURE_NOT_SENT = 'not_sent';

    protected $guarded = ['id'];

    /**
     * Did the last attempt to email this invitation fail?
     *
     * Beside the status rather than instead of it: the invitation is still
     * valid, and an invitation that has been used or cancelled has nothing
     * left to deliver.
     */
    public function hasDeliveryFailed(): bool
    {
        return $this->failure_reason !== null
            && ! in_array($this->status, [self::STATUS_USED, self::STATUS_CANCELLED], true);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(HiringCampaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(RecruitmentApplication::class, 'campaign_invite_id');
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
