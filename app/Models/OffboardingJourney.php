<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A departing volunteer's exit journey (DEC-09 increment 2). Only
 * App\Services\OffboardingJourneys writes it; nothing here is fillable.
 */
class OffboardingJourney extends Model
{
    public const TRIGGER_AUTO_RENEW_STOPPED = 'auto_renew_stopped';

    public const TRIGGER_TERMINATED = 'terminated';

    public const TRIGGER_CONTRACT_ENDED = 'contract_ended';

    protected $guarded = ['*'];

    protected $casts = [
        'started_at' => 'datetime',
        'exit_interview_held_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function exitInterviewBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exit_interview_by');
    }
}
