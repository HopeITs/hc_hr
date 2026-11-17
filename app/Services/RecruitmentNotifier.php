<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\WorkflowStepKey;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\User;
use App\Notifications\RecruitmentApprovalAwaiting;
use App\Notifications\RecruitmentApprovalUnwound;
use App\Notifications\RecruitmentRequestApprovedForHr;
use App\Notifications\RecruitmentRequestDecided;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling people what the workflow did, without ever being what it did.
 *
 * Notifications are a courtesy laid over a record, never the record. Three
 * rules follow from that and they are the whole design:
 *
 *   1  Nothing is sent from inside a transaction. A mail cannot be unsent when
 *      the transaction that prompted it rolls back, so every call here happens
 *      after the commit that made it true.
 *   2  Nothing sent from here can fail the thing it describes. Every dispatch
 *      is wrapped: a dead mail server, a queue that is not running, a user with
 *      no address — none of it reaches the caller.
 *   3  Nothing here grants anything. The links go to the request inside the
 *      system, where the approver signs in and the six checks run again. There
 *      is no deciding from a mail, and the pending list is read from the
 *      database rather than from anybody's inbox.
 *
 * A duplicate notice is therefore harmless: it points at a request whose state
 * is read fresh when the link is opened. The same is true of a notice that
 * reaches somebody who is no longer the approver — they open it and find the
 * step is not theirs, which is exactly what the system should tell them.
 */
class RecruitmentNotifier
{
    /**
     * A workflow has started and its first approver is waiting.
     *
     * Called after submission commits, never from inside it: a request that
     * fails to save must not leave somebody holding a mail about it.
     */
    public function workflowStarted(RecruitmentRequest $request, ?RecruitmentWorkflowInstance $instance): void
    {
        if ($instance === null) {
            return;
        }

        $this->notifyCurrentApprover($request, $instance);
    }

    /**
     * A decision was made: tell whoever it now concerns.
     *
     * Who that is depends on what the decision did rather than on what it was
     * called. An approval that moved the workflow on concerns the next
     * approver; one that finished it concerns the requester and HR; anything
     * that handed the request back concerns the requester.
     */
    public function decided(
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        int $decision,
        ?string $comment = null,
        ?User $decidedBy = null,
    ): void {
        $status = (int) $request->status;

        if ($decision === ApprovalDecision::Approve && $status === Status::UnderReview) {
            $this->notifyCurrentApprover($request, $instance->fresh() ?? $instance);

            return;
        }

        // Approved, applied, failed to apply, rejected or handed back: in every
        // one of those the requester is the person waiting to hear.
        $this->send($request->requester, new RecruitmentRequestDecided($request, $decision, $comment));

        if ($decision === ApprovalDecision::RequestChanges) {
            $this->notifyEarlierApprovers($request, $instance, $decidedBy);
        }

        if (in_array($status, [Status::Approved, Status::Applied, Status::FailedApplication], true)) {
            $this->notifyHr($request, $status === Status::Applied);
        }
    }

    /**
     * Tell everybody who already approved that the document is changing.
     *
     * They approved what the request said at the time, and somebody further
     * down has sent it back to be rewritten. Nothing is being asked of them
     * yet — it goes to its author and comes round again — but a record that
     * moves underneath an approval without the approver hearing of it is a
     * record they cannot rely on.
     *
     * Only the people who actually decided: a skipped step had no decider,
     * and the approver who asked for the changes already knows.
     */
    private function notifyEarlierApprovers(
        RecruitmentRequest $request,
        RecruitmentWorkflowInstance $instance,
        ?User $decidedBy,
    ): void {
        try {
            $instance->steps()
                ->whereNotNull('decision')
                ->whereNotNull('acted_by_user_id')
                // The person who asked is excluded by who they are rather than
                // by being the most recent: two decisions can share a second,
                // and "latest" would then be a coin toss.
                ->when($decidedBy !== null,
                    fn ($query) => $query->where('acted_by_user_id', '!=', $decidedBy->getKey()))
                ->with('actedBy.profile')
                ->get()
                ->pluck('actedBy')
                ->filter()
                ->unique('id')
                ->each(fn (User $approver) => $this->send(
                    $approver,
                    new RecruitmentApprovalUnwound(
                        $request,
                        $decidedBy?->profile?->name ?? $decidedBy?->name,
                    ),
                ));
        } catch (Throwable $exception) {
            $this->swallow($request, $exception);
        }
    }

    /** The person the workflow is waiting on right now, if it resolved one. */
    private function notifyCurrentApprover(RecruitmentRequest $request, RecruitmentWorkflowInstance $instance): void
    {
        $step = app(RecruitmentWorkflowInstanceService::class)->currentStep($instance);

        // An unassigned step is not a failure here: HR was already told the
        // approver could not be resolved, and there is nobody to write to.
        if ($step?->assigned_user_id === null) {
            return;
        }

        $this->send(
            $step->assignedUser,
            new RecruitmentApprovalAwaiting(
                $request,
                WorkflowStepKey::getLocalizedName((int) $step->workflowStep?->step_key)['value'] ?? null,
            ),
        );
    }

    private function notifyHr(RecruitmentRequest $request, bool $applied): void
    {
        try {
            User::broadcastRecipientsQuery()
                ->get()
                ->filter(fn (User $user): bool => $user->activeRoleIs('HR')
                    && $user->hasActivePermission('recruitment-requests.manage'))
                ->each(fn (User $user) => $this->send(
                    $user, new RecruitmentRequestApprovedForHr($request, $applied),
                ));
        } catch (Throwable $exception) {
            $this->swallow($request, $exception);
        }
    }

    /**
     * Dispatch, and let nothing out.
     *
     * The failure is logged rather than raised, because the caller has already
     * committed the thing this describes and there is nothing useful it could
     * do with the exception except undo work that was correct.
     */
    private function send(?User $user, object $notification): void
    {
        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            $this->swallow($notification->request ?? null, $exception);
        }
    }

    private function swallow(?RecruitmentRequest $request, Throwable $exception): void
    {
        Log::warning('A recruitment notification could not be sent.', [
            'request_id' => $request?->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
