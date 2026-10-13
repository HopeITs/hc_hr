<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentWorkflowStep extends Model
{
    protected $guarded = ['id'];

    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowDefinitionVersion::class, 'definition_version_id');
    }

    public function instanceSteps(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowInstanceStep::class, 'workflow_step_id');
    }

    public function requestEvents(): HasMany
    {
        return $this->hasMany(RecruitmentRequestEvent::class, 'workflow_step_id');
    }

    protected function casts(): array
    {
        return [
            'step_key' => 'integer',
            'scope_rule' => 'integer',

            'step_order' => 'integer',
            'required_condition_json' => 'array',
        ];
    }
}
