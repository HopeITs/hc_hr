<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Turning one accepted job offer into an employee and a contract.
 *
 * Phase 2 Task 9 writes the row, pending, the moment the candidate accepts
 * (one per offer: unique job_offer_id). Task 10's RecruitmentHiringConversionService
 * is the only writer after that: it records each failed attempt and, once,
 * the employee and contract it created or linked.
 *
 * A converted row is final — the hire it records happened — so it is never
 * rewritten, and no row is ever deleted.
 */
class RecruitmentHiringConversion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONVERTED = 'converted';

    protected $guarded = ['id'];

    public function jobOffer(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJobOffer::class);
    }

    public function jobOfferVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJobOfferVersion::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by_user_id');
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function delete()
            {
                throw new LogicException('Hiring conversions are never deleted.');
            }

            public function forceDelete()
            {
                $this->delete();
            }

            public function truncate(): void
            {
                $this->delete();
            }
        };
    }

    protected static function booted(): void
    {
        static::updating(function (self $conversion): void {
            if ($conversion->getOriginal('status') === self::STATUS_CONVERTED) {
                throw new LogicException('A converted hiring conversion is final and cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Hiring conversions are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'employee_payload_json' => 'array',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }
}
