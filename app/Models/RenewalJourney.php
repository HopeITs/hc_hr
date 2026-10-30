<?php

namespace App\Models;

use App\Services\RenewalJourneys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The renewal journey of an expiring contract (DEC-09 increment 2,
 * docs/ux/journeys.md §3.2). Only App\Services\RenewalJourneys writes it;
 * nothing here is fillable.
 */
class RenewalJourney extends Model
{
    public const DECISION_RENEW = 'renew';

    public const DECISION_STOP = 'stop';

    public const DECISIONS = [self::DECISION_RENEW, self::DECISION_STOP];

    protected $guarded = ['*'];

    protected $casts = [
        'started_at' => 'datetime',
        'decided_at' => 'datetime',
        'decision_switched_auto_renew' => 'boolean',
        'completed_at' => 'datetime',
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

    /**
     * Late: no decision yet, and the contract ends within
     * RenewalJourneys::LATE_DAYS days (or already ended).
     */
    public function decisionIsLate(): bool
    {
        $end = $this->contract?->end_date;

        return $this->decision === null
            && $end !== null
            && now(config('app.timezone'))->startOfDay()->gte($end->copy()->subDays(RenewalJourneys::LATE_DAYS));
    }
}
