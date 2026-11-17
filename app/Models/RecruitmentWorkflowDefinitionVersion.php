<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentWorkflowDefinitionVersion extends Model
{
    protected $guarded = ['id'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowDefinition::class, 'definition_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowStep::class, 'definition_version_id')
            ->orderBy('step_order');
    }

    public function workflowInstances(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowInstance::class, 'definition_version_id');
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'rules_json' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
