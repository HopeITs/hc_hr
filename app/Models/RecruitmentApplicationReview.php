<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentApplicationReview extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['pros', 'cons', 'notes', 'recommendation'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'application_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
