<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentApplicationParticipant extends Model
{
    public const ROLE_REVIEWER = 'reviewer';

    public const ROLE_VIEWER = 'viewer';

    protected $guarded = ['id'];

    /** @return array<int, string> */
    public static function roles(): array
    {
        return [self::ROLE_REVIEWER, self::ROLE_VIEWER];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class, 'applicant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('removed_at'));
    }

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }
}
