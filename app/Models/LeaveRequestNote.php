<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR's note on a leave request (C3, DEC-05 revision): optional with "Seen by
 * HR", required with an objection. Visible to HR, the direct manager and the
 * requester; written only by LeaveRequestReview.
 */
class LeaveRequestNote extends Model
{
    public const KIND_NOTE = 'note';

    public const KIND_OBJECTION = 'objection';

    protected $fillable = ['leave_request_id', 'author_employee_id', 'author_role', 'kind', 'body'];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_employee_id');
    }
}
