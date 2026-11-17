<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode as Mode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\WorkflowInstanceStatus;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\User;
use App\Notifications\RecruitmentRequestAwaitingExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Approving, rejecting and returning a request for changes.
 *
 * Six things are asked before any decision is written, in order, all of them
 * on the server, and a failure of any one refuses the act without touching a
 * row:
 *
 *   1  permission          the role may decide at all
 *   2  the active step     this step is the one the workflow is waiting on
 *   3  the assigned approver  this user is the person it is waiting on
 *   4  scope               still held, measured now rather than at assignment
 *   5  actionable          the transition table allows the move
 *   6  a current version   the screen was not looking at an older one
 *
 * Only the first is bypassed by an administrator role (D10). The other five
 * are asked of everybody, which is what stops the holder of every permission
 * in the system from deciding a step that belongs to someone else.
 */
class RecruitmentApprovalService
{
    public function __construct(
        private readonly RecruitmentScopeResolver $scopes,
        private readonly RecruitmentRequestStateMachine $states,
        private readonly RecruitmentRequestRevalidator $revalidator,
        private readonly RecruitmentWorkflowInstanceService $workflows,
        private readonly RecruitmentRequestApplicationService $applications,
        private readonly RecruitmentNotifier $notifier,
    ) {}

    /**
     * Approve the step, and advance or finish the workflow (T6 · T7).
     *
     * The whole thing is one transaction around a locked step row: two
     * approvers acting at the same instant serialize on that lock, and the
     * second finds the workflow has moved on and fails check 2 rather than
     * writing a second decision over the first.
     */
    public function approve(User $actor, RecruitmentWorkflowInstanceStep $step, int $versionNo, ?string $comment = null): RecruitmentRequest
    {
        return $this->decide($actor, $step, $versionNo, ApprovalDecision::Approve, $comment, 'approve');
    }

    /** End the request. The reason is mandatory and is what the requester reads. */
    public function reject(User $actor, RecruitmentWorkflowInstanceStep $step, int $versionNo, string $reason): RecruitmentRequest
    {
        return $this->decide($actor, $step, $versionNo, ApprovalDecision::Reject, $reason, 'reject');
    }

    /**
     * Hand it back to the requester (T9), naming what they may change.
     *
     * Two things are mandatory, and for the same reason. The comment has to say
     * what is wrong (D60), and `$fields` has to say which fields the requester
     * may touch — because the request has been locked since it was submitted
     * and this ask is the only thing that unlocks any part of it. An ask with
     * no fields would hand back a document the requester cannot change.
     *
     * @param  array<int, string>  $fields  payload keys the requester may edit
     */
    public function requestChanges(
        User $actor,
        RecruitmentWorkflowInstanceStep $step,
        int $versionNo,
        string $comment,
        array $fields = [],
    ): RecruitmentRequest {
        return $this->decide(
            $actor, $step, $versionNo, ApprovalDecision::RequestChanges, $comment, 'requestChanges',
            array_values(array_unique(array_filter(
                $fields,
                fn ($field): bool => is_string($field) && trim($field) !== '',
            ))),
        );
    }

    /**
     * Tell HR the decision is made and the effect is still to come.
     *
     * Without this the request would look finished on the approver's screen
     * while the post is still open, and nobody would know a date was pending.
     * Failing to notify never undoes an approval that has already committed.
     */
    private function notifyHrOfPendingExecution(RecruitmentRequest $request): void
    {
        try {
            $date = $this->applications->effectiveDate($request);

            User::broadcastRecipientsQuery()
                ->get()
                ->filter(fn (User $user): bool => $user->activeRoleIs('HR')
                    && $user->hasActivePermission('recruitment-requests.manage'))
                ->each(fn (User $user) => $user->notify(
                    new RecruitmentRequestAwaitingExecution($request, $date),
                ));
        } catch (Throwable $exception) {
            Log::warning('Notifying HR of a pending recruitment execution failed.', [
                'request_id' => $request->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<int, string> $fields the fields a request for changes unlocks */
    private function decide(
        User $actor,
        RecruitmentWorkflowInstanceStep $step,
        int $versionNo,
        int $decision,
        ?string $comment,
        string $ability,
        array $fields = [],
    ): RecruitmentRequest {
        if ($decision !== ApprovalDecision::Approve && blank($comment)) {
            throw ValidationException::withMessages([
                'comment' => __($decision === ApprovalDecision::Reject
                    ? 'recruitment.rejection_needs_a_reason'
                    : 'recruitment.changes_need_to_be_stated'),
            ]);
        }

        // Asked after the comment, because saying what is wrong comes before
        // saying which fields it lives in, and an approver who has said
        // neither should be told about the first one first.
        if ($decision === ApprovalDecision::RequestChanges && $fields === []) {
            throw ValidationException::withMessages([
                'fields' => __('recruitment.changes_need_fields'),
            ]);
        }

        $applied = DB::transaction(function () use ($actor, $step, $versionNo, $decision, $comment, $ability, $fields) {
            // Locked first, so everything read after it is read under the lock.
            $locked = RecruitmentWorkflowInstanceStep::query()
                ->whereKey($step->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $instance = RecruitmentWorkflowInstance::query()
                ->whereKey($locked->workflow_instance_id)
                ->lockForUpdate()
                ->firstOrFail();

            $request = RecruitmentRequest::query()
                ->whereKey($instance->request_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1 — the permission, through the policy.
            Gate::forUser($actor)->authorize($ability, $request);

            $this->assertDecidable($actor, $locked, $instance, $request, $versionNo, $decision);

            $locked->forceFill([
                'acted_by_user_id' => $actor->getKey(),
                'decision' => $decision,
                'decision_reason' => $comment,
                'acted_at' => now(),
            ])->save();

            $this->record($request, $actor, $instance, $locked, [
                'event_type' => match ($decision) {
                    ApprovalDecision::Approve => RecruitmentEventType::StepApproved,
                    ApprovalDecision::Reject => RecruitmentEventType::StepRejected,
                    default => RecruitmentEventType::ChangesRequested,
                },
                'decision' => $decision,
                'from_status' => (int) $request->status,
                'comment' => $comment,
                // The fields live on the decision that asked for them, so the
                // audit trail and the lock can never disagree about the ask.
                'meta' => $fields === [] ? null : ['requested_fields' => $fields],
            ]);

            return match ($decision) {
                ApprovalDecision::Approve => $this->advance($actor, $request, $instance, $locked),
                ApprovalDecision::Reject => $this->close($actor, $request, $instance, 'reject', WorkflowInstanceStatus::Rejected),
                default => $this->close($actor, $request, $instance, 'request_changes', WorkflowInstanceStatus::Cancelled),
            };
        });

        // D44 and D59: the effect is applied in a second transaction, after the
        // approval is committed, so a failure there cannot roll the approval
        // back and ask the approver to decide again.
        //
        // The service decides for itself whether the effect is due. A reduction
        // or a closure dated in the future stays approved and waits, and HR is
        // told it is waiting; everything else takes effect now.
        if ((int) $applied->status === RecruitmentRequestStatus::Approved) {
            $before = (int) $applied->status;
            $applied = $this->applications->apply($applied, $actor);

            if ((int) $applied->status === $before && $this->applications->isAwaitingEffectiveDate($applied)) {
                $this->notifyHrOfPendingExecution($applied);
            }
        }

        // Last, and outside everything. The request is committed and its effect
        // is settled, so what the notices describe is already true — and a mail
        // server that is down cannot undo any of it.
        $this->notifier->decided(
            $applied,
            RecruitmentWorkflowInstance::query()->find($step->workflow_instance_id) ?? $step->workflowInstance,
            $decision,
            $comment,
            $actor,
        );

        return $applied;
    }

    /** Checks 2 to 6. The first is the policy's, and is asked before this. */
    private function assertDecidable(
        User $actor,
        RecruitmentWorkflowInstanceStep $step,
        RecruitmentWorkflowInstance $instance,
        RecruitmentRequest $request,
        int $versionNo,
        int $decision,
    ): void {
        // 2 — the workflow is waiting on this step, and nobody has decided it.
        $isCurrent = $step->decision === null
            && (int) $instance->status === WorkflowInstanceStatus::Running
            && (int) $step->workflowStep?->step_order === (int) $instance->current_step_order;

        if (! $isCurrent) {
            throw ValidationException::withMessages(['step' => __('recruitment.step_is_not_current')]);
        }

        // 3 and 4 — the assigned approver, and the scope still held today.
        if (! $this->scopes->canActOnStep($actor, $step)) {
            throw ValidationException::withMessages(['step' => __('recruitment.not_your_step')]);
        }

        // 5 — the move is one the transition table allows.
        if (! $this->states->can($request, $this->triggerFor($request, $instance, $step, $decision))) {
            throw ValidationException::withMessages(['request' => __('recruitment.transition_not_allowed')]);
        }

        // 6 — the screen was looking at the version under review, not an older
        // one. Approving a version that has since been superseded approves
        // something nobody is asking for any more.
        if ($versionNo !== (int) $instance->requestVersion?->version_no) {
            throw ValidationException::withMessages(['version' => __('recruitment.version_moved_on')]);
        }
    }

    /** Advance to the next step, or finish (T6 · T7). */
    private function advance(
        User $actor,
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        RecruitmentWorkflowInstanceStep $step,
    ): RecruitmentRequest {
        $next = $this->nextStepOrder($instance, $step);

        if ($next !== null) {
            $instance->forceFill(['current_step_order' => $next])->save();
            $this->states->apply($request, 'approve_step');

            $this->record($request, $actor, $instance, null, [
                'event_type' => RecruitmentEventType::WorkflowAdvanced,
                'to_status' => (int) $request->status,
                'meta' => ['current_step_order' => $next],
            ]);

            return $request;
        }

        // The last approver. RV-2 to RV-6 run again here, on the values as
        // they are now: the world moved while the request waited.
        try {
            $this->revalidator->assert($request, (array) ($request->draft_payload_json ?? []));
        } catch (ValidationException $exception) {
            return $this->voidOnRevalidationFailure($actor, $request, $instance, $exception);
        }

        $instance->forceFill([
            'status' => WorkflowInstanceStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $this->states->apply($request, $this->finalTriggerFor($request));

        $this->record($request, $actor, $instance, null, [
            'event_type' => RecruitmentEventType::WorkflowAdvanced,
            'to_status' => (int) $request->status,
        ]);

        return $request;
    }

    /**
     * Revalidation failed at the last moment (RV-2 · RV-6).
     *
     * A normal request goes back to its requester to be fixed. A direct opening
     * has no such cycle — its requester is HR — so it is refused instead, with
     * a reason the system generated rather than a person (D39).
     */
    private function voidOnRevalidationFailure(
        User $actor,
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        ValidationException $exception,
    ): RecruitmentRequest {
        $direct = (int) $request->mode === Mode::DirectHr;
        $reason = __('recruitment.revalidation_failed', [
            'reason' => implode(' ', array_merge(...array_values($exception->errors()))),
        ]);

        $instance->forceFill([
            'status' => $direct ? WorkflowInstanceStatus::Rejected : WorkflowInstanceStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        $this->states->apply($request, $direct ? 'direct_open_voided' : 'request_changes');

        $this->record($request, $actor, $instance, null, [
            'event_type' => $direct ? RecruitmentEventType::StepRejected : RecruitmentEventType::ChangesRequested,
            'to_status' => (int) $request->status,
            'comment' => $reason,
            'meta' => ['revalidation' => $exception->errors()],
        ]);

        return $request;
    }

    /** Rejecting or returning ends this workflow run (T8 · T9). */
    private function close(
        User $actor,
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        string $trigger,
        int $instanceStatus,
    ): RecruitmentRequest {
        $instance->forceFill(['status' => $instanceStatus, 'completed_at' => now()])->save();
        $this->states->apply($request, $trigger);

        $this->record($request, $actor, $instance, null, [
            'event_type' => RecruitmentEventType::WorkflowAdvanced,
            'to_status' => (int) $request->status,
        ]);

        return $request;
    }

    /** The step order after this one, or null when this was the last. */
    private function nextStepOrder(RecruitmentWorkflowInstance $instance, RecruitmentWorkflowInstanceStep $step): ?int
    {
        $next = $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query
                ->where('step_order', '>', (int) $step->workflowStep?->step_order))
            ->whereNull('decision')
            ->with('workflowStep')
            ->get()
            ->sortBy(fn (RecruitmentWorkflowInstanceStep $candidate) => (int) $candidate->workflowStep?->step_order)
            ->first();

        return $next === null ? null : (int) $next->workflowStep?->step_order;
    }

    private function triggerFor(
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        RecruitmentWorkflowInstanceStep $step,
        int $decision,
    ): string {
        return match ($decision) {
            ApprovalDecision::Reject => 'reject',
            ApprovalDecision::RequestChanges => 'request_changes',
            default => $this->nextStepOrder($instance, $step) !== null
                ? 'approve_step'
                : $this->finalTriggerFor($request),
        };
    }

    /** A direct opening reaches approved by its own transition (T21). */
    private function finalTriggerFor(RecruitmentRequest $request): string
    {
        return (int) $request->mode === Mode::DirectHr ? 'direct_open_approved' : 'approve_final';
    }

    /** D8: a failed audit write is reported and never rolls back the decision. */
    private function record(
        RecruitmentRequest $request,
        User $actor,
        RecruitmentWorkflowInstance $instance,
        ?RecruitmentWorkflowInstanceStep $step,
        array $attributes,
    ): void {
        try {
            DB::transaction(fn () => $request->events()->create(array_merge([
                'request_version_id' => $instance->request_version_id,
                'workflow_instance_id' => $instance->getKey(),
                'workflow_step_id' => $step?->workflow_step_id,
                'actor_user_id' => $actor->getKey(),
                // Stored as it is now, never re-derived: reading an old event
                // with today's role would misrepresent who decided what.
                'actor_role' => $actor->myRole() ?? 'system',
                'scope_type' => $this->scopes->scopeType($actor),
            ], $attributes)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
