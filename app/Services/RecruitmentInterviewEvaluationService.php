<?php

namespace App\Services;

use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation as Evaluation;
use App\Models\RecruitmentInterviewEvaluationRevision as Revision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 Task 8: every write to an interview evaluation goes through here.
 *
 * - submit(): a participant writes or edits their own evaluation.
 * - correct(): HR fixes a mistake in someone else's, with a reason.
 * - withdraw(): HR takes one out of the aggregate, with a reason. Nothing is
 *   ever deleted.
 * - close(): HR closes evaluation of a completed interview.
 *
 * Every method authorizes the actor itself (components check too), then
 * locks the interview row — the same serialization point Task 7 uses — and
 * then the evaluation. Two reviewers writing at once each write their own
 * row, one after the other; the unique (interview, reviewer) key makes a
 * second row for the same reviewer impossible. A save made against an older
 * version than the one stored (another tab, or HR correcting meanwhile) is
 * refused instead of silently overwriting it.
 *
 * Every change bumps the version and appends a full revision (who, when,
 * why, and the content after the change), so no edit loses the history, and
 * logs an activity entry that names what changed without repeating it: the
 * activity log is read outside recruitment scope, evaluations are not.
 *
 * A recommendation is one reviewer's advice. Nothing here reads or changes
 * the application's status: the hiring decision stays in
 * RecruitmentApplicationStatusService, made by a person, on its own screen.
 */
class RecruitmentInterviewEvaluationService
{
    public const AUDIT_LOG = 'recruitment-interview-evaluation';

    private const FIELDS = ['pros', 'cons', 'notes', 'recommendation'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Why this interview cannot be evaluated right now, or null if it can:
     * cancelled, no_show, not_held, closed. Evaluation opens when HR records
     * the interview as held — not at its start time — so nobody evaluates an
     * interview that may not have taken place.
     */
    public function closedReason(RecruitmentInterview $interview): ?string
    {
        return match (true) {
            $interview->status === Status::Cancelled => 'cancelled',
            $interview->status === Status::NoShow => 'no_show',
            $interview->status !== Status::Completed => 'not_held',
            $interview->evaluationsClosed() => 'closed',
            default => null,
        };
    }

    /**
     * A participant's own evaluation: created on the first save, edited on
     * the next ones. $expectedVersion is the version the form was opened on
     * (null for a new evaluation).
     *
     * @param  array{pros?: mixed, cons?: mixed, notes?: mixed, recommendation?: mixed}  $input
     */
    public function submit(User $reviewer, RecruitmentInterview $interview, array $input, ?int $expectedVersion): Evaluation
    {
        Gate::forUser($reviewer)->authorize('evaluate', $interview);
        $content = $this->validateContent($input);

        try {
            return DB::transaction(function () use ($reviewer, $interview, $content, $expectedVersion): Evaluation {
                $locked = $this->lockInterview($interview);

                // Re-asked under the lock: the reviewer may have been taken
                // off the interview, or it cancelled, since the form opened.
                Gate::forUser($reviewer)->authorize('evaluate', $locked);
                $this->assertOpen($locked);

                $evaluation = Evaluation::query()
                    ->where('interview_id', $locked->getKey())
                    ->where('reviewer_user_id', $reviewer->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($evaluation === null) {
                    if ($expectedVersion !== null) {
                        throw $this->stale();
                    }

                    $evaluation = Evaluation::query()->forceCreate([
                        'interview_id' => $locked->getKey(),
                        'reviewer_user_id' => $reviewer->getKey(),
                        ...$content,
                        'version' => 1,
                        'submitted_at' => now(),
                        'updated_by_user_id' => $reviewer->getKey(),
                    ]);

                    $this->record($reviewer, $locked, $evaluation, Evaluation::ACTION_CREATED, array_keys($content), null);

                    return $evaluation;
                }

                if ($evaluation->isWithdrawn()) {
                    throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.withdrawn')]);
                }

                $this->assertVersion($evaluation, $expectedVersion);

                return $this->change($reviewer, $locked, $evaluation, $content, Evaluation::ACTION_UPDATED, null);
            });
        } catch (UniqueConstraintViolationException) {
            // Two first saves raced past the lookup: the other one won.
            throw $this->stale();
        }
    }

    /**
     * HR corrects a mistake in someone else's evaluation — allowed after
     * evaluation closed, never without a reason, never without history.
     *
     * @param  array{pros?: mixed, cons?: mixed, notes?: mixed, recommendation?: mixed}  $input
     */
    public function correct(User $actor, Evaluation $evaluation, int $expectedVersion, array $input, ?string $reason): Evaluation
    {
        Gate::forUser($actor)->authorize('correct', $evaluation);
        $content = $this->validateContent($input);
        $reason = $this->validateReason($reason);

        return DB::transaction(function () use ($actor, $evaluation, $expectedVersion, $content, $reason): Evaluation {
            $interview = $this->lockInterview($evaluation->interview()->firstOrFail());
            $locked = Evaluation::query()->whereKey($evaluation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.withdrawn')]);
            }

            $this->assertVersion($locked, $expectedVersion);

            if ($this->changedFields($locked, $content) === []) {
                throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.no_changes')]);
            }

            return $this->change($actor, $interview, $locked, $content, Evaluation::ACTION_CORRECTED, $reason);
        });
    }

    /** HR takes an evaluation out of the aggregate. Its content and history stay. */
    public function withdraw(User $actor, Evaluation $evaluation, int $expectedVersion, ?string $reason): Evaluation
    {
        Gate::forUser($actor)->authorize('withdraw', $evaluation);
        $reason = $this->validateReason($reason);

        return DB::transaction(function () use ($actor, $evaluation, $expectedVersion, $reason): Evaluation {
            $interview = $this->lockInterview($evaluation->interview()->firstOrFail());
            $locked = Evaluation::query()->whereKey($evaluation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.withdrawn')]);
            }

            $this->assertVersion($locked, $expectedVersion);

            $locked->forceFill([
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->getKey(),
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $actor->getKey(),
                'withdrawal_reason' => $reason,
            ])->save();

            $this->record($actor, $interview, $locked, Evaluation::ACTION_WITHDRAWN, [], $reason);

            return $locked;
        });
    }

    /** HR closes evaluation of a completed interview; reviewers can no longer edit. */
    public function close(User $actor, RecruitmentInterview $interview, string $expectedStatus): RecruitmentInterview
    {
        Gate::forUser($actor)->authorize('closeEvaluations', $interview);

        return DB::transaction(function () use ($actor, $interview, $expectedStatus): RecruitmentInterview {
            $locked = $this->lockInterview($interview);

            if ($locked->status !== $expectedStatus) {
                throw $this->stale();
            }

            if ($locked->status !== Status::Completed) {
                throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.close_before_completion')]);
            }

            if ($locked->evaluations_closed_at !== null) {
                throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.already_closed')]);
            }

            $locked->forceFill(['evaluations_closed_at' => now(), 'evaluations_closed_by_user_id' => $actor->getKey()])->save();

            $this->activity->log(
                'recruitment_interview.evaluations_closed',
                subject: $locked,
                causer: $actor,
                properties: [
                    'application_id' => $locked->application_id,
                    'evaluations' => $locked->evaluations()->active()->count(),
                    'role' => $actor->myRole(),
                ],
                logName: self::AUDIT_LOG,
            );

            return $locked;
        });
    }

    /**
     * The aggregate HR reads: how many reviewers said what. Withdrawn
     * evaluations are left out. Advisory only — not a decision, not a score.
     *
     * @return array{total: int, counts: array<string, int>}
     */
    public function summary(RecruitmentInterview $interview): array
    {
        $counts = $interview->evaluations()->active()
            ->selectRaw('recommendation, count(*) as aggregate')
            ->groupBy('recommendation')
            ->pluck('aggregate', 'recommendation');

        $ordered = [];
        foreach (Recommendation::values() as $value) {
            $ordered[$value] = (int) ($counts[$value] ?? 0);
        }

        return ['total' => array_sum($ordered), 'counts' => $ordered];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{pros: ?string, cons: ?string, notes: ?string, recommendation: string}
     */
    public function validateContent(array $input): array
    {
        $max = (int) config('recruitment.evaluations.max_text_length', 5000);
        $errors = [];
        $content = [];

        foreach (['pros', 'cons', 'notes'] as $field) {
            $value = $input[$field] ?? null;
            $value = is_string($value) ? (trim($value) ?: null) : null;

            if ($value !== null && mb_strlen($value) > $max) {
                $errors[$field] = __('recruitment-applicant.evaluations.errors.too_long', ['max' => $max]);
            }

            $content[$field] = $value;
        }

        $recommendation = $input['recommendation'] ?? null;
        if (! in_array($recommendation, Recommendation::values(), true)) {
            $errors['recommendation'] = __('recruitment-applicant.evaluations.errors.recommendation_required');
        }
        $content['recommendation'] = (string) $recommendation;

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $content;
    }

    private function validateReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        $min = (int) config('recruitment.evaluations.min_reason_length', 10);

        if (mb_strlen($reason) < $min) {
            throw ValidationException::withMessages(['reason' => __('recruitment-applicant.evaluations.errors.reason_required', ['min' => $min])]);
        }

        return $reason;
    }

    /** Applies $content, bumps the version and records the revision. */
    private function change(User $actor, RecruitmentInterview $interview, Evaluation $locked, array $content, string $action, ?string $reason): Evaluation
    {
        $changed = $this->changedFields($locked, $content);

        // Saving the same thing again is not a change and leaves no revision.
        if ($changed === []) {
            return $locked;
        }

        $locked->forceFill([
            ...$content,
            'version' => $locked->version + 1,
            'updated_by_user_id' => $actor->getKey(),
        ])->save();

        $this->record($actor, $interview, $locked, $action, $changed, $reason);

        return $locked;
    }

    /** @return array<int, string> */
    private function changedFields(Evaluation $evaluation, array $content): array
    {
        return array_values(array_filter(
            self::FIELDS,
            fn (string $field): bool => $evaluation->getAttribute($field) !== $content[$field],
        ));
    }

    /** The revision row (content after the change) and the activity entry (no content). */
    private function record(User $actor, RecruitmentInterview $interview, Evaluation $evaluation, string $action, array $changedFields, ?string $reason): void
    {
        Revision::query()->forceCreate([
            'evaluation_id' => $evaluation->getKey(),
            'version' => $evaluation->version,
            'action' => $action,
            'pros' => $evaluation->pros,
            'cons' => $evaluation->cons,
            'notes' => $evaluation->notes,
            'recommendation' => $evaluation->recommendation,
            'reason' => $reason,
            'changed_by_user_id' => $actor->getKey(),
            'changed_at' => now(),
        ]);

        $this->activity->log(
            'recruitment_interview_evaluation.'.$action,
            subject: $evaluation,
            causer: $actor,
            properties: [
                'interview_id' => $interview->getKey(),
                'application_id' => $interview->application_id,
                'reviewer_user_id' => (int) $evaluation->reviewer_user_id,
                'version' => $evaluation->version,
                'changed_fields' => $changedFields,
                'role' => $actor->myRole(),
            ],
            logName: self::AUDIT_LOG,
        );
    }

    private function lockInterview(RecruitmentInterview $interview): RecruitmentInterview
    {
        return RecruitmentInterview::query()->whereKey($interview->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(RecruitmentInterview $locked): void
    {
        if (($reason = $this->closedReason($locked)) !== null) {
            throw ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.'.$reason)]);
        }
    }

    private function assertVersion(Evaluation $locked, ?int $expectedVersion): void
    {
        if ($expectedVersion !== $locked->version) {
            throw $this->stale();
        }
    }

    private function stale(): ValidationException
    {
        return ValidationException::withMessages(['evaluation' => __('recruitment-applicant.evaluations.errors.stale')]);
    }
}
