<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\ChecklistItem;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\OnboardingJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Facades\Activity;

/**
 * The onboarding journey of a new volunteer (D4-I, DEC-09; docs/ux/journeys.md):
 *
 *     record → contract → then, side by side and none waiting on another:
 *       login account · electronics · ID card · HR's session · the direct
 *       manager's session · policies read and agreed
 *
 * Each step has one authoritative record, and this class never copies one
 * into another:
 *
 *  - record    employees.record_status         (B5, EmployeeEdit)
 *  - contract  the first real contract          (ContractCreate)
 *  - account   users.employee_id                (EmployeeActivation)
 *  - card      digital: DigitalIdManager; printed + handed over: this journey
 *  - orientation                                 this journey
 *
 * The only writes here are the journey's own two steps. Each one is
 * serialized on the journey row and conditional on the state it moves from, so
 * a retried or double-clicked action changes nothing the second time and
 * reports that it did not. Every change is in the "onboarding" activity log
 * with the person who made it. Authorization is the caller's job
 * (EmployeePolicy::update, i.e. HR for the volunteer's city).
 */
class OnboardingJourneys
{
    /** DEC-09 slice, decided 2026-10-30. */
    public const ORIENTATION_DAYS = 14;

    /** Track 7: the login account, the electronics and the ID card are due 2 days after the contract starts. */
    public const ACCOUNT_DAYS = 2;

    public const ELECTRONICS_DAYS = 2;

    public const CARD_DAYS = 2;

    public const STEPS = ['record', 'contract', 'account', 'electronics', 'card', 'orientation', 'manager_orientation', 'policies'];

    /** A step is in exactly one of these. */
    public const STATE_DONE = 'done';

    public const STATE_TODO = 'todo';          // waits on HR now

    public const STATE_WAITING = 'waiting';    // blocked by an earlier step

    public const STATE_PROBLEM = 'problem';    // cannot finish without a fix elsewhere

    public function __construct(
        private DigitalIdManager $digitalIds,
        private OnboardingDeliveries $deliveries,
        private PolicyAcknowledgements $policies,
    ) {}

    /**
     * The ledger day of a deadline reminder: the due date plus whole weeks
     * late, so an overdue step reminds once a week until it is done.
     */
    public static function reminderDay(?CarbonInterface $due, CarbonInterface $today): CarbonInterface
    {
        $due = ($due ?? $today)->copy()->startOfDay();
        $weeksLate = max(0, intdiv((int) $due->diffInDays($today->copy()->startOfDay(), false), 7));

        return $due->addDays($weeksLate * 7);
    }

    /**
     * Start the journey for a volunteer whose first real contract just
     * activated them. Call inside the activation's transaction, with the
     * employee row locked. Returns null when this is not a first contract.
     */
    public function startForContract(Employee $employee, Contract $contract): ?OnboardingJourney
    {
        if ((int) $contract->type === ContractTypeEnum::Draft) {
            return null;
        }

        if ($employee->realContracts()->whereKeyNot($contract->getKey())->exists()) {
            return null;
        }

        $existing = OnboardingJourney::query()->where('employee_id', $employee->id)->first();

        if ($existing) {
            return $existing;
        }

        $start = $contract->start_date ?? now(config('app.timezone'));

        $journey = new OnboardingJourney;
        $journey->forceFill([
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'started_at' => now(),
            'orientation_due_on' => $start->copy()->addDays(self::ORIENTATION_DAYS)->toDateString(),
        ])->save();

        $this->record($journey, 'onboarding_started', null, ['contract_id' => $contract->id]);

        return $journey;
    }

    public function scheduleOrientation(Employee $employee, CarbonInterface $date, User $actor): bool
    {
        return $this->change($employee, $actor, 'orientation_scheduled', function (OnboardingJourney $journey) use ($date, $actor): ?array {
            if ($journey->orientation_completed_at !== null
                || $journey->orientation_scheduled_for?->isSameDay($date)) {
                return null;
            }

            $from = $journey->orientation_scheduled_for?->toDateString();
            $journey->forceFill([
                'orientation_scheduled_for' => $date->toDateString(),
                'orientation_scheduled_by' => $actor->id,
            ]);

            return ['from' => $from, 'to' => $date->toDateString()];
        });
    }

    /**
     * Only after the session's date: a session cannot be recorded as held
     * before it happens.
     */
    public function completeOrientation(Employee $employee, User $actor): bool
    {
        return $this->change($employee, $actor, 'orientation_completed', function (OnboardingJourney $journey) use ($actor): ?array {
            if ($journey->orientation_completed_at !== null
                || $journey->orientation_scheduled_for === null
                || $journey->orientation_scheduled_for->gt(now(config('app.timezone'))->startOfDay())) {
                return null;
            }

            $journey->forceFill([
                'orientation_completed_at' => now(),
                'orientation_completed_by' => $actor->id,
            ]);

            return ['held_on' => $journey->orientation_scheduled_for->toDateString()];
        });
    }

    public function scheduleManagerOrientation(Employee $employee, CarbonInterface $date, User $actor): bool
    {
        if (! $this->canRunManagerOrientation($actor, $employee)) {
            return false;
        }

        return $this->change($employee, $actor, 'manager_orientation_scheduled', function (OnboardingJourney $journey) use ($date, $actor): ?array {
            if ($journey->manager_orientation_completed_at !== null
                || $journey->manager_orientation_scheduled_for?->isSameDay($date)) {
                return null;
            }

            $from = $journey->manager_orientation_scheduled_for?->toDateString();
            $journey->forceFill([
                'manager_orientation_scheduled_for' => $date->toDateString(),
                'manager_orientation_scheduled_by' => $actor->id,
            ]);

            return ['from' => $from, 'to' => $date->toDateString()];
        });
    }

    /** Only after the session's date, like HR's session. */
    public function completeManagerOrientation(Employee $employee, User $actor): bool
    {
        if (! $this->canRunManagerOrientation($actor, $employee)) {
            return false;
        }

        return $this->change($employee, $actor, 'manager_orientation_completed', function (OnboardingJourney $journey) use ($actor): ?array {
            if ($journey->manager_orientation_completed_at !== null
                || $journey->manager_orientation_scheduled_for === null
                || $journey->manager_orientation_scheduled_for->gt(now(config('app.timezone'))->startOfDay())) {
                return null;
            }

            $journey->forceFill([
                'manager_orientation_completed_at' => now(),
                'manager_orientation_completed_by' => $actor->id,
            ]);

            return ['held_on' => $journey->manager_orientation_scheduled_for->toDateString()];
        });
    }

    /**
     * Who runs the manager's session: the volunteer's direct manager (the
     * people running the position their position reports to), and HR of the
     * volunteer's city as the fallback (EmployeePolicy::update), which also
     * covers a volunteer nobody manages (the head of the organization).
     */
    public function canRunManagerOrientation(User $user, Employee $employee): bool
    {
        return $this->isDirectManager($user, $employee)
            || Gate::forUser($user)->allows('update', $employee);
    }

    public function isDirectManager(User $user, Employee $employee): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        return $this->managerEmployees($employee)->contains(fn (Employee $manager) => $manager->id === (int) $user->employee_id);
    }

    /** @return \Illuminate\Support\Collection<int, Employee> */
    public function managerEmployees(Employee $employee): \Illuminate\Support\Collection
    {
        $contract = $employee->onboardingJourney?->contract
            ?? $employee->realContracts()->oldest('start_date')->oldest('id')->first();

        return $contract ? app(ProbationReviews::class)->managerEmployees($contract) : collect();
    }

    /**
     * Recompute the journey's completion after something outside it changed
     * (a delivery, a policy acknowledgement). The writes of those records stay
     * with their own services; this only moves completed_at.
     */
    public function settle(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $journey = OnboardingJourney::query()->where('employee_id', $employee->id)->lockForUpdate()->first();

            if ($journey && $this->settleCompletion($journey, $employee)) {
                $this->record($journey, $journey->completed_at ? 'onboarding_completed' : 'onboarding_reopened', null, []);
            }
        });
    }

    /** Only once the digital card works: the printed card carries its link. */
    public function markCardHandedOver(Employee $employee, User $actor): bool
    {
        if (! $this->digitalCardReady($employee)) {
            return false;
        }

        return $this->change($employee, $actor, 'card_handed_over', function (OnboardingJourney $journey) use ($actor): ?array {
            if ($journey->card_handed_over_at !== null) {
                return null;
            }

            $journey->forceFill([
                'card_handed_over_at' => now(),
                'card_handed_over_by' => $actor->id,
            ]);

            return [];
        });
    }

    /**
     * Undo a step marked by mistake. The log keeps both the mark and the undo.
     */
    public function reopen(Employee $employee, string $step, User $actor): bool
    {
        return $this->change($employee, $actor, 'step_reopened', function (OnboardingJourney $journey) use ($step): ?array {
            if ($step === 'orientation' && $journey->orientation_completed_at !== null) {
                $journey->forceFill(['orientation_completed_at' => null, 'orientation_completed_by' => null]);

                return ['step' => $step];
            }

            if ($step === 'manager_orientation' && $journey->manager_orientation_completed_at !== null) {
                $journey->forceFill(['manager_orientation_completed_at' => null, 'manager_orientation_completed_by' => null]);

                return ['step' => $step];
            }

            if ($step === 'card' && $journey->card_handed_over_at !== null) {
                $journey->forceFill(['card_handed_over_at' => null, 'card_handed_over_by' => null]);

                return ['step' => $step];
            }

            return null;
        }, reopening: true);
    }

    /**
     * Where the volunteer stands, step by step: state, who acts, due date and
     * what blocks it. Null for staff who never had a journey (existing staff,
     * former staff re-hired), so nobody already working sees one.
     *
     * @return list<array{key: string, state: string, owner: string, due: ?CarbonInterface, late: bool, blocked_by: ?string, note: ?string, data: array<string, mixed>}>|null
     */
    public function steps(Employee $employee): ?array
    {
        $journey = $employee->onboardingJourney;

        if (! $journey && ! $employee->isDraft() && ! $employee->isAwaitingContract()) {
            return null;
        }

        $isDraft = $employee->isDraft();
        $contract = $journey?->contract ?? $employee->realContracts()->oldest('start_date')->oldest('id')->first();
        $hasContract = $contract !== null;
        $hasAccount = $employee->account()->exists();
        $today = now(config('app.timezone'))->startOfDay();

        // After the contract no step waits on another one: all of them wait on the contract only.
        $afterContract = $hasContract && $journey ? null : 'contract';
        $lateAfter = fn (?CarbonInterface $due, bool $done): bool => ! $done && $due !== null && $due->lt($today);

        $steps = [];

        $steps[] = $this->step('record', $isDraft ? self::STATE_TODO : self::STATE_DONE, 'hr');

        $steps[] = $this->step('contract', match (true) {
            $hasContract => self::STATE_DONE,
            $isDraft => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, 'hr', blockedBy: $isDraft ? 'record' : null, data: ['contract_id' => $contract?->id]);

        $accountDue = $journey?->dueAfterStart(self::ACCOUNT_DAYS);
        $steps[] = $this->step('account', match (true) {
            $hasAccount => self::STATE_DONE,
            $hasContract => self::STATE_PROBLEM,
            default => self::STATE_WAITING,
        }, 'system', due: $accountDue, late: $lateAfter($accountDue, $hasAccount), blockedBy: $hasContract ? null : 'contract', note: ! $hasAccount && $hasContract ? 'account-missing' : null);

        $electronics = $this->deliveries->group($employee, ChecklistItem::GROUP_ELECTRONICS);
        $electronicsDue = $journey?->dueAfterStart(self::ELECTRONICS_DAYS);
        $steps[] = $this->step('electronics', match (true) {
            ! $journey => self::STATE_WAITING,
            $electronics['complete'] => self::STATE_DONE,
            ! $electronics['configured'] => self::STATE_PROBLEM,
            default => self::STATE_TODO,
        }, 'deliverer', due: $electronicsDue, late: $lateAfter($electronicsDue, $electronics['complete']), blockedBy: $afterContract,
            note: $journey && ! $electronics['configured'] ? 'electronics-no-deliverer' : null,
            data: ['items' => $electronics['items'], 'missing' => $electronics['missing']]);

        $card = $this->deliveries->group($employee, ChecklistItem::GROUP_CARD);
        $cardItemsDone = ! $card['configured'] || $card['complete'];
        $handedOver = $journey?->card_handed_over_at !== null;
        $cardDone = $handedOver && $cardItemsDone;
        $cardReady = $hasContract && $this->digitalCardReady($employee);
        $cardDue = $journey?->dueAfterStart(self::CARD_DAYS);
        $steps[] = $this->step('card', match (true) {
            $cardDone => self::STATE_DONE,
            ! $hasContract || ! $journey => self::STATE_WAITING,
            $handedOver => self::STATE_TODO,
            ! $cardReady => self::STATE_PROBLEM,
            default => self::STATE_TODO,
        }, 'hr', due: $cardDue, late: $lateAfter($cardDue, $cardDone), blockedBy: $hasContract ? null : 'contract',
            note: match (true) {
                $handedOver && ! $cardItemsDone => 'card-items-missing',
                $hasContract && ! $cardReady && ! $handedOver => $this->digitalIds->missingLinkReason($employee) ?? 'employees.journey.card-not-valid',
                default => null,
            }, data: [
                'digital_ready' => $cardReady,
                'handed_over_at' => $journey?->card_handed_over_at,
                'handed_over_by' => $journey?->cardHandedOverBy,
                'missing' => $card['missing'],
            ]);

        $steps[] = $this->step('orientation', match (true) {
            $journey?->orientation_completed_at !== null => self::STATE_DONE,
            ! $journey => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, 'hr', due: $journey?->orientation_due_on, late: (bool) $journey?->orientationIsLate(), blockedBy: $afterContract, data: [
            'scheduled_for' => $journey?->orientation_scheduled_for,
            'completed_at' => $journey?->orientation_completed_at,
            'completed_by' => $journey?->orientationCompletedBy,
            'can_complete' => $journey?->orientation_scheduled_for !== null
                && $journey->orientation_scheduled_for->lte($today),
        ]);

        $steps[] = $this->step('manager_orientation', match (true) {
            $journey?->manager_orientation_completed_at !== null => self::STATE_DONE,
            ! $journey => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, 'manager', due: $journey?->orientation_due_on, late: (bool) $journey?->managerOrientationIsLate(), blockedBy: $afterContract, data: [
            'scheduled_for' => $journey?->manager_orientation_scheduled_for,
            'completed_at' => $journey?->manager_orientation_completed_at,
            'completed_by' => $journey?->managerOrientationCompletedBy,
            'managers' => $journey ? $this->managerEmployees($employee) : collect(),
            'can_complete' => $journey?->manager_orientation_scheduled_for !== null
                && $journey->manager_orientation_scheduled_for->lte($today),
        ]);

        $policies = $this->policies->status($employee);
        $steps[] = $this->step('policies', match (true) {
            ! $journey => self::STATE_WAITING,
            $policies['complete'] => self::STATE_DONE,
            $policies['required']->isEmpty() => self::STATE_PROBLEM,
            default => self::STATE_TODO,
        }, 'volunteer', blockedBy: $afterContract, note: $journey && $policies['required']->isEmpty() ? 'policies-none-required' : null, data: [
            'required' => $policies['required']->count(),
            'pending' => $policies['pending'],
        ]);

        $others = $this->deliveries->group($employee, ChecklistItem::GROUP_OTHER);
        if ($others['configured']) {
            $steps[] = $this->step('other_items', match (true) {
                ! $journey => self::STATE_WAITING,
                $others['complete'] => self::STATE_DONE,
                default => self::STATE_TODO,
            }, 'deliverer', blockedBy: $afterContract, data: ['items' => $others['items'], 'missing' => $others['missing']]);
        }

        return $steps;
    }

    /**
     * The Mug is outside the journey: it only becomes due once a non-trial contract has started.
     *
     * @return array<string, mixed>
     */
    public function mug(Employee $employee): array
    {
        return $this->deliveries->mug($employee);
    }

    public function digitalCardReady(Employee $employee): bool
    {
        return $employee->is_active
            && $employee->digital_id_enabled !== false
            && $this->digitalIds->cardCode($employee) !== null;
    }

    /**
     * @param  callable(OnboardingJourney): ?array<string, mixed>  $apply  returns the log properties, or null for "nothing to change"
     */
    private function change(Employee $employee, User $actor, string $event, callable $apply, bool $reopening = false): bool
    {
        return DB::transaction(function () use ($employee, $actor, $event, $apply, $reopening): bool {
            $journey = OnboardingJourney::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            if (! $journey) {
                return false;
            }

            $properties = $apply($journey);

            if ($properties === null) {
                return false;
            }

            $journey->save();
            $this->settleCompletion($journey, $employee, $reopening);
            $this->record($journey, $event, $actor, $properties);

            return true;
        });
    }

    /**
     * completed_at follows the steps, so an undo reopens the journey too.
     * Complete = account + electronics + card + HR's session + the manager's
     * session + policies (+ the "other" delivery items, when the city has any).
     * The Mug is outside it. A journey that was complete before the newer steps
     * existed stays complete: only an undo, or one of the original three steps
     * going missing, reopens it.
     *
     * @return bool whether completed_at changed
     */
    private function settleCompletion(OnboardingJourney $journey, Employee $employee, bool $reopening = false): bool
    {
        $legacy = $journey->orientation_completed_at !== null
            && $journey->card_handed_over_at !== null
            && $employee->account()->exists();

        $others = $this->deliveries->group($employee, ChecklistItem::GROUP_OTHER);
        $electronics = $this->deliveries->group($employee, ChecklistItem::GROUP_ELECTRONICS);
        $card = $this->deliveries->group($employee, ChecklistItem::GROUP_CARD);

        $complete = $legacy
            && $electronics['complete']
            && (! $card['configured'] || $card['complete'])
            && $journey->manager_orientation_completed_at !== null
            && $this->policies->status($employee)['complete']
            && (! $others['configured'] || $others['complete']);

        $wasComplete = $journey->completed_at !== null;

        if ($complete === $wasComplete) {
            return false;
        }

        if ($wasComplete && ! $complete && $legacy && ! $reopening) {
            return false;
        }

        $journey->forceFill(['completed_at' => $complete ? now() : null])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(OnboardingJourney $journey, string $event, ?User $actor, array $properties): void
    {
        $logger = Activity::useLog('onboarding')
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
