<?php

namespace App\Enums;

use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;
use BenSampo\Enum\Enum;

/**
 * @extends Enum<int>
 */
final class LeaveRequestStatus extends Enum implements HasStatusTone
{
    const Pending = 0;

    const Accepted = 1;

    const Rejected = 2;

    /** Accepted, then withdrawn before it started: kept as a record, its hours given back. */
    const Cancelled = 3;

    public static function getLocalizedName(int $value): array
    {
        return ['value' => __(self::labelKey($value))];
    }

    /** The instance for a stored value; an unknown value reads as pending, as getLocalizedName() always has. */
    public static function of(int|string|null $value): self
    {
        return self::coerce((int) $value) ?? self::fromValue(self::Pending);
    }

    public function tone(): StatusTone
    {
        return match ($this->value) {
            self::Accepted => StatusTone::Done,
            self::Rejected, self::Cancelled => StatusTone::Stop,
            default => StatusTone::Wait,
        };
    }

    public function icon(): string
    {
        return match ($this->value) {
            self::Accepted => 'check',
            self::Rejected => 'x',
            self::Cancelled => 'ban',
            default => 'clock',
        };
    }

    public function label(): string
    {
        return __(self::labelKey((int) $this->value));
    }

    private static function labelKey(int $value): string
    {
        return match ($value) {
            self::Accepted => 'leaves.status.accepted',
            self::Rejected => 'leaves.status.rejected',
            self::Cancelled => 'leaves.status.cancelled',
            default => 'leaves.status.pending',
        };
    }
}
