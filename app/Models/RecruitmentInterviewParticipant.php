<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member on an interview. Taking someone off sets removed_at rather
 * than deleting the row, so the record of who was on it survives — and so
 * does any evaluation they already wrote (Task 8).
 */
class RecruitmentInterviewParticipant extends Model
{
    public const ROLE_INTERVIEWER = 'interviewer';

    public const ROLE_OBSERVER = 'observer';

    public const NOTIFICATION_PENDING = 'pending';

    public const NOTIFICATION_SENT = 'sent';

    public const NOTIFICATION_FAILED = 'failed';

    /** The user's account or employee record is no longer active. */
    public const NOTIFICATION_SKIPPED = 'skipped';

    protected $guarded = ['id'];

    /** @return array<int, string> */
    public static function roles(): array
    {
        return [self::ROLE_INTERVIEWER, self::ROLE_OBSERVER];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
