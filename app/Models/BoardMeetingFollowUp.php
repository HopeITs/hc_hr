<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A row of the follow-up table: the subject, what is required, and who or which body it waits on. */
class BoardMeetingFollowUp extends Model
{
    protected $fillable = ['board_meeting_id', 'topic', 'required', 'party', 'employee_id', 'status', 'sort'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
