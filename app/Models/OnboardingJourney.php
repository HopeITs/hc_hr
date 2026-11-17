<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A new volunteer's onboarding journey (D4-I, DEC-09). Only
 * App\Services\OnboardingJourneys writes it; nothing here is fillable.
 */
class OnboardingJourney extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'started_at' => 'datetime',
        'orientation_due_on' => 'date',
        'orientation_scheduled_for' => 'date',
        'orientation_completed_at' => 'datetime',
        'card_handed_over_at' => 'datetime',
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

    public function orientationScheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'orientation_scheduled_by');
    }

    public function orientationCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'orientation_completed_by');
    }

    public function cardHandedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'card_handed_over_by');
    }

    public function orientationIsLate(): bool
    {
        return $this->orientation_completed_at === null
            && $this->orientation_due_on !== null
            && $this->orientation_due_on->lt(now(config('app.timezone'))->startOfDay());
    }
}
