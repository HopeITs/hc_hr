<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indicators as they were computed at one moment, under one criteria version.
 *
 * A figure without its criteria version is meaningless once the criteria
 * change, so the two are only ever written together.
 */
class RecruitmentApplicationIndicatorSnapshot extends Model
{
    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(RecruitmentIndicatorCriteriaVersion::class, 'criteria_version', 'version');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'met_count' => 'integer',
            'assessed_count' => 'integer',
            'indicators_json' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
