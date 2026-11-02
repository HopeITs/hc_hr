<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * A chat model could not answer: not configured, unreachable, over quota or
 * refused. The name predates the other providers; AssistantModelException
 * extends it, so catching this one catches them all.
 */
class GeminiException extends RuntimeException
{
    public function isRateLimited(): bool
    {
        return $this->getCode() === 429;
    }

    /** Over quota, overloaded (503 "high demand") or unreachable: worth another try. */
    public function isTemporary(): bool
    {
        return in_array($this->getCode(), [408, 429, 500, 502, 503, 504], true);
    }

    /**
     * Refused for this host or this key rather than for this question: an
     * unsupported location (Gemini from Syria), a bad or revoked key, a model
     * that does not exist. Asking again soon will not help.
     */
    public function isUnavailableHere(): bool
    {
        $code = $this->getCode();

        return in_array($code, [401, 403, 404], true)
            || ($code === 400 && preg_match('/location|region|country|not supported|unsupported/i', $this->getMessage()) === 1);
    }
}
