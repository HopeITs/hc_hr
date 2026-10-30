<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\OnboardingJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

/**
 * The onboarding journey of a new volunteer (D4-I, DEC-09; docs/ux/journeys.md):
 *
 *     record → contract → login account → ID card → orientation session
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

    public const STEPS = ['record', 'contract', 'account', 'card', 'orientation'];

    /** A step is in exactly one of these. */
    public const STATE_DONE = 'done';

    public const STATE_TODO = 'todo';          // waits on HR now

    public const STATE_WAITING = 'waiting';    // blocked by an earlier step

    public const STATE_PROBLEM = 'problem';    // cannot finish without a fix elsewhere

    public function __construct(private DigitalIdManager $digitalIds) {}

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

            if ($step === 'card' && $journey->card_handed_over_at !== null) {
                $journey->forceFill(['card_handed_over_at' => null, 'card_handed_over_by' => null]);

                return ['step' => $step];
            }

            return null;
        });
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

        $steps = [];

        $steps[] = $this->step('record', $isDraft ? self::STATE_TODO : self::STATE_DONE, 'hr');

        $steps[] = $this->step('contract', match (true) {
            $hasContract => self::STATE_DONE,
            $isDraft => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, 'hr', blockedBy: $isDraft ? 'record' : null, data: ['contract_id' => $contract?->id]);

        $steps[] = $this->step('account', match (true) {
            $hasAccount => self::STATE_DONE,
            $hasContract => self::STATE_PROBLEM,
            default => self::STATE_WAITING,
        }, 'system', blockedBy: $hasContract ? null : 'contract', note: ! $hasAccount && $hasContract ? 'account-missing' : null);

        $cardReady = $hasContract && $this->digitalCardReady($employee);
        $steps[] = $this->step('card', match (true) {
            $journey?->card_handed_over_at !== null => self::STATE_DONE,
            ! $hasContract || ! $journey => self::STATE_WAITING,
            ! $cardReady => self::STATE_PROBLEM,
            default => self::STATE_TODO,
        }, 'hr', blockedBy: $hasContract ? null : 'contract', note: $hasContract && ! $cardReady ? ($this->digitalIds->missingLinkReason($employee) ?? 'employees.journey.card-not-valid') : null, data: [
            'digital_ready' => $cardReady,
            'handed_over_at' => $journey?->card_handed_over_at,
            'handed_over_by' => $journey?->cardHandedOverBy,
        ]);

        $steps[] = $this->step('orientation', match (true) {
            $journey?->orientation_completed_at !== null => self::STATE_DONE,
            ! $journey => self::STATE_WAITING,
            default => self::STATE_TODO,
        }, 'hr', due: $journey?->orientation_due_on, late: (bool) $journey?->orientationIsLate(), blockedBy: $journey ? null : 'contract', data: [
            'scheduled_for' => $journey?->orientation_scheduled_for,
            'completed_at' => $journey?->orientation_completed_at,
            'completed_by' => $journey?->orientationCompletedBy,
            'can_complete' => $journey?->orientation_scheduled_for !== null
                && $journey->orientation_scheduled_for->lte(now(config('app.timezone'))->startOfDay()),
        ]);

        return $steps;
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
    private function change(Employee $employee, User $actor, string $event, callable $apply): bool
    {
        return DB::transaction(function () use ($employee, $actor, $event, $apply): bool {
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
            $this->settleCompletion($journey, $employee);
            $this->record($journey, $event, $actor, $properties);

            return true;
        });
    }

    /** completed_at follows the steps, so an undo reopens the journey too. */
    private function settleCompletion(OnboardingJourney $journey, Employee $employee): void
    {
        $complete = $journey->orientation_completed_at !== null
            && $journey->card_handed_over_at !== null
            && $employee->account()->exists();

        if ($complete === ($journey->completed_at !== null)) {
            return;
        }

        $journey->forceFill(['completed_at' => $complete ? now() : null])->save();
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
