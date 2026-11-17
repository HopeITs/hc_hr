<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RecruitmentApplicationTag extends Pivot
{
    public $incrementing = true;

    protected $table = 'recruitment_application_tags';

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTag::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
