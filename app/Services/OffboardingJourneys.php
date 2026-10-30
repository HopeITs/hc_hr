<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\OffboardingJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

/**
 * The exit journey of a departing volunteer (DEC-09 increment 2;
 * docs/ux/journeys.md §3.3, docs/ux/exit-journey-implementation.md):
 *
 *     equipment returned → contract ended → deactivated → access and card closed
 *                                                    (+ optional exit interview)
 *
 * Each step has one authoritative record, and this class never copies one
 * into another:
 *
 *  - equipment     checklists: nothing delivered without a received return  (ChecklistWorkflow)
 *  - contract      the contract the volunteer leaves on                       (ContractEdit, deactivate:contract)
 *  - deactivated   employees.is_active                                        (HR toggle, or automatic after the grace period)
 *  - access        account roles stood down + digital card disabled           (EmployeeObserver → AccessRevoker, DigitalIdManager)
 *  - interview     this journey (optional)
 *
 * It also owns the departure rule the daily `employees:deactivate-departed`
 * command applies: who has left (no current, future or pending auto-renewed
 * real contract), when their last contract ended, and when they are due to be
 * deactivated. The only automated employment decision here is that one, taken
 * by product-owner decision (DEC-09 increment 2) and through the same save
 * manual deactivation uses, so EmployeeObserver revokes access and disables
 * the card exactly as it does for HR.
 *
 * Every write is serialized (a lock on the row it moves) and conditional on the
 * state it moves from, so a retry changes nothing the second time and reports
 * that it did not. Every change is in the "offboarding" activity log with the
 * person who made it (none for the scheduler). Authorization is the caller's
 * job (EmployeePolicy::update, i.e. HR for the volunteer's city).
 */
class OffboardingJourneys
{
    public const STEPS = ['equipment', 'contract', 'deactivated', 'access', 'interview'];

    /** Steps that must be done for the journey to be complete; the interview is optional. */
    public const REQUIRED_STEPS = ['equipment', 'contract', 'deactivated', 'access'];

    /** A step is in exactly one of these (the same four as onboarding). */
    public const STATE_DONE = 'done';

    public const STATE_TODO = 'todo';          // waits on HR now

    public const STATE_WAITING = 'waiting';    // waits on someone else, or on an earlier step

    public const STATE_PROBLEM = 'problem';    // should have happened; needs a look

    public function graceDays(): int
    {
        return max(0, (int) config('journeys.exit_grace_days', 7));
    }

    public function today(): CarbonInterface
    {
        return now(config('app.timezone'))->startOfDay();
    }

    // ── The departure rule (read only) ───────────────────────────────

    /**
     * Has $employee left? Null while any real contract is current, starts in
     * the future, or has ended but is still due to auto-renew (the renewal
     * has not been made yet). Otherwise the contract they left on (the latest
     * to end), the day it ended and the day they are due to be deactivated.
     *
     * @param  Collection<int, Contract>|null  $contracts  the employee's real contracts, if already loaded
     * @return array{contract: Contract, ended_on: CarbonInterface, deactivate_on: CarbonInterface}|null
     */
    public function departure(Employee $employee, ?CarbonInterface $today = null, ?Collection $contracts = null): ?array
    {
        $today ??= $this->today();
        $contracts ??= $employee->realContracts()->get();

        if ($contracts->isEmpty() || $contracts->contains(fn (Contract $c) => $this->continues($c, $contracts, $today))) {
            return null;
        }

        $last = $contracts
            ->sortBy([
                fn (Contract $a, Contract $b) => $this->endsOn($a, $today)?->toDateString() <=> $this->endsOn($b, $today)?->toDateString(),
                fn (Contract $a, Contract $b) => $a->id <=> $b->id,
            ])
            ->last();

        $endedOn = $this->endsOn($last, $today) ?? $today->copy();

        return [
            'contract' => $last,
            'ended_on' => $endedOn,
            'deactivate_on' => $endedOn->copy()->addDays($this->graceDays()),
        ];
    }

    /**
     * Current or future: not terminated, and either starting after today or
     * active and not past its end date. A future contract counts whatever its
     * active flag says: nobody with a contract still to come is deactivated.
     */
    public function isCurrentOrFuture(Contract $contract, CarbonInterface $today): bool
    {
        if ($contract->isTerminated()) {
            return false;
        }

        if ($contract->start_date !== null && $contract->start_date->toDateString() > $today->toDateString()) {
            return true;
        }

        return (bool) $contract->is_active
            && ($contract->end_date === null || $contract->end_date->toDateString() >= $today->toDateString());
    }

    /**
     * Ended, set to auto-renew, and its renewal not made yet: the 21:35
     * auto-renewal run will make it (same successor rule as
     * ContractAutoRenewalService). Normally that has already happened by the
     * time this is asked; this only matters on a day the renewal run failed.
     *
     * @param  Collection<int, Contract>  $contracts
     */
    public function awaitsAutoRenewal(Contract $contract, Collection $contracts, CarbonInterface $today): bool
    {
        if (! $this->willRollOver($contract) || $contract->end_date === null
            || $contract->end_date->toDateString() >= $today->toDateString()) {
            return false;
        }

        $successorStart = $contract->end_date->copy()->addDay()->toDateString();

        return ! $contracts->contains(fn (Contract $other) => $other->id !== $contract->id
            && (int) $other->position_id === (int) $contract->position_id
            && $other->start_date?->toDateString() === $successorStart);
    }

    /**
     * The day a contract stops (or stopped) counting: the termination date
     * when one is recorded (or the end date if that came first), otherwise
     * the end date. A contract switched off early without a date (inactive,
     * or terminated by reason only) stopped the day it was switched off.
     */
    public function endsOn(Contract $contract, ?CarbonInterface $today = null): ?CarbonInterface
    {
        $today ??= $this->today();
        $end = $contract->end_date?->copy()->startOfDay();

        if ($contract->terminated_at !== null) {
            $terminated = $contract->terminated_at->copy()->startOfDay();

            return $end !== null && $end->lt($terminated) ? $end : $terminated;
        }

        if ($this->isCurrentOrFuture($contract, $today)) {
            return $end;
        }

        if ($end !== null && $end->toDateString() < $today->toDateString()) {
            return $end;
        }

        $off = $contract->updated_at?->copy()->timezone(config('app.timezone'))->startOfDay() ?? $today->copy();

        return $end !== null && $end->lt($off) ? $end : $off;
    }

    /** Set to roll over by itself: auto-renew on, and allowed to (not terminated, not a trial). */
    public function willRollOver(Contract $contract): bool
    {
        return (bool) $contract->auto_renew && $contract->canAutoRenew();
    }

    // ── Starting a journey ───────────────────────────────────────────

    /**
     * Start the exit journey for the departure $contract makes, if it is the
     * volunteer's last: HR stopped its auto-renewal (E1), terminated it (E4),
     * or it ended with no successor (the daily command). Returns the existing
     * journey when this departure already has one, and null when $contract is
     * not a departure (a reservation, a contract that still rolls over, or the
     * volunteer has another contract that carries on).
     */
    public function startForDeparture(Contract $contract, string $trigger, ?User $actor = null): ?OffboardingJourney
    {
        if ((int) $contract->type === ContractTypeEnum::Draft || $this->willRollOver($contract)) {
            return null;
        }

        $employee = Employee::query()->find($contract->employee_id);

        if (! $employee || $employee->isDraft()) {
            return null;
        }

        $today = $this->today();
        $contracts = $employee->realContracts()->get();

        if ($contracts->contains(fn (Contract $other) => $other->id !== $contract->id && $this->continues($other, $contracts, $today))) {
            return null;
        }

        $existing = $this->journeyFor($employee->id, $contract->id);

        if ($existing) {
            return $existing;
        }

        $journey = new OffboardingJourney;
        $journey->forceFill([
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'trigger' => $trigger,
            'started_at' => now(),
            'started_by' => $actor?->id,
        ]);

        try {
            $journey->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent save started it first: that row is this departure's.
            return $this->journeyFor($employee->id, $contract->id);
        }

        $this->record($journey, 'offboarding_started', $actor, ['contract_id' => $contract->id, 'trigger' => $trigger]);

        return $journey;
    }

    // ── HR's own step: the exit interview ────────────────────────────

    public function markExitInterviewHeld(Employee $employee, User $actor): bool
    {
        return $this->change($employee, $actor, 'exit_interview_held', function (OffboardingJourney $journey) use ($actor): ?array {
            if ($journey->exit_interview_held_at !== null) {
                return null;
            }

            $journey->forceFill([
                'exit_interview_held_at' => now(),
                'exit_interview_by' => $actor->id,
            ]);

            return [];
        });
    }

    /** Undo a mark made by mistake. The log keeps both the mark and the undo. */
    public function reopen(Employee $employee, string $step, User $actor): bool
    {
        return $this->change($employee, $actor, 'step_reopened', function (OffboardingJourney $journey) use ($step): ?array {
            if ($step !== 'interview' || $journey->exit_interview_held_at === null) {
                return null;
            }

            $journey->forceFill(['exit_interview_held_at' => null, 'exit_interview_by' => null]);

            return ['step' => $step];
        });
    }

    // ── Automatic deactivation (DEC-09 increment 2) ──────────────────

    /**
     * Deactivate $employee if they left at least the grace period ago. Locks
     * the employee row and re-reads the departure under the lock, so a
     * contract saved meanwhile wins. Uses the same save manual deactivation
     * does: EmployeeObserver disables the card and runs AccessRevoker.
     */
    public function deactivateDeparted(Employee $employee, ?CarbonInterface $today = null): bool
    {
        $today ??= $this->today();

        return DB::transaction(function () use ($employee, $today): bool {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->is_active || $locked->isDraft()) {
                return false;
            }

            $departure = $this->departure($locked, $today);

            if ($departure === null || $departure['deactivate_on']->toDateString() > $today->toDateString()) {
                return false;
            }

            $locked->is_active = false;
            $locked->save();

            $journey = $this->journeyFor($locked->id, $departure['contract']->id);

            Activity::useLog('offboarding')
                ->performedOn($locked)
                ->withProperties([
                    'employee_id' => $locked->id,
                    'reason' => 'last_contract_ended',
                    'contract_id' => $departure['contract']->id,
                    'ended_on' => $departure['ended_on']->toDateString(),
                    'grace_days' => $this->graceDays(),
                    'journey_id' => $journey?->id,
                ])
                ->log('employee_deactivated');

            return true;
        });
    }

    // ── Read model ───────────────────────────────────────────────────

    /**
     * A departure no longer stands: HR switched auto-renew back on, or the
     * volunteer has another contract that carries on (a late renewal, a new
     * contract). Never true once the journey was completed: that is history.
     */
    public function isCalledOff(OffboardingJourney $journey, ?Collection $contracts = null, ?CarbonInterface $today = null): bool
    {
        if ($journey->completed_at !== null) {
            return false;
        }

        $today ??= $this->today();
        $contract = $journey->contract;

        if ($contract !== null && $this->willRollOver($contract)) {
            return true;
        }

        $contracts ??= Contract::query()
            ->where('employee_id', $journey->employee_id)
            ->where('type', '!=', ContractTypeEnum::Draft)
            ->get();

        return $contracts->contains(fn (Contract $other) => $other->id !== $journey->contract_id
            && $this->continues($other, $contracts, $today));
    }

    /**
     * Where the departing volunteer stands, step by step: state, who acts, due
     * date and what blocks it. Null for anyone with no exit journey.
     *
     * @return list<array{key: string, state: string, owner: string, due: ?CarbonInterface, late: bool, blocked_by: ?string, note: ?string, data: array<string, mixed>}>|null
     */
    public function steps(Employee $employee): ?array
    {
        $journey = $employee->latestOffboardingJourney;

        if (! $journey) {
            return null;
        }

        $today = $this->today();
        $contracts = $employee->realContracts()->get();
        $contract = $journey->contract;
        $endsOn = $contract ? $this->endsOn($contract, $today) : null;
        $departure = $this->departure($employee, $today, $contracts);
        $deactivateOn = $departure['deactivate_on'] ?? $endsOn?->copy()->addDays($this->graceDays());

        $steps = [];

        // 1. Equipment: every delivered item has a received return (a finalized
        // cycle deletes the row, so it no longer counts either).
        $outstanding = Checklist::query()
            ->where('employee_id', $employee->id)
            ->where('delivered_status', 'delivered')
            ->where(fn (Builder $q) => $q->whereNull('returned_status')->orWhere('returned_status', '!=', 'received'))
            ->get(['id', 'returned_status']);
        $unrequested = $outstanding->whereNull('returned_status')->count();

        $steps[] = $this->step('equipment', match (true) {
            $outstanding->isEmpty() => self::STATE_DONE,
            $unrequested > 0 => self::STATE_TODO,
            default => self::STATE_WAITING,
        }, $outstanding->isNotEmpty() && $unrequested === 0 ? 'receiver' : 'hr', due: $endsOn, late: $this->isPast($endsOn, $today), data: [
            'outstanding' => $outstanding->count(),
            'unrequested' => $unrequested,
            'pending' => $outstanding->count() - $unrequested,
        ]);

        // 2. Contract ended.
        $contractEnded = $contract
            ? ! $this->isCurrentOrFuture($contract, $today) && $endsOn !== null && $endsOn->toDateString() <= $today->toDateString()
            : $departure !== null;

        $steps[] = $this->step('contract', $contractEnded ? self::STATE_DONE : self::STATE_WAITING, 'system', due: $endsOn, data: [
            'contract_id' => $contract?->id,
            'ends_on' => $endsOn,
        ]);

        // 3. Deactivated: automatic once the grace period after the end has passed.
        $deactivated = ! $employee->is_active;
        $deactivationOverdue = ! $deactivated && $contractEnded && $this->isPast($deactivateOn, $today);

        $steps[] = $this->step('deactivated', match (true) {
            $deactivated => self::STATE_DONE,
            $deactivationOverdue => self::STATE_PROBLEM,
            default => self::STATE_WAITING,
        }, 'system', due: $deactivateOn, late: $deactivationOverdue, blockedBy: $contractEnded ? null : 'contract', note: $deactivationOverdue ? 'deactivation-overdue' : null, data: [
            'deactivate_on' => $deactivateOn,
        ]);

        // 4. Access and card closed: no role left active on the login, card off.
        $account = $employee->account;
        $loginOpen = $account !== null && $account->roles()->wherePivot('is_active', true)->exists();
        $cardOn = $employee->digital_id_enabled !== false;
        $accessClosed = ! $loginOpen && ! $cardOn;

        $steps[] = $this->step('access', match (true) {
            $accessClosed => self::STATE_DONE,
            $deactivated => self::STATE_PROBLEM,
            default => self::STATE_WAITING,
        }, 'system', due: $deactivateOn, late: $deactivated && ! $accessClosed, blockedBy: $deactivated ? null : 'deactivated', note: match (true) {
            $accessClosed || ! $deactivated => null,
            $loginOpen && $cardOn => 'access-both-open',
            $loginOpen => 'access-login-open',
            default => 'access-card-on',
        }, data: [
            'has_account' => $account !== null,
            'login_open' => $loginOpen,
            'card_on' => $cardOn,
        ]);

        // 5. Exit interview (optional): the one step this journey records.
        $steps[] = $this->step('interview', $journey->exit_interview_held_at !== null ? self::STATE_DONE : self::STATE_TODO, 'hr', data: [
            'optional' => true,
            'held_at' => $journey->exit_interview_held_at,
            'held_by' => $journey->exitInterviewBy,
        ]);

        return $steps;
    }

    /**
     * Record completion the first time every required step is done. Sticky:
     * a later re-hire does not reopen a finished departure. Returns true when
     * it was just completed.
     */
    public function settle(OffboardingJourney $journey, ?User $actor = null): bool
    {
        if ($journey->completed_at !== null) {
            return false;
        }

        $employee = Employee::query()->with('latestOffboardingJourney.contract')->find($journey->employee_id);

        // Only the employee's latest departure is followed.
        if (! $employee || $employee->latestOffboardingJourney?->id !== $journey->id || $this->isCalledOff($journey)) {
            return false;
        }

        $steps = collect($this->steps($employee) ?? [])->keyBy('key');
        $complete = collect(self::REQUIRED_STEPS)->every(fn (string $key) => ($steps[$key]['state'] ?? null) === self::STATE_DONE);

        if (! $complete) {
            return false;
        }

        $done = OffboardingJourney::query()
            ->whereKey($journey->id)
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'updated_at' => now()]);

        if ($done !== 1) {
            return false;
        }

        $journey->refresh();
        $this->record($journey, 'offboarding_completed', $actor, []);

        return true;
    }

    // ── internals ────────────────────────────────────────────────────

    /** @param  Collection<int, Contract>  $contracts */
    private function continues(Contract $contract, Collection $contracts, CarbonInterface $today): bool
    {
        return $this->isCurrentOrFuture($contract, $today) || $this->awaitsAutoRenewal($contract, $contracts, $today);
    }

    private function isPast(?CarbonInterface $date, CarbonInterface $today): bool
    {
        return $date !== null && $date->toDateString() < $today->toDateString();
    }

    private function journeyFor(int $employeeId, int $contractId): ?OffboardingJourney
    {
        return OffboardingJourney::query()
            ->where('employee_id', $employeeId)
            ->where('contract_id', $contractId)
            ->first();
    }

    /**
     * @param  callable(OffboardingJourney): ?array<string, mixed>  $apply  returns the log properties, or null for "nothing to change"
     */
    private function change(Employee $employee, User $actor, string $event, callable $apply): bool
    {
        return DB::transaction(function () use ($employee, $actor, $event, $apply): bool {
            $journey = OffboardingJourney::query()
                ->where('employee_id', $employee->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $journey || $this->isCalledOff($journey)) {
                return false;
            }

            $properties = $apply($journey);

            if ($properties === null) {
                return false;
            }

            $journey->save();
            $this->record($journey, $event, $actor, $properties);
            $this->settle($journey, $actor);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(OffboardingJourney $journey, string $event, ?User $actor, array $properties): void
    {
        $logger = Activity::useLog('offboarding')
            ->performedOn($journey)
            ->withProperties(['employee_id' => $journey->employee_id] + $properties);

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
