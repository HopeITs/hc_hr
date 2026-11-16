<?php

namespace App\Services\Attendance;

use RuntimeException;

/** A step of the month's workflow that is not allowed now; the message is a translation key. */
class WorkflowRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('attendance.workflow.refused.'.$reason);
    }
}
