<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An HR opinion attached to an evaluation without changing the reviewer's work. */
class RecruitmentInterviewEvaluationNote extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['body'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterviewEvaluation::class, 'evaluation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evaluation notes are append-only.'));
        static::deleting(fn () => throw new LogicException('Evaluation notes are append-only.'));
    }
}
