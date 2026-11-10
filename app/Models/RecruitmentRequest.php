<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecruitmentRequest extends Model
{
    protected $guarded = ['id'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function scopeCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'scope_city_id');
    }

    public function targetPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'target_position_id');
    }

    public function targetVacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'target_vacancy_id');
    }

    public function actualRequesterPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'actual_requester_position_id');
    }

    public function actualRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actual_requester_user_id');
    }

    public function directOpenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direct_opened_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecruitmentRequestVersion::class, 'request_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(RecruitmentRequestVersion::class, 'request_id')
            ->ofMany('version_no', 'max');
    }

    public function workflowInstances(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowInstance::class, 'request_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RecruitmentRequestComment::class, 'request_id');
    }

    public function externalConfirmations(): HasMany
    {
        return $this->hasMany(RecruitmentRequestExternalConfirmation::class, 'recruitment_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecruitmentRequestEvent::class, 'recruitment_request_id');
    }

    public function vacancy(): HasOne
    {
        return $this->hasOne(Vacancy::class, 'created_from_request_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeRequests($query, $user);
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'request_type' => 'integer',
            'mode' => 'integer',

            'draft_payload_json' => 'array',
            'direct_opened_at' => 'datetime',
            'submitted_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
