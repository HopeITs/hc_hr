<?php

namespace App\Enums;

use App\Models\Employee;
use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;

/**
 * An employee's state as the lists show it. Derived, not stored: a draft
 * record, an active one, one waiting for its first contract, or inactive.
 */
enum EmployeeStatus: string implements HasStatusTone
{
    case Incomplete = 'incomplete';
    case Active = 'active';
    case AwaitingContract = 'awaiting-contract';
    case Inactive = 'inactive';

    public static function for(Employee $employee): self
    {
        return match (true) {
            $employee->isDraft() => self::Incomplete,
            (bool) $employee->is_active => self::Active,
            $employee->isAwaitingContract() => self::AwaitingContract,
            default => self::Inactive,
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Incomplete => StatusTone::Attn,
            self::Active => StatusTone::Done,
            self::AwaitingContract => StatusTone::Wait,
            self::Inactive => StatusTone::Stop,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Incomplete => 'pen',
            self::Active => 'check',
            self::AwaitingContract => 'clock',
            self::Inactive => 'pause',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => __('employees.onboarding.status-incomplete'),
            self::Active => __('general.active'),
            self::AwaitingContract => __('employees.onboarding.status-awaiting'),
            self::Inactive => __('general.inactive'),
        };
    }
}
