<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Why a public application was refused. The reason is a key under
 * `careers.errors.*` and is shown to the applicant as it is; `errors`
 * carries the per-field messages when the answers themselves failed.
 */
class PublicRecruitmentSubmissionException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(
        public readonly string $reason = 'generic',
        public readonly array $errors = [],
    ) {
        parent::__construct($reason);
    }

    /** @param array<string, array<int, string>> $errors */
    public static function because(string $reason, array $errors = []): self
    {
        return new self($reason, $errors);
    }
}
