<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The evaluation as it stood after one change, with who made it and why.
 * Append-only: a revision is never edited or removed, so no edit — by the
 * reviewer or by HR — can erase what was written before.
 */
class RecruitmentInterviewEvaluationRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['pros', 'cons', 'notes', 'recommendation', 'reason'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterviewEvaluation::class, 'evaluation_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evaluation history is append-only.'));
        static::deleting(fn () => throw new LogicException('Evaluation history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
