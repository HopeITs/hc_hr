<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentApplicationDecision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
