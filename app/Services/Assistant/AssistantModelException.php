<?php

namespace App\Services\Assistant;

use Throwable;

/**
 * A chat model (any provider) could not answer. Extends GeminiException so
 * everything that already catches that one keeps catching every provider.
 */
class AssistantModelException extends GeminiException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?string $provider = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
