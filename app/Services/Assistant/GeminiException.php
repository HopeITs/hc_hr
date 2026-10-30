<?php

namespace App\Services\Assistant;

use RuntimeException;

/** Gemini could not answer: not configured, unreachable, over quota or refused. */
class GeminiException extends RuntimeException
{
    public function isRateLimited(): bool
    {
        return $this->getCode() === 429;
    }
}
