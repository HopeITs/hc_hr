<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The one row of matching-rule overrides; see RecruitmentIndicatorRules. */
class RecruitmentIndicatorSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['overrides_json' => 'array'];
    }
}
