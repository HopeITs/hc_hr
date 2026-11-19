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
        'manager_orientation_scheduled_for' => 'date',
        'manager_orientation_completed_at' => 'datetime',
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

    /** The IT person HR picked to hand the electronics over (only needed when the city has several). */
    public function electronicsDeliverer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'electronics_deliverer_id');
    }

    public function managerOrientationScheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_orientation_scheduled_by');
    }

    public function managerOrientationCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_orientation_completed_by');
    }

    /**
     * The date a short-deadline step (account, electronics, card) is due:
     * $days after the contract's start. Computed, never stored.
     */
    public function dueAfterStart(int $days): ?\Carbon\CarbonInterface
    {
        $start = ($this->contract?->start_date ?? $this->started_at);

        return $start?->copy()->startOfDay()->addDays($days);
    }

    public function managerOrientationIsLate(): bool
    {
        return $this->manager_orientation_completed_at === null
            && $this->orientation_due_on !== null
            && $this->orientation_due_on->lt(now(config('app.timezone'))->startOfDay());
    }

    public function orientationIsLate(): bool
    {
        return $this->orientation_completed_at === null
            && $this->orientation_due_on !== null
            && $this->orientation_due_on->lt(now(config('app.timezone'))->startOfDay());
    }
}
