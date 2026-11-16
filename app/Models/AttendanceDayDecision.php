<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What HR decided for a working day with no record (no punch, no leave, no holiday) or a single
 * punch: the person worked, or was absent without leave. Leave itself is recorded in the leave
 * module, so balances stay right.
 */
class AttendanceDayDecision extends Model
{
    public const WORKED = 'worked';

    public const ABSENT = 'absent';

    protected $fillable = ['employee_id', 'work_date', 'kind', 'note', 'decided_by'];

    protected $casts = [
        'work_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
