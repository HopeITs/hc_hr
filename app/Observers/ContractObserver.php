<?php

namespace App\Observers;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Position;
use App\Services\ChecklistWorkflow;
use App\Support\StatisticsCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractObserver
{
    /**
     * A terminated contract has ended for good, and a trial is meant to end
     * and be evaluated — neither may ever roll over.
     *
     * Enforced here rather than in the form because a termination can be
     * saved by a role that may not touch auto_renew (ContractForm only writes
     * the flag for HR), because HR could otherwise switch a trial on from the
     * contracts list, and because ContractAutoRenewalService picks up every
     * inactive auto_renew contract once its end_date passes. Runs before
     * creating(), so the default there never sees an unset flag on either.
     */
    public function saving(Contract $contract): void
    {
        if (! $contract->canAutoRenew()) {
            $contract->auto_renew = false;
        }
    }

    public function creating(Contract $contract): void
    {
        // Auto-renew defaults true — right for volunteer and service
        // contracts; saving() above has already forced it off for a trial.
        // Only applied when the caller left it unset, so an explicit false is
        // never overridden.
        if (! array_key_exists('auto_renew', $contract->getAttributes())) {
            $contract->auto_renew = true;
        }

        // A draft contract (a placeholder position/center reservation
        // created from the employee-create screen) does not claim a seat
        // yet: no capacity check here, and created() below skips the
        // matching inc(). The seat is claimed only when the draft is
        // upgraded to a real type — see updating()/updated().
        if ((int) $contract->type === ContractTypeEnum::Draft) {
            return;
        }

        if (app()->runningInConsole()) {
            return;
        }

        // A renewal onto the same position is the same person keeping the same
        // seat, so it is exempt from the capacity check. exist_emp still rises
        // by one for the overlap between issuing the renewal and the source
        // expiring, and `deactivate:contract` brings it back down from there.
        if ($contract->isRenewalInPlace()) {
            return;
        }

        $pos = Position::query()
            ->whereKey($contract->position_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $pos->canAssign()) {
            throw ValidationException::withMessages([
                'form.position_id' => __('contracts.errors.position-full'),
            ]);
        }
    }

    public function created(Contract $contract): void
    {
        if ((int) $contract->type === ContractTypeEnum::Draft) {
            return;
        }

        $pos = Position::query()
            ->whereKey($contract->position_id)
            ->lockForUpdate()
            ->first();

        if ($pos) {
            $pos->inc();
        }
    }

    public function updating(Contract $contract): void
    {
        // Upgrading a draft to a real type claims the seat now, exactly as
        // if this were the contract's first creation: check capacity here,
        // and updated() below applies the matching inc(). No decrement is
        // needed on any "old" position, because a draft never incremented
        // one in the first place.
        if ($this->isUpgradingFromDraft($contract)) {
            $pos = Position::query()
                ->whereKey($contract->position_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $pos->canAssign()) {
                throw ValidationException::withMessages([
                    'form.position_id' => __('contracts.errors.position-full'),
                ]);
            }

            return;
        }

        // A contract that stays a draft carries no capacity bookkeeping at all.
        if ((int) $contract->type === ContractTypeEnum::Draft) {
            return;
        }

        if (! $contract->isDirty('position_id')) {
            return;
        }

        $oldId = (int) $contract->getOriginal('position_id');
        $newId = (int) $contract->position_id;

        [$a, $b] = $oldId < $newId ? [$oldId, $newId] : [$newId, $oldId];

        $locked = Position::query()
            ->whereIn('id', [$a, $b])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $new = $locked[$newId] ?? null;
        if (! $new) {
            throw ValidationException::withMessages([
                'form.position_id' => __('validation.exists', ['attribute' => 'position']),
            ]);
        }
        if (! $new->canAssign()) {
            throw ValidationException::withMessages([
                'form.position_id' => __('contracts.errors.position-full'),
            ]);
        }
    }

    public function updated(Contract $contract): void
    {
        // HR just switched this contract off auto-renew: the volunteer's
        // time here is now finite, so start collecting back whatever
        // equipment is still checked out to them instead of leaving it for
        // whoever notices on the last day.
        if ($contract->wasChanged('auto_renew')
            && (bool) $contract->getOriginal('auto_renew')
            && ! $contract->auto_renew) {
            app(ChecklistWorkflow::class)->requestBelongingsReturn($contract->employee);
        }

        if ($this->wasUpgradedFromDraft($contract)) {
            $pos = Position::query()
                ->whereKey($contract->position_id)
                ->lockForUpdate()
                ->first();

            if ($pos) {
                $pos->inc();
            }

            return;
        }

        if ((int) $contract->type === ContractTypeEnum::Draft) {
            return;
        }

        if (! $contract->wasChanged('position_id')) {
            return;
        }

        $oldId = (int) $contract->getOriginal('position_id');
        $newId = (int) $contract->position_id;

        [$a, $b] = $oldId < $newId ? [$oldId, $newId] : [$newId, $oldId];

        $locked = Position::query()
            ->whereIn('id', [$a, $b])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $old = $locked[$oldId] ?? null;
        $new = $locked[$newId] ?? null;

        if ($old) {
            $old->dec();
        }
        if ($new) {
            $new->inc();
        }
    }

    public function deleted(Contract $contract): void
    {
        DB::transaction(function () use ($contract) {
            $pos = Position::query()
                ->whereKey($contract->position_id)
                ->lockForUpdate()
                ->first();

            if ($pos && $pos->exist_emp > 0) {
                $pos->decrement('exist_emp');
            }
        });

        StatisticsCache::flush();
    }

    /**
     * Every dashboard aggregate is derived from contracts, so any write to this
     * table invalidates all of them.
     *
     * `saved` deliberately covers both insert and update in one hook rather than
     * duplicating the call in created() and updated() — a future code path that
     * saves a contract some other way inherits the invalidation for free.
     * Deletion is handled separately above because `saved` does not fire for it.
     */
    public function saved(Contract $contract): void
    {
        StatisticsCache::flush();
    }

    /** Is $contract's pending write the transition from Draft to a real type? */
    private function isUpgradingFromDraft(Contract $contract): bool
    {
        return (int) $contract->getOriginal('type') === ContractTypeEnum::Draft
            && (int) $contract->type !== ContractTypeEnum::Draft;
    }

    /** Did the write that just completed transition $contract from Draft to a real type? */
    private function wasUpgradedFromDraft(Contract $contract): bool
    {
        return $contract->wasChanged('type')
            && (int) $contract->getOriginal('type') === ContractTypeEnum::Draft
            && (int) $contract->type !== ContractTypeEnum::Draft;
    }
}
