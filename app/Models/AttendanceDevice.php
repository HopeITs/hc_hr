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
        'agent_key_hash', 'agent_key_hint', 'agent_key_created_at', 'agent_last_sync_at',
    ];

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

    /**
     * A new sync key for this device. Returns the key itself, which is shown to HR once and never kept: only
     * its hash is stored, so a copy of the database does not hand out working keys.
     */
    public function issueAgentKey(): string
    {
        $key = 'zk_'.bin2hex(random_bytes(20));

        $this->forceFill([
            'agent_key_hash' => hash('sha256', $key),
            'agent_key_hint' => substr($key, -4),
            'agent_key_created_at' => now(),
        ])->save();

        return $key;
    }

    public function revokeAgentKey(): void
    {
        $this->forceFill(['agent_key_hash' => null, 'agent_key_hint' => null, 'agent_key_created_at' => null])->save();
    }

    public function hasAgentKey(): bool
    {
        return $this->agent_key_hash !== null;
    }

    /** The device a presented sync key belongs to, or null. */
    public static function forAgentKey(?string $key): ?self
    {
        if (! is_string($key) || ! str_starts_with($key, 'zk_') || strlen($key) > 80) {
            return null;
        }

        return static::query()->where('agent_key_hash', hash('sha256', $key))->first();
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

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime', 'last_punch_at' => 'datetime',
            'agent_key_created_at' => 'datetime', 'agent_last_sync_at' => 'datetime',
        ];
    }
}
