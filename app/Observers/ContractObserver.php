<?php

namespace App\Observers;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\OffboardingJourney;
use App\Models\Position;
use App\Models\User;
use App\Services\ChecklistWorkflow;
use App\Services\OffboardingJourneys;
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
        // yet, and neither does one entered already inactive: no capacity
        // check here, and created() below skips the matching inc(). A draft
        // claims its seat when upgraded to a real type — see updating()/updated().
        if (! $this->holdsSeat($contract->type, $contract->is_active)) {
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
        if (! $this->holdsSeat($contract->type, $contract->is_active)) {
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

    /**
     * A write that makes this contract claim a seat it did not hold — a draft
     * upgraded to a real type, a contract switched back on, an active one
     * moved to another position — is checked against the capacity of the
     * position it lands on, exactly as a new contract is. Releasing a seat, or
     * keeping the one it holds, needs no check. updated() moves the counter.
     */
    public function updating(Contract $contract): void
    {
        $claimId = $this->seatMove($contract)['claim'];

        if ($claimId === null) {
            return;
        }

        $pos = Position::query()
            ->whereKey($claimId)
            ->lockForUpdate()
            ->first();

        if (! $pos) {
            throw ValidationException::withMessages([
                'form.position_id' => __('validation.exists', ['attribute' => 'position']),
            ]);
        }
        if (! $pos->canAssign()) {
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

        // DEC-09 increment 2: stopping auto-renewal (E1) or terminating (E4) the
        // volunteer's last contract starts their exit journey. The service
        // decides whether it is the last one, and a repeat lands on the same row.
        if ($trigger = $this->departureTrigger($contract)) {
            $actor = auth()->user();
            app(OffboardingJourneys::class)->startForDeparture($contract, $trigger, $actor instanceof User ? $actor : null);
        }

        // exist_emp counts the position's active, non-draft contracts. Every
        // way a write can change that — a termination or the nightly
        // deactivation switching is_active off (E4/R3), a reactivation, a
        // draft upgraded, a move to another position — comes down to the
        // seat it held before and the seat it holds now.
        ['release' => $releaseId, 'claim' => $claimId] = $this->seatMove($contract);

        if ($releaseId === null && $claimId === null) {
            return;
        }

        $locked = Position::query()
            ->whereIn('id', array_values(array_filter([$releaseId, $claimId], fn ($id) => $id !== null)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($releaseId !== null) {
            $locked->get($releaseId)?->dec();
        }
        if ($claimId !== null) {
            $locked->get($claimId)?->inc();
        }
    }

    public function deleted(Contract $contract): void
    {
        DB::transaction(function () use ($contract) {
            // A draft or an inactive contract never held a seat to give back.
            if (! $this->holdsSeat($contract->getOriginal('type'), $contract->getOriginal('is_active'))) {
                return;
            }

            $pos = Position::query()
                ->whereKey($contract->getOriginal('position_id'))
                ->lockForUpdate()
                ->first();

            $pos?->dec();
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

    /** Did the write that just completed terminate $contract (E4) or stop its auto-renewal (E1)? */
    private function departureTrigger(Contract $contract): ?string
    {
        $wasTerminated = filled($contract->getOriginal('terminated_at')) || filled($contract->getOriginal('termination_reason_id'));

        if (! $wasTerminated && $contract->isTerminated()) {
            return OffboardingJourney::TRIGGER_TERMINATED;
        }

        if ($contract->wasChanged('auto_renew') && (bool) $contract->getOriginal('auto_renew') && ! $contract->auto_renew) {
            return OffboardingJourney::TRIGGER_AUTO_RENEW_STOPPED;
        }

        return null;
    }

    /**
     * Does a contract of this type and active flag hold a seat on its
     * position? A draft never does; anything else does while it is active.
     * An unset flag is the column default (true): a contract created without
     * one is active, and was counted by created().
     */
    private function holdsSeat(mixed $type, mixed $isActive): bool
    {
        return (int) $type !== ContractTypeEnum::Draft && ($isActive === null || (bool) $isActive);
    }

    /**
     * The seat $contract's pending (updating) or just-completed (updated)
     * write gives up and the one it takes, as position ids; null for neither.
     * getOriginal() still holds the stored row in both hooks.
     *
     * @return array{release: ?int, claim: ?int}
     */
    private function seatMove(Contract $contract): array
    {
        $held = $this->holdsSeat($contract->getOriginal('type'), $contract->getOriginal('is_active'));
        $holds = $this->holdsSeat($contract->type, $contract->is_active);

        $oldId = (int) $contract->getOriginal('position_id');
        $newId = (int) $contract->position_id;
        $samePosition = $oldId === $newId;

        return [
            'release' => $held && (! $holds || ! $samePosition) ? $oldId : null,
            'claim' => $holds && (! $held || ! $samePosition) ? $newId : null,
        ];
    }
}
