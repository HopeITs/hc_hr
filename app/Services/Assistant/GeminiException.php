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

    /** Over quota, overloaded (503 "high demand") or unreachable: worth another try. */
    public function isTemporary(): bool
    {
        return in_array($this->getCode(), [429, 500, 503, 504], true);
    }
}
