<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The definition behind one criteria version, kept so old snapshots stay explainable. */
class RecruitmentIndicatorCriteriaVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition_json' => 'array'];
    }
}
