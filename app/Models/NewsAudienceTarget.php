<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One city/center/program/department/employee id in a news item's audience (kind = news.audience_type). */
class NewsAudienceTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['news_id', 'target_id'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
