<?php

namespace App\Services;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Enums\SalaryPer;
use App\Models\ContractType;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\RecruitmentJobOfferVersion as Version;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 Task 9: every write to a job offer goes through here — create,
 * edit a draft, send, revise, record the candidate's answer, cancel, expire.
 *
 * Rules, and where each is enforced:
 *
 * - An offer is created only for a selected application, and one offer per
 *   application (unique key). A change after sending, a refusal, an expiry or
 *   a cancellation is handled by revising: a new version of the same offer.
 * - A version is edited only while it is a draft; once sent it is frozen
 *   (RecruitmentJobOfferVersion refuses any other write), so the salary,
 *   start date and contract terms that were offered stay on record as a
 *   snapshot, and every version is kept.
 * - The application's status follows the offer through
 *   RecruitmentApplicationStatusService::applyOfferTransition(): offer sent,
 *   accepted, refused; an expired or cancelled offer puts it back to
 *   selected, where HR can revise the offer or choose another candidate.
 * - Nothing here closes, fills or reopens the vacancy: a refused or expired
 *   offer leaves it exactly as it was. Accepting needs a free seat — a seat
 *   is taken by an accepted offer until the hiring conversion (Task 10)
 *   fills it — so a vacancy that filled up in the meantime refuses it.
 * - An accepted offer is final, and hands over to the hiring conversion by
 *   writing its pending conversion row once: the unique job_offer_id on
 *   recruitment_hiring_conversions makes a second handover impossible.
 * - Every method authorizes the actor itself, locks the application and then
 *   the offer — the same order everywhere — and refuses if the offer moved
 *   since the user looked ($expectedVersionId).
 * - Audit entries name what changed; the salary is logged by name only, never
 *   by value (the activity log is read outside recruitment scope).
 */
class RecruitmentJobOfferService
{
    public const AUDIT_LOG = 'recruitment-job-offer';

    public const REASON_OTHER = 'other';

    public const MIN_REASON = 10;

    /** Application statuses a sendable offer can belong to. */
    private const SENDABLE_FROM = [AppStatus::Selected, AppStatus::OfferRejected, AppStatus::OfferSent];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly RecruitmentApplicationStatusService $statuses,
    ) {}

    /**
     * Whether the offer can go out again as a new version right now: its own
     * state allows a revision, and its application is still in the offer
     * stage. A candidate rejected in the meantime cannot be offered again.
     */
    public function canRevise(Offer $offer): bool
    {
        return Status::canMove($offer->status, Status::Revised)
            && in_array($offer->application?->status, self::SENDABLE_FROM, true);
    }

    /** @return array<int, string> the reasons HR can pick */
    public function cancellationReasons(): array
    {
        return array_values(array_diff(
            (array) config('recruitment.offers.cancellation_reasons', []),
            [Offer::CANCEL_APPLICATION_CLOSED],
        ));
    }

    /** @param  array<string, mixed>  $input */
    public function create(User $actor, RecruitmentApplication $application, array $input): Offer
    {
        Gate::forUser($actor)->authorize('create', [Offer::class, $application]);
        $terms = $this->validateTerms($input);

        try {
            return DB::transaction(function () use ($actor, $application, $terms): Offer {
                $locked = $this->lockApplication($application);

                if ($locked->status === AppStatus::Rejected) {
                    throw $this->error('offer', 'application_rejected');
                }

                // Before the status check: an application whose offer is out
                // (offer_sent, offer_rejected…) is "not selected" too, but the
                // useful answer is that it already has an offer to revise.
                if (Offer::query()->where('application_id', $locked->getKey())->exists()) {
                    throw $this->error('offer', 'already_exists');
                }

                if ($locked->status !== AppStatus::Selected) {
                    throw $this->error('offer', 'not_selected');
                }

                $vacancy = $locked->vacancy()->first() ?? $locked->campaign?->vacancy;

                if ($vacancy === null) {
                    throw $this->error('offer', 'no_vacancy');
                }

                $offer = Offer::query()->forceCreate([
                    'application_id' => $locked->getKey(),
                    'vacancy_id' => $vacancy->getKey(),
                    'position_id' => $vacancy->position_id,
                    'status' => Status::Draft,
                    'created_by_user_id' => $actor->getKey(),
                    'updated_by_user_id' => $actor->getKey(),
                ]);

                $version = $offer->versions()->forceCreate([
                    'version_no' => 1,
                    ...$terms,
                    'status' => Version::STATUS_DRAFT,
                    'created_by_user_id' => $actor->getKey(),
                    'updated_by_user_id' => $actor->getKey(),
                ]);

                $offer->forceFill(['current_version_id' => $version->getKey()])->save();

                $this->log($actor, $offer, 'created', ['version_no' => 1]);

                return $offer;
            });
        } catch (UniqueConstraintViolationException) {
            // Two creations raced past the check: the other one won.
            throw $this->error('offer', 'already_exists');
        }
    }

    /**
     * Edits the current draft (a new offer, or a revision not yet sent).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateDraft(User $actor, Offer $offer, int $expectedVersionId, array $input): Offer
    {
        Gate::forUser($actor)->authorize('manage', $offer);
        $terms = $this->validateTerms($input);

        return DB::transaction(function () use ($actor, $offer, $expectedVersionId, $terms): Offer {
            [, $locked, $version] = $this->lock($offer, $expectedVersionId);

            if (! $locked->isEditable() || ! $version->isDraft()) {
                throw $this->error('offer', 'not_editable');
            }

            $changed = $this->changedTerms($version, $terms);

            if ($changed === []) {
                return $locked;
            }

            $version->forceFill([...$terms, 'updated_by_user_id' => $actor->getKey()])->save();
            $locked->forceFill(['updated_by_user_id' => $actor->getKey()])->save();

            $this->log($actor, $locked, 'draft_updated', ['version_no' => $version->version_no, 'changed_fields' => $changed]);

            return $locked;
        });
    }

    /**
     * Sends the current draft to the candidate. A revision sent while an
     * earlier version was out supersedes it.
     */
    public function send(User $actor, Offer $offer, int $expectedVersionId, ?string $expiresOn): Offer
    {
        Gate::forUser($actor)->authorize('manage', $offer);
        $expiresAt = $this->validateExpiry($expiresOn);

        return DB::transaction(function () use ($actor, $offer, $expectedVersionId, $expiresAt): Offer {
            [$application, $locked, $version] = $this->lock($offer, $expectedVersionId);

            if (! Status::canMove($locked->status, Status::Sent) || ! $version->isDraft()) {
                throw $this->error('offer', 'not_sendable');
            }

            if (! in_array($application->status, self::SENDABLE_FROM, true)) {
                throw $this->error('offer', 'not_selected');
            }

            $this->assertSeatAvailable($locked);

            // The version the candidate had until now is replaced, not rewritten.
            $locked->versions()->where('status', Version::STATUS_SENT)->get()
                ->each(fn (Version $previous) => $previous->forceFill(['status' => Version::STATUS_SUPERSEDED])->save());

            $version->forceFill(['status' => Version::STATUS_SENT, 'sent_at' => now()])->save();

            $locked->forceFill([
                'status' => Status::Sent,
                'sent_at' => now(),
                'expires_at' => $expiresAt,
                'expired_at' => null,
                'rejected_at' => null,
                'response_recorded_by_user_id' => null,
                'response_note' => null,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            if ($application->status !== AppStatus::OfferSent) {
                $this->statuses->applyOfferTransition($actor, $application, AppStatus::OfferSent);
            }

            $this->log($actor, $locked, 'sent', ['version_no' => $version->version_no, 'expires_at' => $expiresAt->toIso8601String()]);

            return $locked;
        });
    }

    /**
     * Starts a new version from the current one — after HR changes the terms
     * of a sent offer, or after a refusal, an expiry or a cancellation. The
     * earlier versions stay exactly as they were.
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(User $actor, Offer $offer, int $expectedVersionId, array $input, ?string $reason): Offer
    {
        Gate::forUser($actor)->authorize('manage', $offer);
        $terms = $this->validateTerms($input);
        $reason = $this->validateReason($reason, 'reason');

        return DB::transaction(function () use ($actor, $offer, $expectedVersionId, $terms, $reason): Offer {
            [$application, $locked, $version] = $this->lock($offer, $expectedVersionId);

            if (! Status::canMove($locked->status, Status::Revised)) {
                throw $this->error('offer', 'not_revisable');
            }

            if (! in_array($application->status, self::SENDABLE_FROM, true)) {
                throw $this->error('offer', 'not_selected');
            }

            $revision = $locked->versions()->forceCreate([
                'version_no' => (int) $locked->versions()->max('version_no') + 1,
                ...$terms,
                'revision_reason' => $reason,
                'status' => Version::STATUS_DRAFT,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            $locked->forceFill([
                'status' => Status::Revised,
                'current_version_id' => $revision->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->log($actor, $locked, 'revised', [
                'from_version_no' => $version->version_no,
                'version_no' => $revision->version_no,
                'changed_fields' => $this->changedTerms($version, $terms),
            ]);

            return $locked;
        });
    }

    /**
     * Records the candidate's answer to the offer that is with them.
     * Accepting is final and hands over to the hiring conversion once.
     */
    public function recordResponse(User $actor, Offer $offer, int $expectedVersionId, string $response, ?string $note): Offer
    {
        Gate::forUser($actor)->authorize('manage', $offer);

        if (! in_array($response, [Status::Accepted, Status::Rejected], true)) {
            throw $this->error('response', 'response_required');
        }

        $note = trim((string) $note) ?: null;

        // An offer past its expiry is expired first — committed on its own —
        // and the answer refused, even if the sweep has not run yet.
        if ($this->expireIfDue($offer)) {
            throw $this->error('offer', 'expired');
        }

        return DB::transaction(function () use ($actor, $offer, $expectedVersionId, $response, $note): Offer {
            [$application, $locked, $version] = $this->lock($offer, $expectedVersionId);

            if ($locked->status === Status::Accepted) {
                throw $this->error('offer', 'already_accepted');
            }

            if ($locked->status !== Status::Sent || $version->status !== Version::STATUS_SENT) {
                throw $this->error('offer', 'not_sent');
            }

            if ($response === Status::Accepted) {
                $this->assertSeatAvailable($locked);
            }

            $accepted = $response === Status::Accepted;
            $version->forceFill(['status' => $accepted ? Version::STATUS_ACCEPTED : Version::STATUS_REJECTED])->save();

            $locked->forceFill([
                'status' => $response,
                ($accepted ? 'accepted_at' : 'rejected_at') => now(),
                'response_recorded_by_user_id' => $actor->getKey(),
                'response_note' => $note,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->statuses->applyOfferTransition($actor, $application, $accepted ? AppStatus::OfferAccepted : AppStatus::OfferRejected);

            if ($accepted) {
                $this->handOverToConversion($actor, $application, $locked, $version);
            }

            $this->log($actor, $locked, $response, ['version_no' => $version->version_no]);

            return $locked;
        });
    }

    /** HR withdraws the offer. Nothing is deleted. */
    public function cancel(User $actor, Offer $offer, int $expectedVersionId, ?string $reasonCode, ?string $note): Offer
    {
        Gate::forUser($actor)->authorize('manage', $offer);

        $reasonCode = trim((string) $reasonCode);
        if (! in_array($reasonCode, $this->cancellationReasons(), true)) {
            throw $this->error('cancelReason', 'cancel_reason_required');
        }

        $note = $reasonCode === self::REASON_OTHER
            ? $this->validateReason($note, 'cancelNote')
            : (trim((string) $note) ?: null);

        return DB::transaction(function () use ($actor, $offer, $expectedVersionId, $reasonCode, $note): Offer {
            [$application, $locked] = $this->lock($offer, $expectedVersionId);

            if (! Status::canMove($locked->status, Status::Cancelled)) {
                throw $this->error('offer', 'not_cancellable');
            }

            $this->close($actor, $application, $locked, Status::Cancelled, [
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->getKey(),
                'cancellation_reason' => $reasonCode,
                'cancellation_note' => $note,
            ]);

            $this->log($actor, $locked, 'cancelled', ['reason_code' => $reasonCode]);

            return $locked;
        });
    }

    /**
     * The expiry sweep: every sent offer past its expiry date. Nothing is
     * sent to anyone and the vacancy is left as it is.
     *
     * @return int how many offers expired
     */
    public function expireDue(): int
    {
        $expired = 0;

        foreach (Offer::query()->where('status', Status::Sent)->where('expires_at', '<=', now())->pluck('id') as $id) {
            $expired += (int) $this->expireIfDue(Offer::query()->findOrFail($id));
        }

        return $expired;
    }

    /**
     * The application moved on — rejected, sent back to review, withdrawn —
     * while a draft offer was still open for it: the draft is cancelled.
     * Called from RecruitmentApplicationObserver, inside the transaction that
     * changed the status. A sent offer cannot be in this position: while it
     * is out the application is "offer sent", which no manual move leaves.
     */
    public function closeForApplication(RecruitmentApplication $application): void
    {
        $offer = Offer::query()
            ->where('application_id', $application->getKey())
            ->whereIn('status', Status::editable())
            ->lockForUpdate()
            ->first();

        if ($offer === null) {
            return;
        }

        $actor = auth()->user();
        $offer->versions()->where('status', Version::STATUS_DRAFT)->get()
            ->each(fn (Version $draft) => $draft->forceFill(['status' => Version::STATUS_CANCELLED])->save());

        $offer->forceFill([
            'status' => Status::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $actor?->getKey(),
            'cancellation_reason' => Offer::CANCEL_APPLICATION_CLOSED,
            'updated_by_user_id' => $actor?->getKey() ?? $offer->updated_by_user_id,
        ])->save();

        $this->activity->log(
            'recruitment_job_offer.cancelled',
            subject: $offer,
            causer: $actor,
            properties: ['application_id' => $application->getKey(), 'reason_code' => Offer::CANCEL_APPLICATION_CLOSED, 'application_status' => $application->status],
            logName: self::AUDIT_LOG,
        );
    }

    /**
     * Seats a vacancy can still give: needed minus filled, minus the offers
     * already accepted but not yet converted (they hold their seat).
     */
    public function seatsLeft(Vacancy $vacancy, ?Offer $except = null): int
    {
        if ($vacancy->status !== Vacancy::OPEN) {
            return 0;
        }

        $held = Offer::query()
            ->where('vacancy_id', $vacancy->getKey())
            ->whereIn('status', Status::takingSeat())
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->whereDoesntHave('hiringConversion', fn ($conversions) => $conversions->whereNotNull('converted_at'))
            ->count();

        return max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count - $held);
    }

    /**
     * Every field is required, so an accepted offer can become a contract
     * as it stands. The dates are HR's to choose, a past start included; the
     * end only has to follow the start, within the year a contract may run.
     * The contract type is a trial or a service contract only, as on the
     * recruitment request (ContractType::recruitmentOptions()). The
     * probation is any whole number of days HR types, 0 included.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> version attributes
     */
    public function validateTerms(array $input): array
    {
        $errors = [];
        $int = fn (string $key) => filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);

        $contractTypeId = $int('contractTypeId');
        $contractType = $contractTypeId === false ? null : ContractType::query()->recruitmentOptions()->find($contractTypeId);
        if ($contractType === null) {
            $errors['contractTypeId'] = __('recruitment-applicant.offers.errors.contract_type_required');
        }

        $salary = is_scalar($input['salary'] ?? null) ? trim((string) $input['salary']) : '';
        if (! is_numeric($salary) || (float) $salary <= 0 || (float) $salary > (float) config('recruitment.offers.max_salary')) {
            $errors['salary'] = __('recruitment-applicant.offers.errors.salary');
        }

        $currency = $int('currency');
        if (! in_array($currency, [Currency::SYP, Currency::USD, Currency::EUR], true)) {
            $errors['currency'] = __('recruitment-applicant.offers.errors.currency');
        }

        $salaryPer = $int('salaryPer');
        if (! in_array($salaryPer, [SalaryPer::Month, SalaryPer::Hour, SalaryPer::Session], true)) {
            $errors['salaryPer'] = __('recruitment-applicant.offers.errors.salary_per');
        }

        $startDate = $this->parseDate($input['startDate'] ?? null);
        if ($startDate === null) {
            $errors['startDate'] = __('recruitment-applicant.offers.errors.start_date');
        }

        $endDate = $this->parseDate($input['endDate'] ?? null);
        if ($endDate === null || ($startDate !== null && ! $endDate->gt($startDate))) {
            $errors['endDate'] = __('recruitment-applicant.offers.errors.end_date');
        } elseif ($startDate !== null && $endDate->gt($startDate->copy()->addYear())) {
            // Task 10: the contract this becomes is at most a year long
            // (ContractForm), and an accepted offer cannot be changed.
            $errors['endDate'] = __('recruitment-applicant.offers.errors.end_date_too_far');
        }

        // Required; any whole number of days the column can hold.
        $probation = filter_var($input['probationDays'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
        if ($probation === false) {
            $errors['probationDays'] = __('recruitment-applicant.offers.errors.probation_required');
        }

        // Only a service contract may say 0 hours, as ContractForm allows.
        $minHours = $contractType?->category === 'service' ? 0 : 1;
        $weeklyHours = $int('weeklyHours');
        if ($weeklyHours === false || $weeklyHours < $minHours || $weeklyHours > (int) config('recruitment.offers.max_weekly_hours')) {
            $errors['weeklyHours'] = __('recruitment-applicant.offers.errors.weekly_hours', ['min' => $minHours, 'max' => config('recruitment.offers.max_weekly_hours')]);
        }

        $employmentType = $int('employmentType');
        if (! in_array($employmentType, [EmploymentType::FullTime, EmploymentType::PartTime, EmploymentType::Freelance], true)) {
            $errors['employmentType'] = __('recruitment-applicant.offers.errors.employment_type_required');
        } elseif ($contractType !== null && ($contractType->category === 'service') !== ($employmentType === EmploymentType::Freelance)) {
            // Task 10: freelance for a service contract and only for one, as
            // ContractForm::typeMatchesCategoryRule() requires of the contract.
            $errors['employmentType'] = __('recruitment-applicant.offers.errors.employment_type_mismatch');
        }

        $place = $int('place');
        if (! in_array($place, [ContractPlace::Onsite, ContractPlace::Online], true)) {
            $errors['place'] = __('recruitment-applicant.offers.errors.place_required');
        }

        $notes = is_string($input['notes'] ?? null) ? (trim($input['notes']) ?: null) : null;
        if ($notes === null) {
            $errors['notes'] = __('recruitment-applicant.offers.errors.notes_required');
        } elseif (mb_strlen($notes) > 2000) {
            $errors['notes'] = __('recruitment-applicant.offers.errors.too_long', ['max' => 2000]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'contract_type_id' => $contractTypeId,
            'salary_amount' => $salary,
            'currency' => $currency,
            'salary_per' => $salaryPer,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'weekly_hours' => $weeklyHours,
            'probation_days' => $probation,
            'terms_json' => array_filter(
                ['employment_type' => $employmentType, 'place' => $place, 'notes' => $notes],
                fn ($value) => $value !== null,
            ),
        ];
    }

    /** Expires one sent offer if its date has passed. Its own transaction. */
    private function expireIfDue(Offer $offer): bool
    {
        return DB::transaction(function () use ($offer): bool {
            $application = RecruitmentApplication::query()->whereKey($offer->application_id)->lockForUpdate()->firstOrFail();
            $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Status::Sent || $locked->expires_at === null || $locked->expires_at->isFuture()) {
                return false;
            }

            $this->close(null, $application, $locked, Status::Expired, ['expired_at' => now()]);

            $this->activity->log(
                'recruitment_job_offer.expired',
                subject: $locked,
                properties: ['application_id' => $application->getKey(), 'expires_at' => $locked->expires_at->toIso8601String()],
                logName: self::AUDIT_LOG,
            );

            return true;
        });
    }

    /**
     * Ends a live offer as expired or cancelled: its versions still in play
     * follow, and an application that was "offer sent" goes back to selected.
     */
    private function close(?User $actor, RecruitmentApplication $application, Offer $locked, string $to, array $fields): void
    {
        $versionStatus = $to === Status::Expired ? Version::STATUS_EXPIRED : Version::STATUS_CANCELLED;

        $locked->versions()->whereIn('status', [Version::STATUS_DRAFT, Version::STATUS_SENT])->get()
            ->each(fn (Version $version) => $version->forceFill(['status' => $versionStatus])->save());

        $locked->forceFill(['status' => $to, 'updated_by_user_id' => $actor?->getKey() ?? $locked->updated_by_user_id] + $fields)->save();

        if ($application->status === AppStatus::OfferSent) {
            // The system's own move when the sweep expires an offer: recorded
            // against the offer's creator, the person the offer belongs to.
            $this->statuses->applyOfferTransition($actor ?? $locked->creator()->firstOrFail(), $application, AppStatus::Selected);
        }
    }

    private function handOverToConversion(User $actor, RecruitmentApplication $application, Offer $offer, Version $version): void
    {
        $applicant = $application->applicant()->firstOrFail();

        try {
            RecruitmentHiringConversion::query()->forceCreate([
                'job_offer_id' => $offer->getKey(),
                'job_offer_version_id' => $version->getKey(),
                'applicant_id' => $applicant->getKey(),
                // Who the new employee is. The pay and contract terms are read
                // from the accepted version itself, which stays encrypted.
                'employee_payload_json' => [
                    'full_name' => $applicant->full_name,
                    'email' => $application->email_normalized,
                    'phone' => $applicant->phone_normalized,
                    'city_id' => $applicant->city_id,
                    'position_id' => $offer->position_id,
                    'vacancy_id' => $offer->vacancy_id,
                ],
                'status' => 'pending',
                'started_at' => now(),
                'converted_by_user_id' => $actor->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->error('offer', 'already_accepted');
        }
    }

    /** @return array{0: RecruitmentApplication, 1: Offer, 2: Version} */
    private function lock(Offer $offer, int $expectedVersionId): array
    {
        $application = $this->lockApplication($offer->application()->firstOrFail());
        $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

        if ((int) $locked->current_version_id !== $expectedVersionId) {
            throw $this->error('offer', 'stale');
        }

        return [$application, $locked, Version::query()->whereKey($locked->current_version_id)->lockForUpdate()->firstOrFail()];
    }

    private function lockApplication(RecruitmentApplication $application): RecruitmentApplication
    {
        return RecruitmentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertSeatAvailable(Offer $locked): void
    {
        $vacancy = Vacancy::query()->whereKey($locked->vacancy_id)->lockForUpdate()->firstOrFail();

        if ($this->seatsLeft($vacancy, $locked) < 1) {
            throw $this->error('offer', 'vacancy_full');
        }
    }

    private function validateExpiry(?string $expiresOn): Carbon
    {
        if (blank($expiresOn)) {
            return today()->addDays((int) config('recruitment.offers.validity_days', 7))->endOfDay();
        }

        $date = $this->parseDate($expiresOn);
        $max = (int) config('recruitment.offers.max_validity_days', 60);

        if ($date === null || ! $date->gt(today()) || $date->gt(today()->addDays($max))) {
            throw $this->error('expiresOn', 'expiry', ['max' => $max]);
        }

        return $date->endOfDay();
    }

    private function validateReason(?string $reason, string $field): string
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw $this->error($field, 'reason_required', ['min' => self::MIN_REASON]);
        }

        return $reason;
    }

    /** A strict Y-m-d date; 2026-02-30 and friends are refused rather than rolled over. */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Term fields that differ between a version and new terms. The salary is
     * compared on its value, and reported by name only.
     *
     * @return array<int, string>
     */
    private function changedTerms(Version $version, array $terms): array
    {
        $current = [
            'contract_type_id' => $version->contract_type_id,
            'salary_amount' => $version->salary_amount,
            'currency' => $version->currency,
            'salary_per' => $version->salary_per,
            'start_date' => $version->start_date?->toDateString(),
            'end_date' => $version->end_date?->toDateString(),
            'weekly_hours' => $version->weekly_hours,
            'probation_days' => $version->probation_days,
            'terms_json' => $version->terms_json ?? [],
        ];
        $terms['salary_amount'] = number_format((float) $terms['salary_amount'], 2, '.', '');

        return array_values(array_filter(
            array_keys($current),
            fn (string $field): bool => $current[$field] != $terms[$field],
        ));
    }

    private function log(User $actor, Offer $offer, string $event, array $properties): void
    {
        $this->activity->log(
            'recruitment_job_offer.'.$event,
            subject: $offer,
            causer: $actor,
            properties: ['application_id' => $offer->application_id, ...$properties, 'role' => $actor->myRole()],
            logName: self::AUDIT_LOG,
        );
    }

    private function error(string $field, string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages([$field => __('recruitment-applicant.offers.errors.'.$key, $replace)]);
    }
}
