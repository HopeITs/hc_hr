<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One free tag of a news item ("Aleppo", "Study Zone", "Training"): what the
 * item is about. Tags never decide who sees an item (that is the audience).
 * Written only through News::syncTags(), which normalizes them.
 */
class NewsTag extends Model
{
    public $timestamps = false;

    protected $fillable = ['news_id', 'tag'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
