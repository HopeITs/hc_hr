<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\ProbationReview;
use App\Models\User;
use App\Notifications\ProbationFinalThird;
use App\Notifications\ProbationRequested;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\Activity;
use Throwable;

/**
 * Probation review of a trial contract (meeting 19/11, track 4).
 *
 * From (start + two thirds of the term) the direct manager is asked what should
 * happen: extend, confirm or cancel. The answer is a REQUEST to HR with a
 * mandatory reason; nothing is written to the contract (no end date, no new
 * contract, no termination), because what HR does with each request is not
 * agreed yet. A trial never rolls over by itself: Contract::canAutoRenew stays
 * false, and RenewalJourneys / SendContractExpirationWarnings still skip it.
 */
class ProbationReviews
{
    public const REMINDER_KIND = 'probation-reminder';

    public function __construct(private NotificationDeliveryLedger $ledger) {}

    /** The first day of the last third: start + two thirds of the term. */
    public function lastThirdStart(Contract $contract): ?CarbonInterface
    {
        if ($contract->start_date === null || $contract->end_date === null) {
            return null;
        }

        $start = $contract->start_date->copy()->startOfDay();
        $days = $start->diffInDays($contract->end_date->copy()->startOfDay());

        return $start->addDays((int) floor($days * (float) config('probation.last_third_after')));
    }

    /**
     * Start a review for every running trial that entered its last third.
     * Idempotent (unique contract_id); a missed day is caught up on the next
     * run. Returns how many reviews were started.
     */
    public function startDue(?CarbonInterface $today = null): int
    {
        $today ??= $this->ledger->today();
        $started = 0;

        $this->trialsInForce($today)
            ->orderBy('contracts.id')
            ->each(function (Contract $contract) use ($today, &$started): void {
                $from = $this->lastThirdStart($contract);

                if ($from === null || $from->gt($today)) {
                    return;
                }

                if ($this->startFor($contract)) {
                    $started++;
                }
            });

        return $started;
    }

    /** One review per contract: a retry or a second worker is a no-op. */
    public function startFor(Contract $contract): ?ProbationReview
    {
        $now = now();
        $inserted = ProbationReview::query()->insertOrIgnore([
            'contract_id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        $review = ProbationReview::query()->where('contract_id', $contract->id)->firstOrFail();
        $this->record($review, 'probation_started', null, ['end_date' => $contract->end_date?->toDateString()]);

        $this->send($this->employeeUsers($contract->employee), new ProbationFinalThird($review, 'employee'));
        $this->send($this->managerUsers($contract), new ProbationFinalThird($review, 'manager'));
        $this->send($this->hrUsers($contract->employee), new ProbationFinalThird($review, 'hr'));

        return $review;
    }

    /**
     * Remind the direct manager every N days until they answer. The ledger
     * claim is keyed to the review and to the day the Nth week falls on, so a
     * second run or worker the same day sends nothing. Returns reminders sent.
     */
    public function remindPending(?CarbonInterface $today = null): int
    {
        $today ??= $this->ledger->today();
        $every = max(1, (int) config('probation.reminder_every_days'));
        $sent = 0;

        ProbationReview::query()
            ->whereNull('decision')
            ->with(['employee.city', 'contract.position.directManager.activeContracts.employee.account'])
            ->orderBy('id')
            ->each(function (ProbationReview $review) use ($today, $every, &$sent): void {
                $contract = $review->contract;

                if (! $this->inForce($contract, $today)) {
                    return;
                }

                $since = $review->started_at->copy()->timezone(config('app.timezone'))->startOfDay();
                $weeks = intdiv((int) $since->diffInDays($today), $every);

                if ($weeks < 1) {
                    return;
                }

                $day = $since->copy()->addDays($weeks * $every);

                foreach ($this->managerUsers($contract) as $user) {
                    if (! $this->ledger->claim(self::REMINDER_KIND, $user->id, [$review->id], $day)) {
                        continue;
                    }

                    try {
                        $user->notify(new ProbationFinalThird($review, 'manager', reminder: true));
                        $this->ledger->markDelivered(self::REMINDER_KIND, $user->id, [$review->id], $day);
                        $sent++;
                    } catch (Throwable $e) {
                        $this->ledger->release(self::REMINDER_KIND, $user->id, [$review->id], $day);
                        report($e);
                    }
                }
            });

        return $sent;
    }

    /**
     * Record the manager's request. The reason is mandatory. One answer per
     * review: a second one is refused (returns false). Nothing else changes.
     */
    public function request(ProbationReview $review, string $decision, string $reason, User $actor): bool
    {
        $reason = trim($reason);

        if (! in_array($decision, ProbationReview::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => __('probation.errors.decision')]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('probation.errors.reason')]);
        }

        $done = DB::transaction(function () use ($review, $decision, $reason, $actor): bool {
            $locked = ProbationReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($locked->isDecided() || ! $this->canDecide($actor, $locked)) {
                return false;
            }

            $locked->forceFill([
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $this->record($locked, 'probation_requested', $actor, ['decision' => $decision, 'reason' => $reason]);

            return true;
        });

        if (! $done) {
            return false;
        }

        $review->refresh()->loadMissing('contract.employee.city');

        // After the commit: a rolled-back request must not tell HR anything.
        $this->send($this->hrUsers($review->employee, except: [$actor->id]), new ProbationRequested($review));

        return true;
    }

    /** HR opened a request: note it once. */
    public function markSeenByHr(ProbationReview $review, User $user): void
    {
        if ($review->decision === null || $review->hr_seen_at !== null || ! $this->isHr($user)) {
            return;
        }

        ProbationReview::query()->whereKey($review->id)->whereNull('hr_seen_at')->update(['hr_seen_at' => now()]);
        $review->refresh();
    }

    /** The direct manager of the trial's position, or HR of its city when it has none. */
    public function canDecide(User $user, ProbationReview $review): bool
    {
        $contract = $review->contract()->with('position.directManager.activeContracts.employee')->first();

        if ($contract === null || ! $this->inForce($contract, $this->ledger->today())) {
            return false;
        }

        if ($this->isDirectManager($user, $contract)) {
            return true;
        }

        // HR stands in only when nobody is running the manager's position.
        return $this->managerEmployees($contract)->isEmpty()
            && $this->isHr($user)
            && Gate::forUser($user)->allows('update', $review->employee);
    }

    /** Who may open the review: the manager, and HR of the employee's city. */
    public function canView(User $user, ProbationReview $review): bool
    {
        $contract = $review->contract()->with('position.directManager.activeContracts.employee')->first();

        if ($contract !== null && $this->isDirectManager($user, $contract)) {
            return true;
        }

        return $this->isHr($user) && Gate::forUser($user)->allows('update', $review->employee);
    }

    public function isDirectManager(User $user, Contract $contract): bool
    {
        return $user->employee_id !== null
            && $this->managerEmployees($contract)->contains(fn (Employee $employee) => $employee->id === (int) $user->employee_id);
    }

    /** @return Collection<int, Employee> */
    public function managerEmployees(Contract $contract): Collection
    {
        return collect([$contract->position?->directManager])
            ->filter()
            ->flatMap(fn ($position) => $position->activeContracts)
            ->map(fn (Contract $running) => $running->employee)
            ->filter(fn (?Employee $employee) => $employee?->is_active)
            ->values();
    }

    /** @return Collection<int, User> */
    public function managerUsers(Contract $contract): Collection
    {
        $contract->loadMissing('position.directManager.activeContracts.employee.account');

        return $this->managerEmployees($contract)
            ->map(fn (Employee $employee) => $employee->account)
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function employeeUsers(?Employee $employee): Collection
    {
        return collect([$employee?->account])->filter()->values();
    }

    /**
     * HR of the employee's city; HR-SYR when the city has none (as the other
     * reminders).
     *
     * @param  array<int, int|null>  $except
     * @return Collection<int, User>
     */
    public function hrUsers(?Employee $employee, array $except = []): Collection
    {
        if ($employee === null) {
            return collect();
        }

        $slug = $employee->city?->slug;
        $users = $slug ? $this->usersWithRoles(City::hrRolesForSlug($slug)) : collect();

        if ($users->isEmpty()) {
            $users = $this->usersWithRoles(['HR-SYR']);
        }

        return $users->reject(fn (User $user) => in_array($user->id, $except, true))->values();
    }

    /** Decided, still running, waiting for nobody: the rows the manager's inbox lists. */
    public function pendingFor(User $user): Builder
    {
        return ProbationReview::query()
            ->whereNull('decision')
            ->whereHas('contract', fn (Builder $contract) => $contract
                ->where('is_active', true)
                ->whereNull('terminated_at')
                ->whereNull('termination_reason_id')
                ->whereDate('end_date', '>=', $this->ledger->today()->toDateString()))
            ->whereHas('contract.position.directManager.contracts', fn (Builder $running) => $running
                ->activeNow()
                ->where('employee_id', (int) $user->employee_id));
    }

    /** Running, real, not terminated, not ended: the contract still has a last third to review. */
    private function inForce(?Contract $contract, CarbonInterface $today): bool
    {
        return $contract !== null
            && $contract->is_active
            && ! $contract->isTerminated()
            && $contract->isTrial()
            && $contract->end_date !== null
            && $contract->end_date->copy()->startOfDay()->gte($today);
    }

    /** @return Builder<Contract> */
    private function trialsInForce(CarbonInterface $today): Builder
    {
        $trialTypeIds = collect(config('contracts.map_by_id', []))
            ->filter(fn (string $category): bool => $category === 'trial')
            ->keys()
            ->all();

        return Contract::query()
            ->with(['employee.city', 'employee.account', 'position.directManager.activeContracts.employee.account'])
            ->where('contracts.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('contracts.type')->orWhere('contracts.type', '!=', ContractTypeEnum::Draft))
            ->whereNull('contracts.terminated_at')
            ->whereNull('contracts.termination_reason_id')
            ->whereNotNull('contracts.start_date')
            ->whereNotNull('contracts.end_date')
            ->whereDate('contracts.start_date', '<=', $today->toDateString())
            ->whereDate('contracts.end_date', '>=', $today->toDateString())
            ->whereIn('contracts.contract_type_id', $trialTypeIds === [] ? [0] : $trialTypeIds)
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('probation_reviews')
                ->whereColumn('probation_reviews.contract_id', 'contracts.id'));
    }

    private function isHr(User $user): bool
    {
        return str_starts_with((string) $user->myRole(), 'HR-');
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();
    }

    /** A failed notification never undoes the review that triggered it. */
    private function send(Collection $users, object $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify(clone $notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function record(ProbationReview $review, string $event, ?User $actor, array $properties): void
    {
        $logger = Activity::useLog('probation')
            ->performedOn($review)
            ->withProperties(['contract_id' => $review->contract_id, 'employee_id' => $review->employee_id] + $properties);

        if ($actor) {
            $logger->causedBy($actor);
        }

        $logger->log($event);
    }
}
