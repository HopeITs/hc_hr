<?php

namespace App\Services;

use App\Enums\ContractAmendmentStatus;
use App\Enums\ContractType as ContractTypeEnum;
use App\Exceptions\ContractAmendmentCannotApply;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAmendmentPayTier;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The life of a contract amendment (ملحق عقد): draft → approve → apply.
 *
 * Applying writes the amendment's "after" terms onto the contract row, so the
 * contract keeps holding the current terms and everything reading it (leave
 * pricing, exports, renewals) needs no change; the amendment keeps the history.
 */
class ContractAmendmentService
{
    public function __construct(private LeaveBalanceService $leaveBalances) {}

    /**
     * Record a draft amendment of $contract.
     *
     * $changes holds only what changes: any of ContractAmendment::AMENDABLE_FIELDS,
     * plus `salary` and `pay_tiers` (a list of condition_en / condition_ar /
     * amount). Anything else is ignored. What is left out stays as it is.
     */
    public function draft(Contract $contract, array $changes, string|CarbonInterface $effectiveFrom, ?string $reason = null, ?User $by = null): ContractAmendment
    {
        $effectiveFrom = Carbon::parse($effectiveFrom)->startOfDay();

        $this->assertAmendable($contract);
        $this->assertNoneOpen($contract);
        $this->assertEffectiveDate($contract, $effectiveFrom);

        if (array_key_exists('type', $changes)
            && ! in_array((int) $changes['type'], [ContractTypeEnum::FullTime, ContractTypeEnum::PartTime, ContractTypeEnum::Freelance], true)) {
            throw ValidationException::withMessages(['type' => __('contracts.amendment.errors.invalid-type')]);
        }

        // The "before" figure must be readable, or the amendment would record
        // a null as the old pay and later write it back as the new one.
        if (filled($contract->getAttributes()['salary'] ?? null) && $contract->salary === null) {
            throw ValidationException::withMessages(['salary' => __('contracts.amendment.errors.unreadable-pay')]);
        }

        $before = ContractAmendment::snapshotOf($contract);
        $after = array_merge($before, array_map(
            // A form sends "15:00"; the column holds "15:00:00".
            fn ($value) => is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value.':00' : $value,
            array_intersect_key($changes, array_flip(ContractAmendment::AMENDABLE_FIELDS)),
        ));
        $tiersBefore = $contract->payTiers()->get();

        return DB::transaction(function () use ($contract, $changes, $effectiveFrom, $reason, $by, $before, $after, $tiersBefore): ContractAmendment {
            $amendment = ContractAmendment::query()->create([
                'contract_id' => $contract->id,
                'number' => $contract->nextAmendmentNumber(),
                'status' => ContractAmendmentStatus::Draft,
                'effective_from' => $effectiveFrom->toDateString(),
                'reason' => filled($reason) ? trim($reason) : null,
                'terms_before' => $before,
                'terms_after' => $after,
                'salary_before' => $contract->salary,
                'salary_after' => array_key_exists('salary', $changes) ? $changes['salary'] : $contract->salary,
                'created_by' => $by?->id,
            ]);

            foreach ($tiersBefore as $tier) {
                $this->addTier($amendment, ContractAmendmentPayTier::SIDE_BEFORE, $tier->condition_en, $tier->condition_ar, $tier->amount, $tier->sort_order);
            }

            $tiersAfter = array_key_exists('pay_tiers', $changes)
                ? collect($changes['pay_tiers'])->values()->map(fn (array $tier, int $i) => [$tier['condition_en'] ?? '', $tier['condition_ar'] ?? '', $tier['amount'] ?? null, $i])
                : $tiersBefore->map(fn ($tier) => [$tier->condition_en, $tier->condition_ar, $tier->amount, $tier->sort_order]);

            foreach ($tiersAfter as [$en, $ar, $amount, $order]) {
                $this->addTier($amendment, ContractAmendmentPayTier::SIDE_AFTER, $en, $ar, $amount, $order);
            }

            if ($amendment->changedFields() === []) {
                throw ValidationException::withMessages(['terms' => __('contracts.amendment.errors.nothing-changed')]);
            }

            return $amendment;
        });
    }

    /** A draft that was never signed can simply be thrown away. */
    public function discard(ContractAmendment $amendment): void
    {
        if (! $amendment->isDraft()) {
            throw ValidationException::withMessages(['amendment' => __('contracts.amendment.errors.not-a-draft')]);
        }

        $amendment->delete();
    }

    /**
     * The signed sheet is back: approve the draft, and apply it at once when
     * its effective date has already come (the daily run applies the rest).
     */
    public function approve(ContractAmendment $amendment, ?User $by = null): ContractAmendment
    {
        if (! $amendment->isDraft()) {
            throw ValidationException::withMessages(['amendment' => __('contracts.amendment.errors.not-a-draft')]);
        }

        $this->assertAmendable($amendment->contract);

        $amendment->update([
            'status' => ContractAmendmentStatus::Approved,
            'approved_by' => $by?->id,
            'approved_at' => now(),
        ]);

        if ($amendment->effective_from->lte(today())) {
            $this->apply($amendment);
        }

        return $amendment->refresh();
    }

    /**
     * Write an approved amendment's terms onto its contract and convert the
     * leave balances to the new working day. All or nothing.
     *
     * @throws ContractAmendmentCannotApply
     */
    public function apply(ContractAmendment $amendment): void
    {
        DB::transaction(function () use ($amendment): void {
            $amendment = ContractAmendment::query()->lockForUpdate()->findOrFail($amendment->id);

            if (! $amendment->isApproved()) {
                throw ContractAmendmentCannotApply::notApproved();
            }

            $contract = Contract::query()->lockForUpdate()->findOrFail($amendment->contract_id);

            if ($amendment->hasUnreadablePay()) {
                throw ContractAmendmentCannotApply::unreadablePay();
            }

            if (! $amendment->matchesContract($contract)) {
                throw ContractAmendmentCannotApply::contractChanged();
            }

            $changed = $amendment->changedFields();

            $contract->fill(array_intersect_key($amendment->terms_after, array_flip($changed)));

            if (in_array('salary', $changed, true)) {
                $contract->salary = $amendment->salary_after;
            }

            $contract->save();

            if (in_array('pay_tiers', $changed, true)) {
                $contract->payTiers()->delete();

                foreach ($amendment->payTiersAfter()->get() as $tier) {
                    $contract->payTiers()->create([
                        'condition_en' => $tier->condition_en,
                        'condition_ar' => $tier->condition_ar,
                        'amount' => $tier->amount,
                        'sort_order' => $tier->sort_order,
                    ]);
                }
            }

            $this->leaveBalances->rescaleForDailyHours($contract, $amendment->dailyHoursBefore(), $amendment->dailyHoursAfter());

            $amendment->update([
                'status' => ContractAmendmentStatus::Applied,
                'applied_at' => now(),
            ]);
        });
    }

    /**
     * Apply every approved amendment whose effective date has come. One that
     * cannot be applied is logged and left approved; it never stops the rest.
     *
     * @return array{applied: Collection<int, ContractAmendment>, failed: Collection<int, ContractAmendment>}
     */
    public function applyDue(?CarbonInterface $day = null): array
    {
        $day ??= today();
        $applied = collect();
        $failed = collect();

        ContractAmendment::query()
            ->dueOn($day)
            ->orderBy('effective_from')
            ->orderBy('number')
            ->get()
            ->each(function (ContractAmendment $amendment) use ($applied, $failed): void {
                try {
                    $this->apply($amendment);
                    $applied->push($amendment->refresh());
                } catch (ContractAmendmentCannotApply $e) {
                    Log::warning('Contract amendment not applied', [
                        'amendment_id' => $amendment->id,
                        'contract_id' => $amendment->contract_id,
                        'reason' => $e->getMessage(),
                    ]);
                    $failed->push($amendment);
                }
            });

        return ['applied' => $applied, 'failed' => $failed];
    }

    private function assertAmendable(Contract $contract): void
    {
        if (! $contract->canBeAmended()) {
            throw ValidationException::withMessages(['contract' => __('contracts.amendment.errors.not-in-force')]);
        }
    }

    /**
     * One amendment in flight at a time: a second draft would record a
     * "before" that the first one is about to change.
     */
    private function assertNoneOpen(Contract $contract): void
    {
        $open = ContractAmendment::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', [ContractAmendmentStatus::Draft, ContractAmendmentStatus::Approved])
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['contract' => __('contracts.amendment.errors.one-open')]);
        }
    }

    private function assertEffectiveDate(Contract $contract, CarbonInterface $effectiveFrom): void
    {
        if ($effectiveFrom->lt($contract->start_date) || $effectiveFrom->gt($contract->end_date)) {
            throw ValidationException::withMessages(['effective_from' => __('contracts.amendment.errors.outside-term')]);
        }

        $latest = ContractAmendment::query()
            ->where('contract_id', $contract->id)
            ->where('status', ContractAmendmentStatus::Applied)
            ->max('effective_from');

        if ($latest !== null && $effectiveFrom->lt(Carbon::parse($latest))) {
            throw ValidationException::withMessages(['effective_from' => __('contracts.amendment.errors.before-latest', [
                'date' => Carbon::parse($latest)->format('d/m/Y'),
            ])]);
        }
    }

    private function addTier(ContractAmendment $amendment, int $side, ?string $en, ?string $ar, mixed $amount, int $order): void
    {
        $amendment->payTiers()->create([
            'side' => $side,
            'condition_en' => (string) $en,
            'condition_ar' => (string) $ar,
            'amount' => $amount,
            'sort_order' => $order,
        ]);
    }
}
