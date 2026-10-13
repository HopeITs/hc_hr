<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequest::class, 'recruitment_request_id');
    }

    public function requestVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequestVersion::class, 'request_version_id');
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowInstance::class, 'workflow_instance_id');
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflowStep::class, 'workflow_step_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeRequestEvents($query, $user);
    }

    protected function casts(): array
    {
        return [
            'event_type' => 'integer',
            'from_status' => 'integer',
            'to_status' => 'integer',
            'decision' => 'integer',
            'scope_type' => 'integer',

            'scope_id' => 'integer',
            'meta' => 'array',
        ];
    }
}
