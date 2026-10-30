<?php

namespace App\Enums;

use App\Enums\ContractType as ContractTypeEnum;
use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;
use Illuminate\Database\Eloquent\Model;

/**
 * A contract's state as the lists show it. Derived from the type, the active
 * flag and the dates, in the order ContractsTable has always used.
 */
enum ContractStatus: string implements HasStatusTone
{
    case Draft = 'draft';
    case Terminated = 'terminated';
    case Active = 'active';
    case Expired = 'expired';
    case Inactive = 'inactive';

    public static function for(Model $contract): self
    {
        return match (true) {
            (int) $contract->type === ContractTypeEnum::Draft => self::Draft,
            $contract->isTerminated() => self::Terminated,
            (bool) $contract->is_active => self::Active,
            $contract->end_date !== null && $contract->end_date->isPast() => self::Expired,
            default => self::Inactive,
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Attn,
            self::Terminated => StatusTone::Stop,
            self::Active => StatusTone::Done,
            self::Expired => StatusTone::Stop,
            self::Inactive => StatusTone::Wait,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'pen',
            self::Terminated => 'x',
            self::Active => 'check',
            self::Expired => 'calendar-x',
            self::Inactive => 'pause',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => ContractTypeEnum::getLocalizedName(ContractTypeEnum::Draft)['value'],
            self::Terminated => __('contracts.status-terminated'),
            self::Active => __('general.active'),
            self::Expired => __('contracts.status-expired'),
            self::Inactive => __('general.inactive'),
        };
    }
}
