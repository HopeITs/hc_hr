<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPostVersion extends Model
{
    protected $guarded = ['id'];

    public function jobPost(): BelongsTo
    {
        return $this->belongsTo(JobPost::class);
    }

    public function publicLinks(): HasMany
    {
        return $this->hasMany(PublicRecruitmentLink::class, 'job_post_version_id');
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'public_payload_json' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
