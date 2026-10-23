<?php

namespace App\Services;

use RuntimeException;

/**
 * A card change DigitalIdManager refused. The message is a translation key in
 * resources/lang/{en,ar}/digital_id.php, so callers can show it as is.
 */
class DigitalIdException extends RuntimeException
{
    /** The index changed after the screen was loaded (another tab, a double click). */
    public static function staleIndex(): self
    {
        return new self('digital_id.errors.stale_index');
    }

    public static function inactiveEmployee(): self
    {
        return new self('digital_id.errors.inactive_employee');
    }

    public static function noCardLink(): self
    {
        return new self('digital_id.errors.no_card_link');
    }

    public static function limitReached(): self
    {
        return new self('digital_id.errors.limit_reached');
    }
}
