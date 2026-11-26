<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's place at one meeting: expected, present, online or absent (and the request that made them absent). */
class BoardMeetingAttendee extends Model
{
    public const EXPECTED = 'expected';

    public const PRESENT = 'present';

    public const ONLINE = 'online';

    public const ABSENT = 'absent';

    public const STATUSES = [self::EXPECTED, self::PRESENT, self::ONLINE, self::ABSENT];

    public const REQUEST_PENDING = 'pending';

    public const REQUEST_APPROVED = 'approved';

    public const REQUEST_REJECTED = 'rejected';

    protected $fillable = ['board_meeting_id', 'employee_id', 'status', 'absence_reason', 'absence_request', 'absence_decided_at'];

    protected $casts = ['absence_decided_at' => 'datetime'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
