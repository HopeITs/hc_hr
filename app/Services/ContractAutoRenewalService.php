<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ContractAutoRenewalService
{
    public function __construct(private LeaveBalanceService $leaveBalances) {}

    /**
     * Switch a contract's auto-renew on or off: the one write path behind the
     * contracts list toggle and the renewal journey's "stop" decision
     * (ContractPolicy::manageAutoRenew, i.e. HR active role + contracts.update
     * + the contract visible in the actor's city, is re-checked here).
     *
     * The write goes through Eloquent so ContractObserver still runs: saving()
     * keeps a trial or terminated contract off, and updated() starts the
     * equipment-return cycles when the flag goes from on to off.
     *
     * Returns false when nothing changed: the flag was already $on, or $on is
     * true for a contract that may never auto-renew.
     */
    public function setAutoRenew(Contract $contract, bool $on, User $actor): bool
    {
        Gate::forUser($actor)->authorize('manageAutoRenew', $contract);

        return DB::transaction(function () use ($contract, $on): bool {
            $locked = Contract::query()->whereKey($contract->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->auto_renew === $on || ($on && ! $locked->canAutoRenew())) {
                return false;
            }

            $locked->update(['auto_renew' => $on]);
            $contract->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * @return Collection<int, Contract>
     */
    public function process(?CarbonInterface $asOf = null): Collection
    {
        $asOf ??= today();
        $renewed = collect();

        Contract::query()
            ->with(['position', 'payTiers'])
            ->where('auto_renew', true)
            ->where('is_active', false)
            ->whereNull('terminated_at')
            ->whereNull('termination_reason_id')
            ->whereDate('end_date', '<', $asOf->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use ($renewed, $asOf): void {
                foreach ($contracts as $contract) {
                    $renewal = $this->renewIfNeeded($contract, $asOf);

                    if ($renewal) {
                        $renewed->push($renewal);
                    }
                }
            });

        return $renewed;
    }

    public function renewIfNeeded(Contract $contract, CarbonInterface $asOf): ?Contract
    {
        return DB::transaction(function () use ($contract, $asOf): ?Contract {
            $source = Contract::query()
                ->with(['position', 'payTiers'])
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $source
                || ! $source->auto_renew
                // A legacy row can still carry the flag from before
                // ContractObserver::saving() forced it off for these.
                || ! $source->canAutoRenew()
                || $source->is_active
                || ! $source->end_date
                || $source->end_date->gte($asOf)
            ) {
                return null;
            }

            $start = $source->end_date->copy()->addDay();

            if ($this->successorExists($source, $start->toDateString())) {
                return null;
            }

            $renewal = $source->replicate([
                'id',
                'created_at',
                'updated_at',
            ]);

            $renewal->renewalSourceId = $source->id;
            $renewal->start_date = $start->toDateString();
            $renewal->end_date = $asOf->copy()->endOfYear()->toDateString();
            $renewal->terminated_at = null;
            $renewal->termination_reason_id = null;
            $renewal->reason_of_termination = null;
            $renewal->is_active = true;
            $renewal->auto_renew = true;
            $renewal->save();

            foreach ($source->payTiers as $tier) {
                $renewal->payTiers()->create($tier->only([
                    'condition_en',
                    'condition_ar',
                    'amount',
                    'sort_order',
                ]));
            }

            $this->createLeaveBalances($renewal);

            return $renewal->refresh();
        });
    }

    /**
     * The renewal is a new contract, so it gets its own leave balances, the
     * same LeaveBalanceService::create() ContractCreate and the renew modal
     * call (renewal journey, journeys.md §3.2: before this, an auto-renewed
     * contract had none). Inside a savepoint: a balance that cannot be priced
     * (no annual-leave rule on file) is reported and leaves the renewal
     * standing, so one bad row never blocks the whole night's run;
     * `leaves:backfill` fills it in later.
     */
    private function createLeaveBalances(Contract $renewal): void
    {
        try {
            DB::transaction(fn () => $this->leaveBalances->create($renewal));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function successorExists(Contract $source, string $startDate): bool
    {
        return Contract::query()
            ->where('id', '!=', $source->id)
            ->where('employee_id', $source->employee_id)
            ->where('position_id', $source->position_id)
            ->whereDate('start_date', $startDate)
            ->exists();
    }
}
