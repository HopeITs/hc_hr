<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus as ApplicationStatus;
use App\Enums\RecruitmentInterviewLocationType as Location;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Enums\RecruitmentInterviewType as Type;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewNotice as Notice;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\RecruitmentInterviewReschedule;
use App\Models\User;
use App\Notifications\RecruitmentInterviewParticipantNotice as ParticipantNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 Task 7: every write to an interview goes through here — schedule,
 * edit / reschedule, cancel, and record the outcome (completed or no-show).
 *
 * Each method authorizes the actor itself (the component checks too: the
 * component guards the screen, this guards the action, whoever calls it),
 * then locks the application and the interview — always in that order — and
 * refuses if either moved since the user looked ($expected* arguments).
 *
 * The rules the task sets, and where each one is enforced:
 *
 * - An interview belongs to an application; an application can have many.
 * - Only a shortlisted (or already interviewing) candidate can be scheduled.
 *   A rejected one is refused until the decision is reopened through the
 *   decision panel (rejected -> under review -> shortlisted).
 * - HR may choose any date and time, a past one included (recording an
 *   interview already held); the candidate is emailed only about a slot
 *   still ahead. Times are read in the application timezone, from a strict
 *   Y-m-d H:i format, and the timezone is stored on the interview and
 *   written in every email.
 * - A participant must be an active user (active employee record); one who
 *   became inactive later is kept on the record but not written to.
 * - Moving an interview (time or place) writes a reschedule history row with
 *   the old and new values and a reason, and an activity entry; queued
 *   emails about the old slot are dropped, and a reminder already sent for it
 *   is recorded on the history row — the new slot gets its own reminder.
 * - An interview is never deleted: cancelling sets Cancelled, and queued
 *   emails for it are dropped, so no reminder goes out for it.
 * - Candidate emails are rows written in the transaction and queued after
 *   commit (RecruitmentInterviewNoticeService); participant notifications are
 *   sent after commit (RecruitmentInterviewNotifier). "After commit" is the
 *   outermost commit (DB::afterCommit), so a caller that wraps these methods
 *   in its own transaction and rolls back leaves no job and no notification.
 *   Neither can undo the interview, and neither ever carries a note, a
 *   reason or an evaluation.
 * - The application's status follows its interviews through
 *   RecruitmentApplicationStatusService::applyInterviewTransition(): the first
 *   open interview makes it InterviewScheduled; when the last one closes it
 *   becomes Interviewed (if any interview was held) or Shortlisted again.
 */
class RecruitmentInterviewSchedulingService
{
    public const AUDIT_LOG = 'recruitment-interview';

    /** The system's own cancellation, when the application closes. HR cannot pick it. */
    public const REASON_APPLICATION_CLOSED = 'application_closed';

    public const REASON_OTHER = 'other';

    public const MIN_REASON = 10;

    /** Application statuses that can have an interview scheduled. */
    public const SCHEDULABLE = [ApplicationStatus::Shortlisted, ApplicationStatus::InterviewScheduled, ApplicationStatus::Interviewed];

    /** Application statuses that close every open interview. */
    public const CLOSING = [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn, ApplicationStatus::Archived];

    private const INPUT_FORMATS = ['Y-m-d\TH:i', 'Y-m-d H:i'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly RecruitmentApplicationStatusService $statuses,
        private readonly RecruitmentInterviewNoticeService $notices,
        private readonly RecruitmentInterviewNotifier $notifier,
    ) {}

    public static function isActiveUser(?User $user): bool
    {
        return $user !== null && (bool) $user->profile?->is_active;
    }

    /** @return array<int, string> the reasons HR can pick */
    public function cancellationReasons(): array
    {
        return array_values(array_diff(
            (array) config('recruitment.interviews.cancellation_reasons', []),
            [self::REASON_APPLICATION_CLOSED],
        ));
    }

    public function timezone(): string
    {
        return (string) config('app.timezone');
    }

    /**
     * @param  array{type?: mixed, startsAt?: mixed, duration?: mixed, locationType?: mixed, locationValue?: mixed, meetingUrl?: mixed, participants?: mixed}  $input
     */
    public function schedule(User $actor, RecruitmentApplication $application, array $input, bool $notifyCandidate = true): RecruitmentInterview
    {
        Gate::forUser($actor)->authorize('schedule', [RecruitmentInterview::class, $application]);

        $details = $this->validateDetails($input);
        $participants = $this->validateParticipants($input['participants'] ?? [], []);
        $notices = [];

        $interview = DB::transaction(function () use ($actor, $application, $details, $participants, $notifyCandidate, &$notices): RecruitmentInterview {
            $locked = $this->lockApplication($application);
            $this->assertSchedulable($locked);

            $interview = RecruitmentInterview::query()->forceCreate([
                'application_id' => $locked->getKey(),
                'interview_type' => $details['type'],
                'status' => Status::Scheduled,
                'scheduled_at' => $details['starts'],
                'ends_at' => $details['ends'],
                'timezone' => $this->timezone(),
                'location_type' => $details['location_type'],
                'location_value' => $details['location_value'],
                'meeting_url' => $details['meeting_url'],
                'slot_set_at' => now(),
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            foreach ($participants as $userId => $role) {
                $interview->participants()->forceCreate([
                    'user_id' => $userId,
                    'role' => $role,
                    'notification_status' => Participant::NOTIFICATION_PENDING,
                    'added_by_user_id' => $actor->getKey(),
                ]);
            }

            if ($locked->status !== ApplicationStatus::InterviewScheduled) {
                $this->statuses->applyInterviewTransition($actor, $locked, ApplicationStatus::InterviewScheduled);
            }

            // Any date may be chosen, a past one included (recording an
            // interview already held); the candidate is invited only to a
            // slot still ahead.
            if ($notifyCandidate && $details['starts']->isFuture()) {
                $notices[] = $this->notices->prepare($actor, $interview, Notice::KIND_SCHEDULED);
            }

            $this->activity->log(
                'recruitment_interview.scheduled',
                subject: $interview,
                causer: $actor,
                properties: [
                    'application_id' => $locked->getKey(),
                    'type' => $details['type'],
                    'scheduled_at' => $details['starts']->toIso8601String(),
                    'ends_at' => $details['ends']->toIso8601String(),
                    'location_type' => $details['location_type'],
                    'participant_ids' => array_keys($participants),
                    'candidate_notified' => ($notices[0] ?? null) !== null,
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );

            return $interview;
        });

        $this->notices->dispatchAfterCommit($notices);
        DB::afterCommit(fn () => $this->notifier->notify($interview, array_keys($participants), ParticipantNotice::SCHEDULED));

        return $interview;
    }

    /**
     * Edits an open interview. A change of time or place is a reschedule: it
     * needs a reason, moves the status to Rescheduled, and is kept in the
     * history. Type and participant changes are recorded in the activity log.
     *
     * $expectedStatus / $expectedStartsAt are what the user was looking at;
     * if someone else changed the interview since, this refuses.
     */
    public function update(
        User $actor,
        RecruitmentInterview $interview,
        string $expectedStatus,
        string $expectedStartsAt,
        array $input,
        ?string $reason,
        bool $notifyCandidate = true,
    ): RecruitmentInterview {
        Gate::forUser($actor)->authorize('manage', $interview);

        $reason = trim((string) $reason) ?: null;
        $notices = [];
        $notify = [];

        $interview = DB::transaction(function () use ($actor, $interview, $expectedStatus, $expectedStartsAt, $input, $reason, $notifyCandidate, &$notices, &$notify): RecruitmentInterview {
            $application = $this->lockApplication($interview->application()->firstOrFail());
            $locked = $this->lockInterview($interview, $expectedStatus, $expectedStartsAt);
            $this->assertSchedulable($application);

            $current = [
                'starts' => $locked->scheduled_at,
                'ends' => $locked->ends_at,
                'location_type' => $locked->location_type,
                'location_value' => $locked->location_value,
                'meeting_url' => $locked->meeting_url,
            ];

            $details = $this->validateDetails($input);
            $slotChanged = ! $details['starts']->equalTo($current['starts'])
                || ! $current['ends']?->equalTo($details['ends']);

            $locationChanged = $details['location_type'] !== $current['location_type']
                || $details['location_value'] !== $current['location_value']
                || $details['meeting_url'] !== $current['meeting_url'];

            $existing = $locked->participants()->get()->keyBy('user_id');
            $currentIds = $existing->whereNull('removed_at')->keys()->map(fn ($id) => (int) $id)->all();
            $participants = $this->validateParticipants($input['participants'] ?? [], $currentIds);

            $changes = [];

            if ($slotChanged || $locationChanged) {
                if ($reason === null || mb_strlen($reason) < self::MIN_REASON) {
                    throw ValidationException::withMessages([
                        'reason' => __('recruitment-applicant.interviews.errors.reason_required', ['min' => self::MIN_REASON]),
                    ]);
                }

                $reminderSent = $this->notices->reminderSentFor($locked, $current['starts']);

                $history = RecruitmentInterviewReschedule::query()->forceCreate([
                    'interview_id' => $locked->getKey(),
                    'from_scheduled_at' => $current['starts'],
                    'from_ends_at' => $current['ends'],
                    'to_scheduled_at' => $details['starts'],
                    'to_ends_at' => $details['ends'],
                    'from_location_type' => $current['location_type'],
                    'to_location_type' => $details['location_type'],
                    'location_changed' => $locationChanged,
                    'reminder_already_sent' => $reminderSent,
                    'reason' => $reason,
                    'rescheduled_by_user_id' => $actor->getKey(),
                    'rescheduled_at' => now(),
                ]);

                // Everything still waiting was written about the old slot or place.
                $this->notices->supersedeQueued($locked);

                $locked->forceFill([
                    'status' => Status::Rescheduled,
                    'scheduled_at' => $details['starts'],
                    'ends_at' => $details['ends'],
                    'timezone' => $this->timezone(),
                    'location_type' => $details['location_type'],
                    'location_value' => $details['location_value'],
                    'meeting_url' => $details['meeting_url'],
                    'reschedule_count' => $locked->reschedule_count + 1,
                    // A new slot is a new reminder window; a new place alone is not.
                    'slot_set_at' => $slotChanged ? now() : $locked->slot_set_at,
                ]);

                $this->activity->log(
                    'recruitment_interview.rescheduled',
                    subject: $locked,
                    causer: $actor,
                    properties: [
                        'application_id' => $application->getKey(),
                        'reschedule_id' => $history->getKey(),
                        'from_scheduled_at' => $current['starts']->toIso8601String(),
                        'to_scheduled_at' => $details['starts']->toIso8601String(),
                        'slot_changed' => $slotChanged,
                        'location_changed' => $locationChanged,
                        'reminder_already_sent' => $reminderSent,
                        'role' => $actor->myRole(),
                    ],
                    logName: self::AUDIT_LOG,
                );
            }

            if ($details['type'] !== $locked->interview_type) {
                $changes['type'] = ['from' => $locked->interview_type, 'to' => $details['type']];
                $locked->interview_type = $details['type'];
            }

            [$added, $removed, $roleChanged] = $this->syncParticipants($actor, $locked, $existing, $participants);

            if ($added !== [] || $removed !== [] || $roleChanged !== []) {
                $changes['participants'] = ['added' => $added, 'removed' => $removed, 'role_changed' => $roleChanged];
            }

            $locked->updated_by_user_id = $actor->getKey();
            $locked->save();

            if ($changes !== []) {
                $this->activity->log(
                    'recruitment_interview.updated',
                    subject: $locked,
                    causer: $actor,
                    properties: ['application_id' => $application->getKey(), 'changes' => $changes, 'role' => $actor->myRole()],
                    logName: self::AUDIT_LOG,
                );
            }

            if (($slotChanged || $locationChanged) && $notifyCandidate && $details['starts']->isFuture()) {
                $notices[] = $this->notices->prepare($actor, $locked, Notice::KIND_RESCHEDULED);
            }

            $stayed = array_values(array_diff(array_keys($participants), $added));
            $notify = [
                ParticipantNotice::SCHEDULED => $added,
                ParticipantNotice::REMOVED => $removed,
                ParticipantNotice::RESCHEDULED => ($slotChanged || $locationChanged) ? $stayed : [],
            ];

            return $locked;
        });

        $this->notices->dispatchAfterCommit($notices);
        DB::afterCommit(function () use ($interview, $notify): void {
            foreach ($notify as $kind => $userIds) {
                $this->notifier->notify($interview, $userIds, $kind);
            }
        });

        return $interview;
    }

    /**
     * Cancels an open interview — the candidate declined, someone is
     * unavailable, it was a mistake. Nothing is deleted.
     */
    public function cancel(
        User $actor,
        RecruitmentInterview $interview,
        string $expectedStatus,
        string $expectedStartsAt,
        ?string $reasonCode,
        ?string $note,
        bool $notifyCandidate = true,
    ): RecruitmentInterview {
        Gate::forUser($actor)->authorize('manage', $interview);

        $reasonCode = trim((string) $reasonCode);
        $note = trim((string) $note) ?: null;

        if (! in_array($reasonCode, $this->cancellationReasons(), true)) {
            throw ValidationException::withMessages(['cancelReason' => __('recruitment-applicant.interviews.errors.cancel_reason_required')]);
        }

        if ($reasonCode === self::REASON_OTHER && ($note === null || mb_strlen($note) < self::MIN_REASON)) {
            throw ValidationException::withMessages([
                'cancelNote' => __('recruitment-applicant.interviews.errors.reason_required', ['min' => self::MIN_REASON]),
            ]);
        }

        $notices = [];
        $participantIds = [];

        $interview = DB::transaction(function () use ($actor, $interview, $expectedStatus, $expectedStartsAt, $reasonCode, $note, $notifyCandidate, &$notices, &$participantIds): RecruitmentInterview {
            $application = $this->lockApplication($interview->application()->firstOrFail());
            $locked = $this->lockInterview($interview, $expectedStatus, $expectedStartsAt);

            $this->close($locked, Status::Cancelled, $actor, [
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->getKey(),
                'cancellation_reason' => $reasonCode,
                'cancellation_note' => $note,
            ]);

            // An email about an interview already past would only confuse.
            if ($notifyCandidate && $locked->scheduled_at->isFuture()) {
                $notices[] = $this->notices->prepare($actor, $locked, Notice::KIND_CANCELLED);
            }

            $this->syncApplication($actor, $application);

            $this->activity->log(
                'recruitment_interview.cancelled',
                subject: $locked,
                causer: $actor,
                properties: [
                    'application_id' => $application->getKey(),
                    'reason_code' => $reasonCode,
                    'candidate_notified' => $notices !== [] && $notices[0] !== null,
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );

            $participantIds = $locked->currentParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            return $locked;
        });

        $this->notices->dispatchAfterCommit($notices);
        DB::afterCommit(fn () => $this->notifier->notify($interview, $participantIds, ParticipantNotice::CANCELLED));

        return $interview;
    }

    /**
     * Records how an interview ended: held (Completed) or the candidate did
     * not come (NoShow). Only once its start time has passed. A completed
     * interview is what Task 8's evaluations attach to.
     */
    public function recordOutcome(
        User $actor,
        RecruitmentInterview $interview,
        string $expectedStatus,
        string $expectedStartsAt,
        string $outcome,
        ?string $note,
    ): RecruitmentInterview {
        Gate::forUser($actor)->authorize('manage', $interview);

        if (! in_array($outcome, [Status::Completed, Status::NoShow], true)) {
            throw ValidationException::withMessages(['outcome' => __('recruitment-applicant.interviews.errors.not_allowed')]);
        }

        $note = trim((string) $note) ?: null;

        return DB::transaction(function () use ($actor, $interview, $expectedStatus, $expectedStartsAt, $outcome, $note): RecruitmentInterview {
            $application = $this->lockApplication($interview->application()->firstOrFail());
            $locked = $this->lockInterview($interview, $expectedStatus, $expectedStartsAt);

            if ($locked->scheduled_at->isFuture()) {
                throw ValidationException::withMessages(['outcome' => __('recruitment-applicant.interviews.errors.outcome_before_start')]);
            }

            $this->close($locked, $outcome, $actor, [
                'outcome_recorded_at' => now(),
                'outcome_recorded_by_user_id' => $actor->getKey(),
                'outcome_note' => $note,
            ]);

            $this->syncApplication($actor, $application);

            // Held: evaluation is open now, and the reviewers are asked for theirs.
            if ($outcome === Status::Completed) {
                $reviewerIds = $locked->currentParticipants()
                    ->whereIn('role', (array) config('recruitment.evaluations.reviewer_roles', []))
                    ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
                DB::afterCommit(fn () => $this->notifier->notify($locked, $reviewerIds, ParticipantNotice::EVALUATION_OPEN));
            }

            $this->activity->log(
                'recruitment_interview.'.$outcome,
                subject: $locked,
                causer: $actor,
                properties: ['application_id' => $application->getKey(), 'role' => $actor->myRole()],
                logName: self::AUDIT_LOG,
            );

            return $locked;
        });
    }

    /**
     * The application was rejected, withdrawn or archived while interviews
     * were still open: they are cancelled, and nothing more goes to the
     * candidate about them (a rejection email is the decision panel's call).
     *
     * Called from RecruitmentApplicationObserver, inside the transaction that
     * changed the application's status and already holds its row lock.
     */
    public function closeForApplication(RecruitmentApplication $application): void
    {
        $open = RecruitmentInterview::query()
            ->where('application_id', $application->getKey())
            ->open()
            ->lockForUpdate()
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        $actor = auth()->user();

        foreach ($open as $interview) {
            $this->close($interview, Status::Cancelled, $actor, [
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor?->getKey(),
                'cancellation_reason' => self::REASON_APPLICATION_CLOSED,
            ]);

            $this->activity->log(
                'recruitment_interview.cancelled',
                subject: $interview,
                causer: $actor,
                properties: [
                    'application_id' => $application->getKey(),
                    'reason_code' => self::REASON_APPLICATION_CLOSED,
                    'application_status' => $application->status,
                    'candidate_notified' => false,
                ],
                logName: self::AUDIT_LOG,
            );

            $userIds = $interview->currentParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            // Participants hear about it only once the status change commits.
            DB::afterCommit(fn () => $this->notifier->notify($interview, $userIds, ParticipantNotice::CANCELLED));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, starts: Carbon, ends: Carbon, location_type: string, location_value: ?string, meeting_url: ?string}
     */
    public function validateDetails(array $input): array
    {
        $errors = [];

        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (! in_array($type, Type::values(), true)) {
            $errors['type'] = __('recruitment-applicant.interviews.errors.type_required');
        }

        $starts = $this->parseLocal($input['startsAt'] ?? null);
        if ($starts === null) {
            $errors['startsAt'] = __('recruitment-applicant.interviews.errors.invalid_date');
        }

        $min = (int) config('recruitment.interviews.min_duration_minutes', 15);
        $max = (int) config('recruitment.interviews.max_duration_minutes', 480);
        $duration = filter_var($input['duration'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < $min || $duration > $max) {
            $errors['duration'] = __('recruitment-applicant.interviews.errors.duration', ['min' => $min, 'max' => $max]);
        }

        $locationType = is_string($input['locationType'] ?? null) ? $input['locationType'] : '';
        $locationValue = trim((string) ($input['locationValue'] ?? '')) ?: null;
        $meetingUrl = trim((string) ($input['meetingUrl'] ?? '')) ?: null;

        if (! in_array($locationType, Location::values(), true)) {
            $errors['locationType'] = __('recruitment-applicant.interviews.errors.location_type_required');
        } elseif ($locationType === Location::InPerson) {
            // A physical interview has an address and no link.
            $meetingUrl = null;
            if ($locationValue === null) {
                $errors['locationValue'] = __('recruitment-applicant.interviews.errors.location_required');
            }
        } elseif ($meetingUrl === null || ! $this->isMeetingUrl($meetingUrl)) {
            $errors['meetingUrl'] = __('recruitment-applicant.interviews.errors.meeting_url');
        }

        if ($locationValue !== null && mb_strlen($locationValue) > 2048) {
            $errors['locationValue'] = __('recruitment-applicant.interviews.errors.too_long');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type' => $type,
            'starts' => $starts,
            'ends' => $starts->copy()->addMinutes($duration),
            'location_type' => $locationType,
            'location_value' => $locationValue,
            'meeting_url' => $meetingUrl,
        ];
    }

    /**
     * $input is [user_id => role]. Every user must exist and be active —
     * except someone already on the interview, who may stay on it after
     * leaving (they are simply not written to any more).
     *
     * @param  array<int, int>  $currentIds
     * @return array<int, string> [user_id => role]
     */
    public function validateParticipants(mixed $input, array $currentIds): array
    {
        $participants = [];

        foreach (is_array($input) ? $input : [] as $userId => $role) {
            $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || ! in_array($role, Participant::roles(), true)) {
                throw ValidationException::withMessages(['participants' => __('recruitment-applicant.interviews.errors.participant_invalid')]);
            }
            $participants[$userId] = $role;
        }

        $max = (int) config('recruitment.interviews.max_participants', 15);
        if (count($participants) > $max) {
            throw ValidationException::withMessages(['participants' => __('recruitment-applicant.interviews.errors.too_many_participants', ['max' => $max])]);
        }

        $users = User::query()->whereKey(array_keys($participants))->with('profile')->get()->keyBy('id');

        foreach (array_keys($participants) as $userId) {
            $user = $users->get($userId);

            if ($user === null) {
                throw ValidationException::withMessages(['participants' => __('recruitment-applicant.interviews.errors.participant_invalid')]);
            }

            if (! self::isActiveUser($user) && ! in_array($userId, $currentIds, true)) {
                throw ValidationException::withMessages(['participants' => __('recruitment-applicant.interviews.errors.participant_inactive', [
                    'name' => $user->profile?->name ?? $user->email,
                ])]);
            }
        }

        return $participants;
    }

    /** A local wall-clock time in the application timezone, strictly formatted. */
    public function parseLocal(mixed $value): ?Carbon
    {
        if (! is_string($value) || ($value = trim($value)) === '') {
            return null;
        }

        foreach (self::INPUT_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value, $this->timezone());
            } catch (Throwable) {
                continue;
            }

            // Rejects overflow such as 2026-02-30 or 25:00, which PHP would
            // otherwise roll into the next day.
            if ($parsed !== null && $parsed->format($format) === $value) {
                return $parsed->second(0);
            }
        }

        return null;
    }

    /** Same order everywhere: the application first, then the interview. */
    private function lockApplication(RecruitmentApplication $application): RecruitmentApplication
    {
        return RecruitmentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockInterview(RecruitmentInterview $interview, string $expectedStatus, string $expectedStartsAt): RecruitmentInterview
    {
        $locked = RecruitmentInterview::query()->whereKey($interview->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expectedStatus || $locked->scheduled_at->toIso8601String() !== $expectedStartsAt) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.stale')]);
        }

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.not_open')]);
        }

        return $locked;
    }

    private function assertSchedulable(RecruitmentApplication $locked): void
    {
        if ($locked->status === ApplicationStatus::Rejected) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.application_rejected')]);
        }

        if (! in_array($locked->status, self::SCHEDULABLE, true)) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.not_shortlisted')]);
        }

        if ($locked->applicant()->whereNotNull('anonymized_at')->exists()) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.anonymized')]);
        }
    }

    /** Ends an open interview: status, the given fields, and no email left waiting for it. */
    private function close(RecruitmentInterview $locked, string $to, ?User $actor, array $fields): void
    {
        if (! Status::canMove($locked->status, $to)) {
            throw ValidationException::withMessages(['interview' => __('recruitment-applicant.interviews.errors.not_allowed')]);
        }

        $this->notices->supersedeQueued($locked);

        $locked->forceFill(['status' => $to, 'updated_by_user_id' => $actor?->getKey() ?? $locked->updated_by_user_id] + $fields)->save();
    }

    /**
     * Once no open interview is left, the application leaves
     * InterviewScheduled: to Interviewed if one was held, otherwise back to
     * Shortlisted, where HR decides what comes next.
     */
    private function syncApplication(User $actor, RecruitmentApplication $locked): void
    {
        if ($locked->status !== ApplicationStatus::InterviewScheduled) {
            return;
        }

        $interviews = RecruitmentInterview::query()->where('application_id', $locked->getKey());

        if ((clone $interviews)->open()->exists()) {
            return;
        }

        $this->statuses->applyInterviewTransition(
            $actor,
            $locked,
            (clone $interviews)->where('status', Status::Completed)->exists()
                ? ApplicationStatus::Interviewed
                : ApplicationStatus::Shortlisted,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Participant>  $existing  keyed by user_id, removed rows included
     * @param  array<int, string>  $wanted  [user_id => role]
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>} added, removed, role changed
     */
    private function syncParticipants(User $actor, RecruitmentInterview $locked, $existing, array $wanted): array
    {
        $added = [];
        $removed = [];
        $roleChanged = [];

        foreach ($wanted as $userId => $role) {
            $row = $existing->get($userId);

            if ($row === null) {
                $locked->participants()->forceCreate([
                    'user_id' => $userId,
                    'role' => $role,
                    'notification_status' => Participant::NOTIFICATION_PENDING,
                    'added_by_user_id' => $actor->getKey(),
                ]);
                $added[] = $userId;
            } elseif ($row->removed_at !== null) {
                // Back on the interview: the same row, re-activated.
                $row->forceFill([
                    'role' => $role,
                    'removed_at' => null,
                    'removed_by_user_id' => null,
                    'added_by_user_id' => $actor->getKey(),
                    'notification_status' => Participant::NOTIFICATION_PENDING,
                ])->save();
                $added[] = $userId;
            } elseif ($row->role !== $role) {
                $row->forceFill(['role' => $role])->save();
                $roleChanged[] = $userId;
            }
        }

        foreach ($existing as $userId => $row) {
            if ($row->removed_at === null && ! array_key_exists((int) $userId, $wanted)) {
                $row->forceFill(['removed_at' => now(), 'removed_by_user_id' => $actor->getKey()])->save();
                $removed[] = (int) $userId;
            }
        }

        return [$added, $removed, $roleChanged];
    }

    private function isMeetingUrl(string $url): bool
    {
        return mb_strlen($url) <= 2048
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true);
    }
}
