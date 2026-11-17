<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicRecruitmentLink extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'url_encrypted'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(HiringCampaign::class, 'campaign_id');
    }

    public function jobPostVersion(): BelongsTo
    {
        return $this->belongsTo(JobPostVersion::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationFormVersion::class, 'form_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class, 'public_link_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopePublicLinks($query, $user);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
