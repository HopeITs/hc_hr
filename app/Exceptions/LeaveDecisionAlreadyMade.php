<?php

namespace App\Exceptions;

use App\Models\LeaveRequest;
use RuntimeException;

/**
 * A decision was submitted for a leave request that is no longer pending:
 * someone else decided it while the dialog was open, or this is a repeat of
 * the actor's own decision (a double click, a retry after a lost response).
 *
 * This is the `already_handled` outcome of the approval contract
 * (docs/ux/approval-source-contract.md §4.1, G-B-1). Nothing was written, and
 * it is not a refusal: it is only raised for someone who may still see the
 * request, so they can be told who decided it and when.
 */
class LeaveDecisionAlreadyMade extends RuntimeException
{
    public function __construct(public readonly LeaveRequest $leaveRequest)
    {
        parent::__construct("Leave request [{$leaveRequest->getKey()}] has already been decided.");
    }
}
