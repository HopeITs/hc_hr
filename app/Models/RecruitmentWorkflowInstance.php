<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentWorkflowInstance extends Model
{
    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequest::class, 'request_id');
    }

    public function requestVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequestVersion::class, 'request_version_id');
    }

    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowDefinitionVersion::class, 'definition_version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowInstanceStep::class, 'workflow_instance_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecruitmentRequestEvent::class, 'workflow_instance_id');
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',

            'current_step_order' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
