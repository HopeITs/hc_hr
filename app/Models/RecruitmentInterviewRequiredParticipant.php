<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentInterviewRequiredParticipant extends Model
{
    public const SEAT_HR = 'hr';

    public const SEAT_MANAGER = 'manager';

    protected $guarded = ['id'];

    /** @return array<int, string> */
    public static function seats(): array
    {
        return [self::SEAT_HR, self::SEAT_MANAGER];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_user_id');
    }
}
