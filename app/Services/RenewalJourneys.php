<?php

namespace App\Services;

use App\Console\Commands\SendContractExpirationWarnings;
use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\RenewalJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

/**
 * The renewal journey of an expiring contract (DEC-09 increment 2;
 * docs/ux/journeys.md §3.2, renewal-journey-implementation.md):
 *
 *     expiry warning → HR decision → new contract → leave balances
 *
 * Each step has one authoritative record, and this class never copies one
 * into another:
 *
 *  - warning     notification_deliveries, kind contract-expiry-warning (R1)
 *  - decision    this journey: decision, decided_by, decided_at (R2)
 *  - successor   a later contract on the same position (R4a / R4b)
 *  - leave       leave_balances of that successor (LeaveBalanceService)
 *
 * The decision is the only thing this class stores. "Stop" switches
 * contracts.auto_renew off through ContractAutoRenewalService::setAutoRenew(),
 * the same path as the contracts list toggle, so the observer's side effects
 * (equipment return) and the manageAutoRenew rule are the same. Each write is
 * serialized on the journey row and conditional on the state it moves from,
 * so a retried or double-clicked action changes nothing the second time and
 * reports that it did not. Every change is in the "renewal" activity log with
 * the person who made it. Authorization is the caller's job
 * (ContractPolicy::manageAutoRenew); setAutoRenew() re-checks it as well.
 */
class RenewalJourneys
{
    /** A journey starts this many days before the contract's end date. */
    public const START_DAYS = 30;

    /** The decision is Late when missing this close to the end date (or after it). */
    public const LATE_DAYS = 7;

    public const STEPS = ['warning', 'decision', 'successor', 'leave'];

    public const STATE_DONE = 'done';

    public const STATE_TODO = 'todo';          // waits on HR now

    public const STATE_WAITING = 'waiting';    // waits on an earlier step or a date

    public const STATE_PROBLEM = 'problem';    // cannot finish without a fix elsewhere

    public const STATE_SKIPPED = 'skipped';    // not needed on this path (renewal stopped)

    public function __construct(
        private ContractAutoRenewalService $autoRenewal,
        private LeaveBalanceService $leaveBalances,
    ) {}

    /**
     * Start a journey for every contract that ends within START_DAYS days and
     * could renew. A missed day is caught up on the next run. Returns how many
     * journeys were started.
     */
    public function startDue(?CarbonInterface $today = null): int
    {
        $today ??= now(config('app.timezone'))->startOfDay();
        $started = 0;

        $this->dueContracts($today)
            ->orderBy('contracts.id')
            ->chunkById(100, function (Collection $contracts) use (&$started): void {
                foreach ($contracts as $contract) {
                    if ($this->startForContract($contract)) {
                        $started++;
                    }
                }
            }, 'contracts.id', 'id');

        return $started;
    }

    /**
     * Contracts that need a journey: running, real (not a Draft reservation),
     * ending within the window, able to renew (not a trial, not terminated:
     * Contract::canAutoRenew), with no journey yet and no successor already in
     * place (renewed early by hand: nothing left to decide).
     *
     * @return Builder<Contract>
     */
    public function dueContracts(CarbonInterface $today): Builder
    {
        $trialTypeIds = collect(config('contracts.map_by_id', []))
            ->filter(fn (string $category): bool => $category === 'trial')
            ->keys()
            ->all();

        return Contract::query()
            ->where('contracts.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('contracts.type')->orWhere('contracts.type', '!=', ContractTypeEnum::Draft))
            ->whereNull('contracts.terminated_at')
            ->whereNull('contracts.termination_reason_id')
            ->whereNotNull('contracts.end_date')
            ->whereDate('contracts.end_date', '>=', $today->toDateString())
            ->whereDate('contracts.end_date', '<=', $today->copy()->addDays(self::START_DAYS)->toDateString())
            ->when($trialTypeIds !== [], fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('contracts.contract_type_id')
                ->orWhereNotIn('contracts.contract_type_id', $trialTypeIds)))
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('renewal_journeys')
                ->whereColumn('renewal_journeys.contract_id', 'contracts.id'))
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('contracts as later')
                ->whereColumn('later.employee_id', 'contracts.employee_id')
                ->whereColumn('later.position_id', 'contracts.position_id')
                ->whereColumn('later.id', '!=', 'contracts.id')
                ->whereColumn('later.start_date', '>', 'contracts.start_date')
                ->where(fn ($w) => $w->whereNull('later.type')->orWhere('later.type', '!=', ContractTypeEnum::Draft)));
    }

    /**
     * One journey per contract: the unique contract_id makes a retry, a second
     * run or a second worker a no-op. Returns null when nothing was started.
     */
    public function startForContract(Contract $contract): ?RenewalJourney
    {
        if (! $contract->canAutoRenew() || (int) $contract->type === ContractTypeEnum::Draft) {
            return null;
        }

        $now = now();
        $inserted = RenewalJourney::query()->insertOrIgnore([
            'contract_id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'started_at' => $now,
            'decision_switched_auto_renew' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        $journey = RenewalJourney::query()->where('contract_id', $contract->id)->firstOrFail();
        $this->record($journey, 'renewal_started', null, ['end_date' => $contract->end_date?->toDateString()]);

        return $journey;
    }

    /**
     * Record HR's decision. "stop" switches auto-renew off through the shared
     * path; "renew" changes no contract (auto-renew stays as it is, and HR
     * renews by hand when it is off). Refused once a decision is recorded, for
     * a terminated contract, and "stop" once a successor exists.
     */
    public function decide(RenewalJourney $journey, string $decision, User $actor): bool
    {
        if (! in_array($decision, RenewalJourney::DECISIONS, true)) {
            return false;
        }

        return $this->change($journey, $actor, 'decision_recorded', function (RenewalJourney $locked, Contract $contract) use ($decision, $actor): ?array {
            if ($locked->decision !== null || $contract->isTerminated()) {
                return null;
            }

            $switched = false;

            if ($decision === RenewalJourney::DECISION_STOP) {
                if ($this->successorOf($contract)) {
                    return null;
                }

                $switched = $this->autoRenewal->setAutoRenew($contract, false, $actor);
            }

            $locked->forceFill([
                'decision' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_switched_auto_renew' => $switched,
            ]);

            return ['decision' => $decision, 'auto_renew_switched_off' => $switched];
        });
    }

    /**
     * Undo a decision recorded by mistake, while no successor exists. A "stop"
     * that switched auto-renew off switches it back on (same shared path); one
     * that found it already off leaves it off. The log keeps both.
     */
    public function undo(RenewalJourney $journey, User $actor): bool
    {
        return $this->change($journey, $actor, 'decision_undone', function (RenewalJourney $locked, Contract $contract) use ($actor): ?array {
            if ($locked->decision === null || $this->successorOf($contract)) {
                return null;
            }

            $restored = $locked->decision === RenewalJourney::DECISION_STOP
                && $locked->decision_switched_auto_renew
                && $this->autoRenewal->setAutoRenew($contract, true, $actor);

            $from = $locked->decision;
            $locked->forceFill([
                'decision' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_switched_auto_renew' => false,
            ]);

            return ['from' => $from, 'auto_renew_restored' => $restored];
        });
    }

    /**
     * Bring completed_at in line with the steps for journeys the system
     * finished (an auto-renewal overnight, a termination). Returns how many
     * changed.
     */
    public function settleOpen(): int
    {
        $changed = 0;

        RenewalJourney::query()
            ->whereNull('completed_at')
            ->orderBy('id')
            ->chunkById(100, function (Collection $journeys) use (&$changed): void {
                foreach ($journeys as $journey) {
                    $done = DB::transaction(function () use ($journey): bool {
                        $locked = RenewalJourney::query()->whereKey($journey->id)->lockForUpdate()->first();

                        return $locked !== null && $this->settle($locked, null);
                    });

                    if ($done) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * The employee's journeys HR should see: the open ones, and the ones
     * finished in the last START_DAYS days (so the outcome and its undo stay
     * in view for a while). Newest contract end first.
     *
     * @return Collection<int, RenewalJourney>
     */
    public function forEmployee(Employee $employee): Collection
    {
        return RenewalJourney::query()
            ->where('employee_id', $employee->id)
            ->where(fn (Builder $q) => $q->whereNull('completed_at')
                ->orWhere('completed_at', '>=', now()->subDays(self::START_DAYS)))
            ->with(['contract.position', 'contract.contractType', 'decidedBy.profile'])
            ->get()
            ->sortByDesc(fn (RenewalJourney $j) => $j->contract?->end_date?->timestamp ?? 0)
            ->values();
    }

    /**
     * Where the renewal stands, step by step: state, who acts, due date and
     * what it waits on.
     *
     * @return list<array{key: string, state: string, owner: string, due: ?CarbonInterface, late: bool, blocked_by: ?string, note: ?string, data: array<string, mixed>}>
     */
    public function steps(RenewalJourney $journey): array
    {
        $contract = $journey->contract;
        $successor = $this->successorOf($contract);
        $decision = $journey->decision;
        $stopped = $decision === RenewalJourney::DECISION_STOP;
        $terminated = $contract->isTerminated();

        // R1: the warning ledger.
        $warnings = DB::table('notification_deliveries')
            ->where('kind', SendContractExpirationWarnings::LEDGER_KIND)
            ->where('subject_id', $contract->id)
            ->whereNotNull('delivered_at');
        $lastWarning = (clone $warnings)->max('local_date');
        $hasManager = $contract->position?->directManager?->employee()?->account !== null;

        $steps = [];

        $steps[] = $this->step('warning', match (true) {
            $lastWarning !== null => self::STATE_DONE,
            ! $hasManager => self::STATE_PROBLEM,
            ! $contract->auto_renew => self::STATE_SKIPPED,
            default => self::STATE_WAITING,
        }, 'system', note: match (true) {
            $lastWarning !== null => null,
            ! $hasManager => 'no-manager',
            ! $contract->auto_renew => 'auto-renew-off',
            default => 'warning-pending',
        }, data: ['last_sent_on' => $lastWarning, 'count' => $warnings->count()]);

        // R2: the decision, the one record this journey owns.
        $steps[] = $this->step('decision', match (true) {
            $decision !== null => self::STATE_DONE,
            $terminated => self::STATE_SKIPPED,
            default => self::STATE_TODO,
        }, 'hr', due: $contract->end_date, late: ! $terminated && $journey->decisionIsLate(), note: $terminated && $decision === null ? 'terminated' : null, data: [
            'decision' => $decision,
            'decided_at' => $journey->decided_at,
            'decided_by' => $journey->decidedBy,
            'auto_renew' => (bool) $contract->auto_renew,
            'can_decide' => $decision === null && ! $terminated,
            'can_stop' => $decision === null && ! $terminated && $successor === null,
            'can_undo' => $decision !== null && $successor === null,
        ]);

        // R4a / R4b: the successor contract.
        $auto = (bool) $contract->auto_renew;
        $steps[] = $this->step('successor', match (true) {
            $successor !== null => self::STATE_DONE,
            $stopped || $terminated => self::STATE_SKIPPED,
            $decision === null => self::STATE_WAITING,
            $auto => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, ($decision === RenewalJourney::DECISION_RENEW && ! $auto && $successor === null) ? 'hr' : 'system', blockedBy: $decision === null ? 'decision' : null, note: match (true) {
            $successor !== null, $stopped, $terminated, $decision === null => null,
            $auto => 'renews-automatically',
            default => 'renew-by-hand',
        }, data: [
            'contract_id' => $successor?->id,
            'start_date' => $successor?->start_date,
            'auto_renew_on' => $contract->end_date?->copy()->addDay(),
            'source_id' => $contract->id,
            'can_renew_by_hand' => $successor === null && $decision === RenewalJourney::DECISION_RENEW && ! $auto
                && $contract->is_active && ! $terminated,
        ]);

        // Leave balances on the successor.
        $exempt = $successor !== null && $this->leaveBalances->exemptFromLeave($successor);
        $hasBalances = $successor !== null && $successor->leaveBalances()->exists();
        $steps[] = $this->step('leave', match (true) {
            $hasBalances || $exempt => self::STATE_DONE,
            $successor !== null => self::STATE_PROBLEM,
            $stopped || $terminated => self::STATE_SKIPPED,
            default => self::STATE_WAITING,
        }, 'system', blockedBy: $successor === null ? 'successor' : null, note: match (true) {
            $exempt && ! $hasBalances => 'leave-exempt',
            $successor !== null && ! $hasBalances => 'leave-missing',
            default => null,
        });

        return $steps;
    }

    /** The contract that carries this one forward: same employee and position, starting later. */
    public function successorOf(Contract $contract): ?Contract
    {
        return Contract::query()
            ->where('employee_id', $contract->employee_id)
            ->where('position_id', $contract->position_id)
            ->whereKeyNot($contract->getKey())
            ->where(fn (Builder $q) => $q->whereNull('type')->orWhere('type', '!=', ContractTypeEnum::Draft))
            ->whereDate('start_date', '>', $contract->start_date?->toDateString() ?? '1900-01-01')
            ->orderBy('start_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  callable(RenewalJourney, Contract): ?array<string, mixed>  $apply  returns the log properties, or null for "nothing to change"
     */
    private function change(RenewalJourney $journey, User $actor, string $event, callable $apply): bool
    {
        return DB::transaction(function () use ($journey, $actor, $event, $apply): bool {
            $locked = RenewalJourney::query()->whereKey($journey->getKey())->lockForUpdate()->first();
            $contract = $locked?->contract;

            if (! $locked || ! $contract) {
                return false;
            }

            $properties = $apply($locked, $contract);

            if ($properties === null) {
                return false;
            }

            $locked->save();
            $this->record($locked, $event, $actor, $properties);
            $this->settle($locked, $actor);
            $journey->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * completed_at follows the steps, so an undo reopens the journey too.
     * Complete when: renewal stopped; or renewed with the successor and its
     * leave balances in place; or the contract was terminated (the exit
     * journey takes over). Call with the journey row locked.
     */
    private function settle(RenewalJourney $journey, ?User $actor): bool
    {
        $contract = $journey->contract()->first();

        if (! $contract) {
            return false;
        }

        $outcome = match (true) {
            $journey->decision === RenewalJourney::DECISION_STOP => 'stopped',
            $contract->isTerminated() => 'terminated',
            $journey->decision === RenewalJourney::DECISION_RENEW && $this->renewedWithLeave($contract) => 'renewed',
            default => null,
        };

        if (($outcome !== null) === ($journey->completed_at !== null)) {
            return false;
        }

        $journey->forceFill(['completed_at' => $outcome !== null ? now() : null])->save();
        $this->record($journey, $outcome !== null ? 'renewal_completed' : 'renewal_reopened', $actor, $outcome !== null ? ['outcome' => $outcome] : []);

        return true;
    }

    private function renewedWithLeave(Contract $contract): bool
    {
        $successor = $this->successorOf($contract);

        return $successor !== null
            && ($successor->leaveBalances()->exists() || $this->leaveBalances->exemptFromLeave($successor));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(RenewalJourney $journey, string $event, ?User $actor, array $properties): void
    {
        $logger = Activity::useLog('renewal')
            ->performedOn($journey)
            ->withProperties(['contract_id' => $journey->contract_id, 'employee_id' => $journey->employee_id] + $properties);

        if ($actor) {
            $logger->causedBy($actor);
        }

        $logger->log($event);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, state: string, owner: string, due: ?CarbonInterface, late: bool, blocked_by: ?string, note: ?string, data: array<string, mixed>}
     */
    private function step(string $key, string $state, string $owner, ?CarbonInterface $due = null, bool $late = false, ?string $blockedBy = null, ?string $note = null, array $data = []): array
    {
        return [
            'key' => $key,
            'state' => $state,
            'owner' => $owner,
            'due' => $due,
            'late' => $late && $state !== self::STATE_DONE,
            'blocked_by' => $state === self::STATE_WAITING ? $blockedBy : null,
            'note' => $note,
            'data' => $data,
        ];
    }
}
