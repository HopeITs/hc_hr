<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The review of a trial contract in its last third (meeting 19/11, track 4).
 * Only App\Services\ProbationReviews writes it; nothing here is fillable.
 *
 * The decision is a request to HR, not a change to the contract.
 */
class ProbationReview extends Model
{
    public const EXTEND = 'extend';

    public const CONFIRM = 'confirm';

    public const CANCEL = 'cancel';

    public const DECISIONS = [self::EXTEND, self::CONFIRM, self::CANCEL];

    protected $guarded = ['*'];

    protected $casts = [
        'started_at' => 'datetime',
        'decided_at' => 'datetime',
        'hr_seen_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isDecided(): bool
    {
        return $this->decision !== null;
    }
}
