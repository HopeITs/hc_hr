<?php

namespace App\Exceptions;

use RuntimeException;

class UnsafeRecruitmentFileException extends RuntimeException
{
    /**
     * `reason` is the key under validation.recruitment_file.*, kept apart from
     * the translated message so monitoring can tell a mistake (too_large) from
     * an attack (content_mismatch, executable).
     */
    public function __construct(string $message, public readonly string $reason = 'unknown')
    {
        parent::__construct($message);
    }

    public static function make(string $reason): self
    {
        return new self(trans("validation.recruitment_file.{$reason}"), $reason);
    }
}
