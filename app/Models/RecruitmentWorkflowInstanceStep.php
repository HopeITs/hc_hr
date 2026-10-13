<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentWorkflowInstanceStep extends Model
{
    protected $guarded = ['id'];

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowInstance::class, 'workflow_instance_id');
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowStep::class, 'workflow_step_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'assigned_scope_type' => 'integer',
            'decision' => 'integer',

            'assigned_scope_id' => 'integer',
            'acted_at' => 'datetime',
        ];
    }
}
