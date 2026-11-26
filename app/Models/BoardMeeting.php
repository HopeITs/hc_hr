<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One meeting of the board. Its minutes (sections and follow-ups) are written by the secretary and
 * become readable by the members once published; until then they are a draft only the writers see.
 */
class BoardMeeting extends Model
{
    public const SCHEDULED = 'scheduled';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'number', 'starts_at', 'ends_at', 'location', 'room', 'purpose', 'led_by', 'status',
        'cancel_reason', 'reminded_at', 'published_at', 'prepared_by', 'created_by',
        'reopened_at', 'reopened_by', 'reopen_reason',
    ];

    protected $casts = [
        'number' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminded_at' => 'datetime',
        'published_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function attendees(): HasMany
    {
        return $this->hasMany(BoardMeetingAttendee::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BoardMeetingSection::class)->orderBy('sort')->orderBy('id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(BoardMeetingFollowUp::class)->orderBy('sort')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Its time has come: the date can no longer move, and attendance can be taken. */
    public function hasStarted(): bool
    {
        return $this->starts_at->lessThanOrEqualTo(now());
    }

    /** Still ahead of us and still on. */
    public function isUpcoming(): bool
    {
        return ! $this->isCancelled() && $this->starts_at->isFuture();
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('starts_at', '>=', now());
    }

    public static function nextNumber(): int
    {
        return ((int) static::query()->max('number')) + 1;
    }
}
