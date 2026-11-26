<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of the follow-up table: the subject, what is required, and who or which body it waits on. It lives on
 * after the minutes: open until marked done, or carried into a later meeting (the copy keeps carried_from_id).
 */
class BoardMeetingFollowUp extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const CARRIED = 'carried';

    protected $fillable = [
        'board_meeting_id', 'topic', 'required', 'party', 'employee_id', 'status', 'sort',
        'done_at', 'done_by', 'carried_from_id', 'notified_at',
    ];

    protected $casts = ['done_at' => 'datetime', 'notified_at' => 'datetime'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id');
    }

    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
