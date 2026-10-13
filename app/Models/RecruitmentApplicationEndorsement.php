<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentApplicationEndorsement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
