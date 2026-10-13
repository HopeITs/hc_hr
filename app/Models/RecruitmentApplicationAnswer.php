<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentApplicationAnswer extends Model
{
    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationFormVersion::class, 'form_version_id');
    }

    protected function casts(): array
    {
        return ['answer_json' => 'array'];
    }
}
