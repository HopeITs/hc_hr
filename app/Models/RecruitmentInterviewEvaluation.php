<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One reviewer's evaluation of one interview (Phase 2 Task 8): pros, cons,
 * notes and their own recommendation. One row per reviewer per interview
 * (unique key); each reviewer's opinion is their own.
 *
 * Written only by RecruitmentInterviewEvaluationService, which bumps
 * `version` and appends a revision on every change — so the history is never
 * lost, and a save made against an older version is refused. HR opinions are
 * stored separately and never alter this participant-authored record.
 *
 * Internal: nothing here is ever shown to the candidate, and pros, cons,
 * notes and recommendation are hidden from serialization so they cannot ride
 * along in a Livewire payload by accident.
 */
class RecruitmentInterviewEvaluation extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_CORRECTED = 'corrected';

    /** Legacy history action retained for existing records. */
    public const ACTION_WITHDRAWN = 'withdrawn';

    protected $guarded = ['id'];

    protected $hidden = ['pros', 'cons', 'notes', 'recommendation', 'withdrawal_reason'];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RecruitmentInterviewEvaluationRevision::class, 'evaluation_id');
    }

    public function opinionNotes(): HasMany
    {
        return $this->hasMany(RecruitmentInterviewEvaluationNote::class, 'evaluation_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('withdrawn_at'));
    }

    /** A query-level delete skips model events, so the builder refuses it too. */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function delete()
            {
                throw new LogicException('Evaluations are never deleted.');
            }

            public function forceDelete()
            {
                $this->delete();
            }

            public function truncate(): void
            {
                $this->delete();
            }
        };
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Evaluations are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
