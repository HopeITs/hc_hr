<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A news item's copy on an external platform (e.g. LinkedIn): status,
 * the platform's id and URL, when it went out, the last error and the
 * attempts. Written only by App\Services\News\Publishing\ExternalPublishing;
 * no platform is integrated yet, so no row is created today.
 */
class NewsExternalPublication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'news_id', 'platform', 'status', 'external_id', 'external_url',
        'published_at', 'last_error', 'attempts', 'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
