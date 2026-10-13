<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequestExternalConfirmation extends Model
{
    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequest::class, 'recruitment_request_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmer_user_id');
    }

    public function confirmerPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'confirmer_position_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'step_key' => 'integer',
            'decision' => 'integer',
            'method' => 'integer',

            'is_generated' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }
}
