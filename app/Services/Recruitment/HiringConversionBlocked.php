<?php

namespace App\Services\Recruitment;

use Illuminate\Validation\ValidationException;

/**
 * Phase 2 Task 10: the hire cannot go ahead in the state things are in now —
 * the vacancy was closed, the position is full or inactive, the offer's
 * terms cannot be a contract. Unlike a mistake in the form, this is recorded
 * on the conversion as a failed attempt, with $reason as its code, so HR can
 * see why it is stuck and retry once the cause is fixed.
 */
final class HiringConversionBlocked extends ValidationException
{
    public string $reason = '';

    public static function because(string $reason): self
    {
        $exception = self::withMessages([
            'conversion' => __('recruitment-applicant.conversions.errors.'.$reason),
        ]);
        $exception->reason = $reason;

        return $exception;
    }
}
