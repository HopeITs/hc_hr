<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A time-clock device that pushes its punches to the system by itself. It is known by its
 * serial number and belongs to one center; HR registers it once (or accepts it from the list of
 * devices that called). `last_seen_at` moves every time it talks, so a device that has gone
 * quiet shows up before its center's timesheets do.
 */
class AttendanceDevice extends Model
{
    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    /** Minutes of silence after which a device counts as late (it calls about every 30 seconds). */
    public const LATE_AFTER_MINUTES = 15;

    /** Days of silence after which its center needs a visit. */
    public const SILENT_AFTER_DAYS = 2;

    protected $fillable = [
        'serial', 'name', 'center_id', 'status', 'last_seen_at', 'last_ip', 'last_punch_at',
        'attlog_stamp', 'push_version', 'created_by',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_punch_at' => 'datetime'];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    /** online (heard from lately), late, silent (days without a word) or never (registered, never called). */
    public function health(): string
    {
        if ($this->last_seen_at === null) {
            return 'never';
        }

        if ($this->last_seen_at->gte(now()->subMinutes(self::LATE_AFTER_MINUTES))) {
            return 'online';
        }

        return $this->last_seen_at->gte(now()->subDays(self::SILENT_AFTER_DAYS)) ? 'late' : 'silent';
    }
}
