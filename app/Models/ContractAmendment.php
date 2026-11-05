<?php

namespace App\Models;

use App\Casts\EncryptedSalary;
use App\Enums\ContractAmendmentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A dated change to the hours, schedule or pay of an active contract (ملحق عقد).
 *
 * The contract row always holds the current terms; this row keeps what they
 * were and what they became, so a person's year can be read back ("part time
 * until 5/11, full time since"). Pay figures are kept out of the JSON
 * snapshots and live in salary_before/after and the pay-tier rows, encrypted
 * like the contract's own.
 */
class ContractAmendment extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    /**
     * The terms an amendment may change, besides the pay figures themselves.
     * Position, contract category and dates are not among them: a new position
     * is a new contract (capacity), a longer term is a renewal.
     */
    const AMENDABLE_FIELDS = [
        'type',
        'weekly_hours',
        'schedule_mode',
        'work_hours_from',
        'work_hours_to',
        'work_hours_2_from',
        'work_hours_2_to',
        'work_days_en',
        'work_days_ar',
        'work_day_from',
        'work_day_to',
        'show_days_per_week',
        'show_hours_per_week',
        'show_hours_per_day',
        'show_day_range',
        'flex_days_per_week',
        'flex_hours_per_week',
        'flex_hours_per_month',
        'flex_varies_by_requirements',
        'flex_coordinated_with_manager',
        'flex_custom_en',
        'flex_custom_ar',
        'pay_mode',
        'session_duration',
        'currency',
        'salary_per',
    ];

    /** The changes that put pay on the printed sheet. */
    const PAY_FIELDS = ['salary', 'pay_tiers', 'currency', 'salary_per', 'pay_mode', 'session_duration'];

    protected $fillable = [
        'contract_id',
        'number',
        'status',
        'effective_from',
        'reason',
        'terms_before',
        'terms_after',
        'salary_before',
        'salary_after',
        'created_by',
        'approved_by',
        'approved_at',
        'applied_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'status' => 'integer',
        'effective_from' => 'date',
        'terms_before' => 'array',
        'terms_after' => 'array',
        'salary_before' => EncryptedSalary::class,
        'salary_after' => EncryptedSalary::class,
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payTiers()
    {
        return $this->hasMany(ContractAmendmentPayTier::class)->orderBy('sort_order');
    }

    public function payTiersBefore()
    {
        return $this->payTiers()->where('side', ContractAmendmentPayTier::SIDE_BEFORE);
    }

    public function payTiersAfter()
    {
        return $this->payTiers()->where('side', ContractAmendmentPayTier::SIDE_AFTER);
    }

    public function statusEnum(): ContractAmendmentStatus
    {
        return ContractAmendmentStatus::of($this->status);
    }

    public function isDraft(): bool
    {
        return $this->status === ContractAmendmentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === ContractAmendmentStatus::Approved;
    }

    public function isApplied(): bool
    {
        return $this->status === ContractAmendmentStatus::Applied;
    }

    /** Approved amendments whose effective date has come, for the daily run. */
    public function scopeDueOn(Builder $query, CarbonInterface $day): Builder
    {
        return $query
            ->where('status', ContractAmendmentStatus::Approved)
            ->whereDate('effective_from', '<=', $day);
    }

    /**
     * The amendable terms of $contract as they stand now: what an amendment
     * records as its "before", and the starting point of its "after".
     *
     * @return array<string, mixed>
     */
    public static function snapshotOf(Contract $contract): array
    {
        $terms = [];

        foreach (self::AMENDABLE_FIELDS as $field) {
            $terms[$field] = $contract->getAttribute($field);
        }

        return $terms;
    }

    /**
     * The terms this amendment actually changes, in AMENDABLE_FIELDS order,
     * followed by `salary` and `pay_tiers` when the figures move.
     *
     * Values are compared normalised, so a form's "09:00" against the stored
     * "09:00:00", or "40" against 40, is not a change; the salary is compared
     * decrypted, since its ciphertext differs on every write.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        $before = $this->terms_before ?? [];
        $after = $this->terms_after ?? [];
        $changed = [];

        foreach (self::AMENDABLE_FIELDS as $field) {
            if (self::normalise($before[$field] ?? null) !== self::normalise($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        if (self::normalise($this->salary_before) !== self::normalise($this->salary_after)) {
            $changed[] = 'salary';
        }

        if ($this->tierSignature(ContractAmendmentPayTier::SIDE_BEFORE) !== $this->tierSignature(ContractAmendmentPayTier::SIDE_AFTER)) {
            $changed[] = 'pay_tiers';
        }

        return $changed;
    }

    public function touchesPay(): bool
    {
        return array_intersect($this->changedFields(), self::PAY_FIELDS) !== [];
    }

    /** Hours of one working day before and after, the way leave balances count them. */
    public function dailyHoursBefore(): int
    {
        return intdiv((int) ($this->terms_before['weekly_hours'] ?? 0), 5);
    }

    public function dailyHoursAfter(): int
    {
        return intdiv((int) ($this->terms_after['weekly_hours'] ?? 0), 5);
    }

    public function registerMediaCollections(): void
    {
        // The signed sheet carries the pay clause, so it stays on the private
        // disk like the avatars, never reachable by URL.
        $this->addMediaCollection('signed')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // No figures and no snapshots: the audit trail records who moved the
        // amendment through its steps, the terms are one join away.
        return LogOptions::defaults()
            ->useLogName('contract')
            ->logOnly(['contract_id', 'number', 'status', 'effective_from', 'approved_by', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Does $contract still carry exactly the terms this amendment recorded as
     * its "before"? If not, someone changed the contract in between, and
     * writing the "after" over it would silently undo their change.
     */
    public function matchesContract(Contract $contract): bool
    {
        $before = $this->terms_before ?? [];

        foreach (ContractAmendment::snapshotOf($contract) as $field => $value) {
            if (self::normalise($value) !== self::normalise($before[$field] ?? null)) {
                return false;
            }
        }

        return self::normalise($contract->salary) === self::normalise($this->salary_before)
            && self::tiersOf($contract->payTiers()->get()) === $this->tierSignature(ContractAmendmentPayTier::SIDE_BEFORE);
    }

    /**
     * Is any pay figure on this amendment stored but unreadable here? On a
     * database copy without the salary key every figure reads as null, and
     * applying would write that null over the contract's real pay.
     */
    public function hasUnreadablePay(): bool
    {
        foreach (['salary_before', 'salary_after'] as $field) {
            if (filled($this->getAttributes()[$field] ?? null) && $this->{$field} === null) {
                return true;
            }
        }

        return $this->payTiers()->get()->contains(
            fn (ContractAmendmentPayTier $tier) => filled($tier->getAttributes()['amount'] ?? null) && $tier->amount === null,
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private function tierSignature(int $side): array
    {
        $tiers = $this->relationLoaded('payTiers')
            ? $this->payTiers
            : $this->payTiers()->get();

        return self::tiersOf($tiers->where('side', $side));
    }

    /**
     * @param  iterable<ContractAmendmentPayTier|ContractPayTier>  $tiers
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private static function tiersOf(iterable $tiers): array
    {
        return collect($tiers)
            ->sortBy('sort_order')
            ->map(fn (ContractAmendmentPayTier|ContractPayTier $tier) => [
                trim((string) $tier->condition_en),
                trim((string) $tier->condition_ar),
                self::normalise($tier->amount),
            ])
            ->values()
            ->all();
    }

    private static function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        $value = trim((string) $value);

        // "09:00" and "09:00:00" are the same time.
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value.':00';
        }

        return $value;
    }
}
