<?php

namespace App\Services;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Gender;
use App\Enums\HiringCampaignStatus;
use App\Enums\MaritalStatus;
use App\Enums\Nationality;
use App\Enums\PayMode;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as OfferStatus;
use App\Enums\SalaryPer;
use App\Enums\ScheduleMode;
use App\Enums\SessionDuration;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\HiringCampaign;
use App\Models\Position;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\RecruitmentJobOfferVersion as Version;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\NewEmployeeWelcome;
use App\Notifications\RecruitmentCandidateHired;
use App\Services\Recruitment\HiringConversionBlocked;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 Task 10: an accepted job offer becomes an employee and a contract
 * in the HR system — the last step of the hiring cycle.
 *
 * Task 9 hands over when the candidate accepts, by writing the pending
 * conversion row (one per offer). convert() finishes it, in one transaction
 * and in this order:
 *
 *   lock the application, the offer and the conversion (the offer service's
 *   order, then ours) → a converted row is returned as it is: a retry after a
 *   timeout, a double click or two people at once cannot hire twice
 *   → lock the vacancy and the position, and re-check everything against
 *   them: the offer is still the accepted one, the vacancy is open with a
 *   seat, the position is active with room (exist_emp < max_emp)
 *   → create the employee, or link the one they already are
 *   → create the contract from the accepted version — the frozen snapshot,
 *   never the live offer — plus its leave balances
 *   → count the seat on the vacancy: it is marked filled, and its open
 *   campaigns closed, only once every needed seat is taken
 *   → application "hired", applicant linked to the employee, conversion
 *   "converted" → audit
 *
 * Anything that fails rolls all of it back — there is never an employee
 * without a contract. A refusal caused by the state of things (vacancy
 * closed, position full…) is then recorded on the conversion as a failed
 * attempt with its reason, and HR can retry once it is fixed; a mistake in
 * the form is simply shown.
 *
 * Existing people are never merged silently, and never linked on a phone
 * or an email alone (the identity rule, matches()). A candidate is linked to
 * an employee only when they signed in as that member of staff, or when the
 * national number, the email or the phone on file comes with the same name.
 * A signed-in member of staff, or a national number on file under the same
 * name, can only be linked; a same-name email or phone match can still be
 * created as someone new once HR confirms they are a different person. An
 * email or phone on file under another name is only a warning, and a
 * national number on file under another name stops the hire.
 *
 * No separate approval: the seat was approved with the recruitment request
 * and the terms with the offer. The permission is its own
 * (recruitment-applicants.convert-hires) on top of what HR needs to create a
 * contract by hand, and the salary is never written to the audit log.
 */
class RecruitmentHiringConversionService
{
    public const AUDIT_LOG = 'recruitment-hiring-conversion';

    public const MODE_CREATE = 'create';

    public const MODE_LINK = 'link';

    /** The reason given when a filled vacancy's open campaigns are closed. */
    public const CAMPAIGN_CLOSE_REASON = 'vacancy_filled';

    /** Why a candidate matches an existing employee, strongest first. */
    public const MATCH_INTERNAL = 'internal';

    public const MATCH_NATIONAL_NUMBER = 'national_number';

    public const MATCH_EMAIL = 'email';

    public const MATCH_PHONE = 'phone';

    /** What HR may do about a match (matches()): link it, only be warned, or stop. */
    public const MATCH_LINKABLE = 'linkable';

    public const MATCH_WARNING = 'warning';

    public const MATCH_ID_CONFLICT = 'id_conflict';

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly RecruitmentApplicationStatusService $statuses,
        private readonly HiringCampaignService $campaigns,
        private readonly HiringCampaignStateMachine $campaignStates,
        private readonly LeaveBalanceService $leaveBalances,
        private readonly OnboardingJourneys $onboarding,
    ) {}

    /**
     * Why this hire cannot go ahead right now, as reason codes; empty when it
     * can. The same checks convert() makes on the locked rows.
     *
     * @return array<int, string>
     */
    public function blockers(Conversion $conversion): array
    {
        $offer = Offer::query()->with(['application', 'vacancy', 'position'])->find($conversion->job_offer_id);

        return $this->blockersFor(
            $offer,
            Version::query()->find($conversion->job_offer_version_id),
            $offer?->application,
            $offer?->vacancy,
            $offer?->position,
        );
    }

    /**
     * Employees this candidate may already be, with why each matched and what
     * HR may do about it (the identity rule):
     *
     *   linkable     the same person: the member of staff the candidate
     *                signed in as, or a national number, email or phone on
     *                file together with the same name (nameMatches());
     *   warning      an email or phone on file under another name: shown to
     *                HR, never linked, and no reason to hold back a new
     *                employee;
     *   id_conflict  a national number on file under another name: the hire
     *                stops until HR has checked it.
     *
     * An employee_id the applicant carries without having signed in proves
     * nothing, so it is no match on its own. A national number is only known
     * once HR types it.
     *
     * @return Collection<int, array{employee: Employee, reasons: array<int, string>, kind: string}>
     */
    public function matches(Conversion $conversion, ?string $nationalNumber = null): Collection
    {
        $applicant = RecruitmentApplicant::query()->find($conversion->applicant_id);
        $payload = $conversion->employee_payload_json ?? [];
        $email = mb_strtolower(trim((string) ($payload['email'] ?? $applicant?->email_normalized)));
        $phone = $this->localPhone($payload['phone'] ?? $applicant?->phone_normalized);
        $nationalNumber = $this->normalizeNationalNumber($nationalNumber);
        $found = [];

        if ($applicant?->employee_id !== null && $applicant->identity_verified_at !== null) {
            $found[(int) $applicant->employee_id][] = self::MATCH_INTERNAL;
        }

        if ($nationalNumber !== null) {
            foreach (Employee::query()->where('national_number', $nationalNumber)->pluck('id') as $id) {
                $found[(int) $id][] = self::MATCH_NATIONAL_NUMBER;
            }
        }

        if ($email !== '') {
            $byEmail = Employee::query()->whereRaw('lower(personal_email) = ?', [$email])->pluck('id')
                ->merge(User::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('employee_id')->pluck('employee_id'));

            foreach ($byEmail as $id) {
                $found[(int) $id][] = self::MATCH_EMAIL;
            }
        }

        if ($phone !== null) {
            $byPhone = Employee::query()
                ->where(fn ($query) => $query->where('personal_phone', $phone)->orWhere('work_phone', $phone))
                ->pluck('id');

            foreach ($byPhone as $id) {
                $found[(int) $id][] = self::MATCH_PHONE;
            }
        }

        $employees = Employee::query()->with('city')->whereKey(array_keys($found))->get()->keyBy('id');
        $names = array_values(array_unique(array_filter([
            trim((string) ($payload['full_name'] ?? '')),
            trim((string) $applicant?->full_name),
        ])));

        return collect($found)
            ->filter(fn (array $reasons, int $id): bool => $employees->has($id))
            ->map(function (array $reasons, int $id) use ($employees, $names): array {
                $employee = $employees->get($id);
                $reasons = array_values(array_unique($reasons));

                return [
                    'employee' => $employee,
                    'reasons' => $reasons,
                    'kind' => match (true) {
                        in_array(self::MATCH_INTERNAL, $reasons, true), $this->nameMatches($employee, $names) => self::MATCH_LINKABLE,
                        in_array(self::MATCH_NATIONAL_NUMBER, $reasons, true) => self::MATCH_ID_CONFLICT,
                        default => self::MATCH_WARNING,
                    },
                ];
            })
            ->values();
    }

    /**
     * The new-employee form, filled from what the application already says:
     * the name (in the script it was written in), the email and the phone.
     *
     * @return array<string, mixed>
     */
    public function prefill(Conversion $conversion): array
    {
        $payload = $conversion->employee_payload_json ?? [];
        $name = trim((string) ($payload['full_name'] ?? ''));
        [$first, $last] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, '');
        $arabic = preg_match('/\p{Arabic}/u', $name) === 1;

        return [
            'firstNameEn' => $arabic ? '' : $first,
            'lastNameEn' => $arabic ? '' : $last,
            'firstNameAr' => $arabic ? $first : '',
            'lastNameAr' => $arabic ? $last : '',
            'fatherNameEn' => '',
            'fatherNameAr' => '',
            'motherNameEn' => '',
            'motherNameAr' => '',
            'nationality' => (string) Nationality::Syrian,
            'nationalNumber' => '',
            'gender' => '',
            'maritalStatus' => '',
            'dob' => '',
            'personalPhone' => $this->localPhone($payload['phone'] ?? null) ?? '',
            'personalEmail' => (string) ($payload['email'] ?? ''),
            'username' => $arabic ? '' : $this->suggestUsername($first, $last),
        ];
    }

    /** The city the employee belongs to: the position's center's, else its own (as Position::inCity()). */
    public function workCityId(Position $position): int
    {
        return (int) ($position->center()->value('city_id') ?? $position->city_id);
    }

    /**
     * @param  array<string, mixed>  $input  mode ('create' | 'link'); employeeId (link);
     *                                       employee (create: the form, see prefill());
     *                                       confirmNotDuplicate (create, when someone matched);
     *                                       sessionDuration (when the pay is per session)
     */
    public function convert(User $actor, Conversion $conversion, array $input): Conversion
    {
        Gate::forUser($actor)->authorize('convert', $conversion);

        $mode = $input['mode'] ?? null;
        if (! in_array($mode, [self::MODE_CREATE, self::MODE_LINK], true)) {
            throw $this->error('mode', 'mode_required');
        }

        $person = (array) ($input['employee'] ?? []);
        $employee = $mode === self::MODE_CREATE ? $this->validateEmployee($person) : null;
        $sessionDuration = filter_var($input['sessionDuration'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        try {
            return DB::transaction(fn (): Conversion => $this->run(
                $actor,
                $conversion,
                $mode,
                $employee,
                $input['employeeId'] ?? null,
                $this->normalizeNationalNumber($person['nationalNumber'] ?? null),
                (bool) ($input['confirmNotDuplicate'] ?? false),
                $sessionDuration,
            ));
        } catch (HiringConversionBlocked $blocked) {
            $this->recordFailure($actor, $conversion, $blocked->reason);

            throw $blocked;
        } catch (ValidationException|AuthorizationException $exception) {
            // A mistake in the form or a choice HR has to make: nothing to record.
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($actor, $conversion, 'unexpected');

            throw $exception;
        }
    }

    /** @param  array<string, mixed>|null  $person  validated new-employee attributes */
    private function run(
        User $actor,
        Conversion $conversion,
        string $mode,
        ?array $person,
        mixed $employeeId,
        ?string $nationalNumber,
        bool $confirmNotDuplicate,
        ?int $sessionDuration,
    ): Conversion {
        $applicationId = Offer::query()->whereKey($conversion->job_offer_id)->value('application_id');
        $application = RecruitmentApplication::query()->whereKey($applicationId)->lockForUpdate()->firstOrFail();
        $offer = Offer::query()->whereKey($conversion->job_offer_id)->lockForUpdate()->firstOrFail();
        $locked = Conversion::query()->whereKey($conversion->getKey())->lockForUpdate()->firstOrFail();

        // Done already — by the request that timed out, the first click, or
        // the colleague who got there first. Nothing is created a second time.
        if ($locked->isConverted()) {
            return $locked;
        }

        $version = Version::query()->whereKey($locked->job_offer_version_id)->lockForUpdate()->first();
        $vacancy = Vacancy::query()->whereKey($offer->vacancy_id)->lockForUpdate()->first();
        $position = Position::query()->whereKey($offer->position_id)->lockForUpdate()->first();

        if ($blockers = $this->blockersFor($offer, $version, $application, $vacancy, $position)) {
            throw HiringConversionBlocked::because($blockers[0]);
        }

        if ((int) $version->salary_per === SalaryPer::Session
            && ! in_array($sessionDuration, [SessionDuration::Hour, SessionDuration::HourAndHalf, SessionDuration::TwoHours], true)) {
            throw $this->error('sessionDuration', 'session_duration_required');
        }

        $applicant = RecruitmentApplicant::query()->whereKey($locked->applicant_id)->lockForUpdate()->firstOrFail();

        // Who the candidate may already be, under the identity rule.
        $matches = $this->matches($locked, $nationalNumber);

        // Their national number is on file under another name: nothing is
        // created until HR has checked it.
        if ($conflict = $matches->firstWhere('kind', self::MATCH_ID_CONFLICT)) {
            throw $this->error($mode === self::MODE_CREATE ? 'employee.nationalNumber' : 'employeeId', 'national_number_mismatch', ['name' => $conflict['employee']->name]);
        }

        [$employee, $reactivated] = $mode === self::MODE_CREATE
            ? [$this->createEmployee($actor, $position, $person, $matches, $confirmNotDuplicate), false]
            : $this->linkEmployee($actor, $position, $employeeId, $matches);

        $contract = new Contract($this->contractAttributes($employee, $offer, $version, $sessionDuration));
        $contract->save();
        $this->leaveBalances->create($contract);

        // D4-I: a hire is a first contract like any other, so it starts the
        // onboarding journey (card, orientation). Someone hired back who
        // already had a real contract gets none, as on the contract page.
        $this->onboarding->startForContract($employee, $contract);

        [$vacancyStatus, $campaignsClosed, $campaignsLeftOpen] = $this->fillSeat($actor, $vacancy);

        $this->statuses->applyConversionTransition($actor, $application, AppStatus::Hired);

        // The candidate is now this employee: the one linked under the
        // identity rule, or the one just created from their application. The
        // link a member of staff made by signing in is never replaced.
        if ($applicant->identity_verified_at === null || $applicant->employee_id === null) {
            $applicant->forceFill(['employee_id' => $employee->getKey()])->save();
        }

        $locked->forceFill([
            'employee_id' => $employee->getKey(),
            'contract_id' => $contract->getKey(),
            'employee_payload_json' => [...($locked->employee_payload_json ?? []), 'mode' => $mode, 'employee_id' => $employee->getKey()],
            'status' => Conversion::STATUS_CONVERTED,
            'failure_reason' => null,
            'attempt_count' => $locked->attempt_count + 1,
            // Until now the HR who recorded the acceptance; from here, who hired.
            'converted_by_user_id' => $actor->getKey(),
            'converted_at' => now(),
        ])->save();

        $this->log($actor, $locked, 'converted', [
            'job_offer_id' => $offer->getKey(),
            'job_offer_version_id' => $version->getKey(),
            'application_id' => $application->getKey(),
            'mode' => $mode,
            'employee_id' => $employee->getKey(),
            'employee_reactivated' => $reactivated,
            // Contact details found on someone else's record, shown to HR and not linked.
            'identity_warnings' => $matches->where('kind', self::MATCH_WARNING)
                ->map(fn (array $match): array => ['employee_id' => $match['employee']->getKey(), 'reasons' => $match['reasons']])
                ->values()->all(),
            'contract_id' => $contract->getKey(),
            'vacancy_id' => $vacancy->getKey(),
            'vacancy_status' => $vacancyStatus,
            'filled_count' => (int) $vacancy->filled_count,
            'needed_count' => (int) $vacancy->needed_count,
            'campaigns_closed' => $campaignsClosed,
            'campaigns_left_open' => $campaignsLeftOpen,
            'attempt_count' => $locked->attempt_count,
        ]);

        // As the contract page does for a first contract; after commit, so a
        // rolled-back hire never welcomes anybody.
        DB::afterCommit(function () use ($employee): void {
            if ($employee->allContracts()->count() === 1) {
                User::sendBroadcast(new NewEmployeeWelcome($employee), $employee->city_id);
            }
        });

        $this->notifyRequester($actor, $vacancy, $offer, $employee);

        return $locked;
    }

    /**
     * The manager who asked for the position learns his hire is done, once
     * it is committed, with a link to the offer. He is the manager HR opened
     * the position for when there is one, otherwise whoever raised the
     * request — and nobody when he made the hire himself. A notice that
     * cannot be written is logged; it never undoes the hire.
     */
    private function notifyRequester(User $actor, Vacancy $vacancy, Offer $offer, Employee $employee): void
    {
        $request = $vacancy->createdFromRequest()->first();
        $requesterId = $request?->actual_requester_user_id ?? $request?->requester_user_id;

        if ($requesterId === null || (int) $requesterId === (int) $actor->getKey()) {
            return;
        }

        DB::afterCommit(fn () => rescue(
            fn () => User::query()->find($requesterId)?->notify(new RecruitmentCandidateHired($offer, $employee)),
        ));
    }

    /**
     * @return array<int, string> reason codes, most fundamental first
     */
    private function blockersFor(?Offer $offer, ?Version $version, ?RecruitmentApplication $application, ?Vacancy $vacancy, ?Position $position): array
    {
        // The snapshot being converted must still be the accepted one.
        if ($offer === null || $version === null
            || $offer->status !== OfferStatus::Accepted
            || $version->status !== Version::STATUS_ACCEPTED
            || (int) $offer->current_version_id !== (int) $version->getKey()
            || $application?->status !== AppStatus::OfferAccepted) {
            return ['offer_not_accepted'];
        }

        $blockers = [];

        if ($vacancy !== null && ($vacancy->status === Vacancy::FILLED || (int) $vacancy->filled_count >= (int) $vacancy->needed_count)) {
            $blockers[] = 'vacancy_full';
        } elseif ($vacancy === null || $vacancy->status !== Vacancy::OPEN || $vacancy->closed_at !== null) {
            $blockers[] = 'vacancy_closed';
        }

        if ($position === null || ! $position->is_active) {
            $blockers[] = 'position_inactive';
        } elseif (! $position->canAssign()) {
            $blockers[] = 'position_full';
        }

        return [...$blockers, ...$this->termBlockers($version)];
    }

    /**
     * What stops the accepted terms from becoming a contract under the
     * contract composer's own rules (ContractForm).
     *
     * @return array<int, string>
     */
    private function termBlockers(Version $version): array
    {
        $type = ContractType::query()->find($version->contract_type_id);

        if ($type === null) {
            return ['contract_type_missing'];
        }

        $blockers = [];

        // Null when this copy of the database cannot decrypt it (SalaryCipher):
        // a contract without its pay clause would be worse than no contract.
        if ($version->salary_amount === null) {
            $blockers[] = 'salary_unreadable';
        }

        if (! $this->typeMatchesCategory($this->employmentType($version, $type), $type)) {
            $blockers[] = 'contract_type_mismatch';
        }

        // ContractForm::validateTerms(): the end on or after the start, and at most a year on.
        [$start, $end] = $this->term($version, $type);
        if ($end->lt($start) || $end->gt($start->copy()->addYear())) {
            $blockers[] = 'contract_term_invalid';
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  Collection<int, array{employee: Employee, reasons: array<int, string>, kind: string}>  $matches
     */
    private function createEmployee(User $actor, Position $position, array $person, Collection $matches, bool $confirmNotDuplicate): Employee
    {
        $cityId = $this->workCityId($position);
        Gate::forUser($actor)->authorize('create', [Employee::class, $cityId]);

        $linkable = $matches->where('kind', self::MATCH_LINKABLE);
        $matchedBy = fn (string $reason): bool => $linkable->contains(fn (array $match): bool => in_array($reason, $match['reasons'], true));

        if ($matchedBy(self::MATCH_INTERNAL)) {
            throw $this->error('mode', 'internal_must_link');
        }

        if ($matchedBy(self::MATCH_NATIONAL_NUMBER)) {
            throw $this->error('employee.nationalNumber', 'national_number_taken');
        }

        // The same name with the same email or phone: HR says it is someone
        // else. A contact detail under another name is only a warning.
        if ($linkable->isNotEmpty() && ! $confirmNotDuplicate) {
            throw $this->error('confirmNotDuplicate', 'possible_duplicate');
        }

        if (Employee::query()->where('username', $person['username'])->exists()) {
            throw $this->error('employee.username', 'username_taken');
        }

        try {
            return Employee::query()->create([...$person, 'city_id' => $cityId, 'is_active' => true]);
        } catch (UniqueConstraintViolationException) {
            // Someone took the national number or username a moment ago.
            throw $this->error('employee.nationalNumber', 'national_number_taken');
        }
    }

    /**
     * @param  Collection<int, array{employee: Employee, reasons: array<int, string>, kind: string}>  $matches
     * @return array{0: Employee, 1: bool} the employee, and whether they were reactivated
     */
    private function linkEmployee(User $actor, Position $position, mixed $employeeId, Collection $matches): array
    {
        $id = filter_var($employeeId, FILTER_VALIDATE_INT);
        $match = $id === false ? null : $matches->first(fn (array $match): bool => $match['employee']->getKey() === $id);

        // Only someone the candidate actually matches: a tampered id links nobody.
        if ($match === null) {
            throw $this->error('employeeId', 'employee_not_matched');
        }

        // A phone or an email on someone else's record does not make them that person.
        if ($match['kind'] !== self::MATCH_LINKABLE) {
            throw $this->error('employeeId', 'name_mismatch');
        }

        // A member of staff who signed in to apply is that employee and no other.
        $internal = $matches->first(fn (array $match): bool => in_array(self::MATCH_INTERNAL, $match['reasons'], true));
        if ($internal !== null && $internal['employee']->getKey() !== $id) {
            throw $this->error('employeeId', 'internal_must_link');
        }

        $employee = Employee::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (Gate::forUser($actor)->denies('update', $employee)) {
            throw $this->error('employeeId', 'employee_out_of_scope');
        }

        // Already on this position with a live contract: that is a renewal,
        // not a hire — a second contract would count one person twice.
        $onPosition = Contract::query()
            ->where('employee_id', $employee->getKey())
            ->where('position_id', $position->getKey())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->exists();

        if ($onPosition) {
            throw $this->error('employeeId', 'already_on_position');
        }

        // Someone who left and is hired back is an active employee again.
        $reactivated = ! $employee->is_active;
        if ($reactivated) {
            $employee->update(['is_active' => true]);
        }

        return [$employee, $reactivated];
    }

    /**
     * The contract, from the accepted version only. What the offer does not
     * carry (the daily schedule) starts where the contract composer starts a
     * new contract; see config('recruitment.conversions').
     *
     * @return array<string, mixed>
     */
    private function contractAttributes(Employee $employee, Offer $offer, Version $version, ?int $sessionDuration): array
    {
        $type = ContractType::query()->findOrFail($version->contract_type_id);
        [$start, $end] = $this->term($version, $type);
        $perSession = (int) $version->salary_per === SalaryPer::Session;
        $weeklyHours = $version->weekly_hours
            ?? ($type->category === 'service' ? 0 : (int) config('recruitment.conversions.weekly_hours', 40));

        return [
            'employee_id' => $employee->getKey(),
            'position_id' => $offer->position_id,
            'contract_type_id' => $type->getKey(),
            'type' => $this->employmentType($version, $type),
            'place' => (int) ($version->terms_json['place'] ?? ContractPlace::Onsite),
            'is_active' => true,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'weekly_hours' => $weeklyHours,

            'schedule_mode' => ScheduleMode::Fixed,
            'work_hours_from' => config('recruitment.conversions.work_hours_from', '09:00'),
            'work_hours_to' => config('recruitment.conversions.work_hours_to', '17:00'),
            'show_days_per_week' => true,
            'show_hours_per_week' => true,
            'show_hours_per_day' => true,
            'show_day_range' => true,
            'flex_varies_by_requirements' => false,
            'flex_coordinated_with_manager' => false,

            'pay_mode' => PayMode::Fixed,
            'currency' => (int) $version->currency,
            'salary' => $version->salary_amount,
            'salary_per' => (int) $version->salary_per,
            'session_duration' => $perSession ? $sessionDuration : null,
        ];
    }

    /**
     * One more seat taken. The vacancy is marked filled — and its campaigns
     * that are still taking applications closed — only when every seat it
     * needs is taken; until then it stays open for the next hire.
     *
     * @return array{0: string, 1: array<int, int>, 2: array<int, int>} the vacancy status, the campaigns closed, the campaigns HR still has to look at
     */
    private function fillSeat(User $actor, Vacancy $vacancy): array
    {
        $filled = (int) $vacancy->filled_count + 1;
        $isFull = $filled >= (int) $vacancy->needed_count;

        $vacancy->forceFill(['filled_count' => $filled] + ($isFull ? ['status' => Vacancy::FILLED, 'closed_at' => now()] : []))->save();

        if (! $isFull) {
            return [Vacancy::OPEN, [], []];
        }

        $closed = [];
        $leftOpen = [];

        $campaigns = HiringCampaign::query()
            ->where('vacancy_id', $vacancy->getKey())
            ->whereIn('status', [HiringCampaignStatus::Open, HiringCampaignStatus::Published])
            ->orderBy('id')
            ->get();

        foreach ($campaigns as $campaign) {
            $effective = $this->campaignStates->effective($campaign);

            // Taking applications for a seat that no longer exists: closed
            // (C4), through the campaign service, which records it.
            if ($effective === HiringCampaignStatus::Open && Gate::forUser($actor)->allows('close', $campaign)) {
                $this->campaigns->close($actor, $campaign, self::CAMPAIGN_CLOSE_REASON);
                $closed[] = (int) $campaign->getKey();
            } elseif (in_array($effective, [HiringCampaignStatus::Open, HiringCampaignStatus::Published], true)) {
                // Not ours to close, or published and not open yet (it could
                // open on its own schedule): HR is told, nothing is decided for them.
                $leftOpen[] = (int) $campaign->getKey();
            }
        }

        return [Vacancy::FILLED, $closed, $leftOpen];
    }

    /** The contract's own employment type: the offer's, else the one its category requires. */
    private function employmentType(Version $version, ContractType $type): int
    {
        return (int) ($version->terms_json['employment_type']
            ?? ($type->category === 'service' ? EmploymentType::Freelance : EmploymentType::FullTime));
    }

    /** Same rule as ContractForm::typeMatchesCategoryRule(): freelance is for service contracts only. */
    private function typeMatchesCategory(int $employmentType, ContractType $type): bool
    {
        return ($type->category === 'service') === ($employmentType === EmploymentType::Freelance);
    }

    /**
     * The offer's dates; without an end date, the composer's own default
     * term for that kind of contract.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function term(Version $version, ContractType $type): array
    {
        $start = Carbon::parse($version->start_date)->startOfDay();

        if ($version->end_date !== null) {
            return [$start, Carbon::parse($version->end_date)->startOfDay()];
        }

        return [$start, $type->category === 'trial'
            ? $start->copy()->addMonthsNoOverflow((int) config('recruitment.conversions.trial_months', 3))
            : $start->copy()->endOfYear()->startOfDay()];
    }

    /**
     * The same fields, and the same rules, as the HR employee form
     * (PersonalInfoForm), so a hire is never a thinner record than an
     * employee HR typed in.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> employee attributes
     */
    private function validateEmployee(array $input): array
    {
        $text = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $errors = [];

        $required = [
            'firstNameEn' => 'first_name_en', 'firstNameAr' => 'first_name_ar',
            'lastNameEn' => 'last_name_en', 'lastNameAr' => 'last_name_ar',
            'fatherNameEn' => 'father_name_en', 'fatherNameAr' => 'father_name_ar',
            'motherNameEn' => 'mother_name_en', 'motherNameAr' => 'mother_name_ar',
        ];

        $person = [];
        foreach ($required as $key => $column) {
            $person[$column] = $text($key);
            if ($person[$column] === '' || mb_strlen($person[$column]) > 255) {
                $errors['employee.'.$key] = __('recruitment-applicant.conversions.errors.required');
            }
        }

        $nationality = filter_var($input['nationality'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($nationality, Nationality::getValues(), true)) {
            $errors['employee.nationality'] = __('recruitment-applicant.conversions.errors.required');
        }

        $nationalNumber = $this->normalizeNationalNumber($text('nationalNumber'));
        if ($nationalNumber === null
            || ($nationality === Nationality::Syrian ? preg_match('/^\d{11}$/', $nationalNumber) !== 1 : mb_strlen($nationalNumber) > 255)) {
            $errors['employee.nationalNumber'] = __('recruitment-applicant.conversions.errors.national_number');
        }

        $gender = filter_var($input['gender'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($gender, [Gender::Male, Gender::Female], true)) {
            $errors['employee.gender'] = __('recruitment-applicant.conversions.errors.required');
        }

        $maritalStatus = filter_var($input['maritalStatus'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($maritalStatus, MaritalStatus::getValues(), true)) {
            $errors['employee.maritalStatus'] = __('recruitment-applicant.conversions.errors.required');
        }

        $dob = null;
        if ($text('dob') !== '') {
            $dob = rescue(fn () => Carbon::createFromFormat('!Y-m-d', $text('dob')), null, false);
            if ($dob === null || $dob->format('Y-m-d') !== $text('dob') || ! $dob->isPast()) {
                $errors['employee.dob'] = __('recruitment-applicant.conversions.errors.dob');
            }
        }

        $phone = $text('personalPhone') === '' ? null : en_digits($text('personalPhone'));
        if ($phone !== null && preg_match('/^09\d{8}$/', $phone) !== 1) {
            $errors['employee.personalPhone'] = __('recruitment-applicant.conversions.errors.phone');
        }

        $email = $text('personalEmail') === '' ? null : mb_strtolower($text('personalEmail'));
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['employee.personalEmail'] = __('recruitment-applicant.conversions.errors.email');
        }

        // It is the profile page's address and the QR ID's (employees/{username}).
        $username = mb_strtolower($text('username'));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,59}$/', $username) !== 1) {
            $errors['employee.username'] = __('recruitment-applicant.conversions.errors.username');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            ...$person,
            'nationality' => $nationality,
            'national_number' => $nationalNumber,
            'gender' => $gender,
            'marital_status' => $maritalStatus,
            // One date of birth is asked for; the profile reads actual_dob, the
            // registers dob. HR corrects either on the employee page if they differ.
            'dob' => $dob?->toDateString(),
            'actual_dob' => $dob?->toDateString(),
            'personal_phone' => $phone,
            'personal_email' => $email,
            'username' => $username,
        ];
    }

    /** One failed attempt, on its own now that the hire itself rolled back. */
    private function recordFailure(User $actor, Conversion $conversion, string $reason): void
    {
        DB::transaction(function () use ($actor, $conversion, $reason): void {
            $locked = Conversion::query()->whereKey($conversion->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isConverted()) {
                return;
            }

            $locked->forceFill([
                'status' => Conversion::STATUS_FAILED,
                'failure_reason' => $reason,
                'attempt_count' => $locked->attempt_count + 1,
            ])->save();

            $this->log($actor, $locked, 'failed', [
                'job_offer_id' => $locked->job_offer_id,
                'reason' => $reason,
                'attempt_count' => $locked->attempt_count,
            ]);
        });
    }

    /** +963 9XX XXX XXX (how applicant phones are kept) → 09XXXXXXXX (how employee phones are). */
    private function localPhone(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', en_digits((string) $phone));

        if (str_starts_with($digits, '00963')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '963') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }

        return preg_match('/^09\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Whether the employee's first and last name both appear in one of the
     * candidate's names — the English pair or the Arabic pair, each name as
     * whole words, compared after foldName().
     *
     * @param  array<int, string>  $candidateNames
     */
    private function nameMatches(Employee $employee, array $candidateNames): bool
    {
        $candidates = array_filter(array_map(fn (string $name): string => ' '.$this->foldName($name).' ', $candidateNames), fn (string $name): bool => trim($name) !== '');

        foreach ([['first_name_en', 'last_name_en'], ['first_name_ar', 'last_name_ar']] as [$firstColumn, $lastColumn]) {
            $first = $this->foldName((string) $employee->getAttribute($firstColumn));
            $last = $this->foldName((string) $employee->getAttribute($lastColumn));

            if ($first === '' || $last === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (str_contains($candidate, ' '.$first.' ') && str_contains($candidate, ' '.$last.' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A name as compared: lower case, without Arabic diacritics or tatweel,
     * with the spellings people use interchangeably folded (أ إ آ → ا,
     * ة → ه, ى → ي), and anything but letters and digits as one space.
     */
    private function foldName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name);
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim($name);
    }

    private function normalizeNationalNumber(?string $value): ?string
    {
        $value = trim(en_digits((string) $value));

        return $value === '' ? null : $value;
    }

    private function suggestUsername(string $first, string $last): string
    {
        $slug = fn (string $part): string => (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($part));

        return trim($slug($first).'.'.$slug($last), '.');
    }

    private function log(User $actor, Conversion $conversion, string $event, array $properties): void
    {
        $this->activity->log(
            'recruitment_hiring_conversion.'.$event,
            subject: $conversion,
            causer: $actor,
            properties: [...$properties, 'role' => $actor->myRole()],
            logName: self::AUDIT_LOG,
        );
    }

    /** @param  array<string, mixed>  $replace */
    private function error(string $field, string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages([$field => __('recruitment-applicant.conversions.errors.'.$key, $replace)]);
    }
}
