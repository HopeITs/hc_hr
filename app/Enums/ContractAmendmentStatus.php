<?php

namespace App\Enums;

use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;
use BenSampo\Enum\Enum;

/**
 * Draft (printed for signing) → Approved (signed; waits for its effective
 * date) → Applied (its terms are now the contract's).
 *
 * @extends Enum<int>
 */
final class ContractAmendmentStatus extends Enum implements HasStatusTone
{
    const Draft = 0;

    const Approved = 1;

    const Applied = 2;

    /** The instance for a stored value; an unknown value reads as a draft. */
    public static function of(int|string|null $value): self
    {
        return self::coerce((int) $value) ?? self::fromValue(self::Draft);
    }

    public function tone(): StatusTone
    {
        return match ($this->value) {
            // The one the contract runs on now; a superseded one is shown as done.
            self::Applied => StatusTone::Work,
            self::Approved => StatusTone::Wait,
            default => StatusTone::Attn,
        };
    }

    public function icon(): string
    {
        return match ($this->value) {
            self::Applied => 'check',
            self::Approved => 'clock',
            default => 'pen',
        };
    }

    public function label(): string
    {
        return __(match ((int) $this->value) {
            self::Applied => 'contracts.amendment.status.applied',
            self::Approved => 'contracts.amendment.status.approved',
            default => 'contracts.amendment.status.draft',
        });
    }
}
