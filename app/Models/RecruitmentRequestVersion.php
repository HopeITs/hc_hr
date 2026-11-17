<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecruitmentRequestVersion extends Model
{
    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequest::class, 'request_id');
    }

    public function positionDetail(): HasOne
    {
        return $this->hasOne(RecruitmentRequestPositionDetail::class, 'request_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RecruitmentRequestComment::class, 'request_version_id');
    }

    public function workflowInstance(): HasOne
    {
        return $this->hasOne(RecruitmentWorkflowInstance::class, 'request_version_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecruitmentRequestEvent::class, 'request_version_id');
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'snapshot_json' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
