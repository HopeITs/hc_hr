<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser or installed app that accepted Web Push for a user. The
 * endpoint and keys are the device's secret: they are hidden from
 * serialisation and only WebPushSender reads them. The endpoint is unique
 * (by hash, since it is too long to index), so the same browser subscribing
 * again updates its row instead of adding a second one.
 */
class PushSubscription extends Model
{
    public const TYPES = ['desktop', 'mobile', 'tablet'];

    protected $fillable = [
        'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'expires_at',
        'device_id', 'device_name', 'device_type', 'browser', 'platform', 'standalone',
        'enabled', 'urgent_only', 'last_seen_at',
    ];

    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'standalone' => 'boolean',
            'enabled' => 'boolean',
            'urgent_only' => 'boolean',
            'failure_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** Desktop push for computers, mobile push for phones and tablets. */
    public function channel(): NotificationChannel
    {
        return $this->device_type === 'desktop' ? NotificationChannel::Desktop : NotificationChannel::Mobile;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
