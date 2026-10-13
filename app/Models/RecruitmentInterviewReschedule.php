<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change of an interview's slot or place: old and new values, who, when,
 * why, and whether the candidate had already been reminded of the old slot.
 * History is append-only — a row is never edited or removed.
 */
class RecruitmentInterviewReschedule extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class);
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reschedule history is append-only.'));
        static::deleting(fn () => throw new LogicException('Reschedule history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'from_scheduled_at' => 'datetime',
            'from_ends_at' => 'datetime',
            'to_scheduled_at' => 'datetime',
            'to_ends_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'location_changed' => 'boolean',
            'reminder_already_sent' => 'boolean',
        ];
    }
}
