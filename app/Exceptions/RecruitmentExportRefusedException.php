<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An export that was audited and refused. `status` is the HTTP answer the
 * controller gives; the message is safe to show the person.
 */
class RecruitmentExportRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
