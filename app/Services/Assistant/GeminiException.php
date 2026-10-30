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

    /** Over quota or overloaded (503 "high demand"): worth one more try after a pause. */
    public function isTemporary(): bool
    {
        return in_array($this->getCode(), [429, 500, 503], true);
    }
}
