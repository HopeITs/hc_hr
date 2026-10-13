<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationFormVersion extends Model
{
    protected $guarded = ['id'];

    public function applicationForm(): BelongsTo
    {
        return $this->belongsTo(ApplicationForm::class);
    }

    public function publicLinks(): HasMany
    {
        return $this->hasMany(PublicRecruitmentLink::class, 'form_version_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class, 'form_version_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationAnswer::class, 'form_version_id');
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'fields_json' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
