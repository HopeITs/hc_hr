<?php

namespace App\Services;

use App\Enums\ContractType;
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
     * Does this contract wait for the new year? A contract ending in one of
     * config('contracts.renew_at_new_year_months') (October to December) is
     * not renewed the day after it ends, which made renewals of a few days
     * up to 31 December: it is renewed on 1 January, for the whole year.
     */
    public static function waitsForNewYear(Contract $contract): bool
    {
        return $contract->end_date !== null
            && in_array($contract->end_date->month, (array) config('contracts.renew_at_new_year_months', []), true)
            && $contract->end_date->format('m-d') !== '12-31';
    }

    /**
     * The day the renewal of $contract starts: 1 January after it for a
     * contract that waits for the new year, otherwise the day after it ends.
     * The one rule the renewal run, the renewal journey and the departure
     * check (OffboardingJourneys::awaitsAutoRenewal) all read.
     */
    public static function renewalStart(Contract $contract): ?CarbonInterface
    {
        if ($contract->end_date === null) {
            return null;
        }

        return self::waitsForNewYear($contract)
            ? $contract->end_date->copy()->addYear()->startOfYear()
            : $contract->end_date->copy()->addDay();
    }

    /**
     * Ended, set to auto-renew, and waiting for the new year: the contracts
     * city HR is told about (ProcessContractAutoRenewals) so the gap until
     * 1 January is not a surprise.
     *
     * @return Collection<int, Contract>
     */
    public function waitingForNewYear(?CarbonInterface $asOf = null): Collection
    {
        $asOf ??= today();

        return $this->renewable($asOf)
            ->with('employee.city')
            ->get()
            ->filter(fn (Contract $contract) => $contract->canAutoRenew()
                && self::waitsForNewYear($contract)
                && self::renewalStart($contract)->toDateString() > $asOf->toDateString())
            ->values();
    }

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

        $this->renewable($asOf)
            ->with(['position', 'payTiers'])
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
                ->with(['position', 'payTiers', 'employee'])
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
                // A deactivated employee has left: no new contract for them.
                || ! $source->employee?->is_active
            ) {
                return null;
            }

            $start = self::renewalStart($source);

            // Ended in October to December: renewed on 1 January, not before.
            if ($start->toDateString() > $asOf->toDateString()) {
                return null;
            }

            if ($this->successorExists($source)) {
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

            // The days worked at the end of the old term, a day off moved since included.
            if ($source->end_date !== null && ($lastDays = $source->workWeekdaysOn($source->end_date)) !== null) {
                $renewal->work_weekdays = implode(',', $lastDays);
                $renewal->work_day_from = null;
                $renewal->work_day_to = null;
            }

            $renewal->unsetRelation('workPatterns');
            $renewal->save();
            app(ContractWorkPatterns::class)->syncInitial($renewal);

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
     * Ended before $asOf, inactive, auto-renew on, never terminated, and the
     * employee still active: a deactivated employee has left, so neither the
     * renewal nor the new-year notice is for them.
     */
    private function renewable(CarbonInterface $asOf): \Illuminate\Database\Eloquent\Builder
    {
        return Contract::query()
            ->where('auto_renew', true)
            ->where('is_active', false)
            ->whereNull('terminated_at')
            ->whereNull('termination_reason_id')
            ->whereDate('end_date', '<', $asOf->toDateString())
            ->whereHas('employee', fn ($employee) => $employee->where('employees.is_active', true));
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

    private function successorExists(Contract $source): bool
    {
        return Contract::query()
            ->where('id', '!=', $source->id)
            ->where('employee_id', $source->employee_id)
            ->where('type', '!=', ContractType::Draft)
            ->whereDate('start_date', '>', $source->end_date)
            ->exists();
    }
}
