<?php

namespace App\Models;

use App\Casts\EncryptedSalary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One version of a job offer: the salary, start date, probation and contract
 * terms as they were offered (Phase 2 Task 9).
 *
 * A draft version can be edited. Once sent it is a snapshot: only its status
 * may change afterwards (sent -> superseded / accepted / rejected / expired /
 * cancelled), so what the candidate was offered can never be rewritten. A
 * change to a sent offer is always a new version (a revision).
 *
 * salary_amount is encrypted at rest under the salary key, like
 * contracts.salary, and hidden from serialization together with the terms.
 */
class RecruitmentJobOfferVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    /** A newer version was sent in its place. */
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** The offer's terms: everything but its lifecycle fields. */
    public const TERMS = [
        'contract_type_id', 'salary_amount', 'currency', 'salary_per', 'start_date', 'end_date',
        'weekly_hours', 'probation_days', 'terms_json',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['salary_amount', 'terms_json', 'revision_reason'];

    public function jobOffer(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJobOffer::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function hiringConversions(): HasMany
    {
        return $this->hasMany(RecruitmentHiringConversion::class, 'job_offer_version_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    protected static function booted(): void
    {
        // The snapshot rule: once a version left draft, its terms are frozen.
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') !== self::STATUS_DRAFT
                && array_diff(array_keys($version->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('A sent offer version is a snapshot; revise the offer instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Offer versions are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'salary_amount' => EncryptedSalary::class,
            'currency' => 'integer',
            'salary_per' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'weekly_hours' => 'integer',
            'probation_days' => 'integer',
            'terms_json' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
