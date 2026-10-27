<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * HR's steps after a manager decided a leave request (C3; DEC-05 revision,
 * approval-source-contract.md §3.3 S2 and §4.3 G3/G5). The only writer of
 * hr_approved and of HR's notes and objections; the leave list's row action
 * and the work inbox both call it.
 *
 * Each step locks the request, then authorizes (so a second click or another
 * HR officer sees the committed state), writes in one transaction under the
 * request-then-balance lock order, and notifies after commit.
 */
final class LeaveRequestReview
{
    public const NOTE_MAX = 1000;

    public function __construct(private readonly LeaveNotificationService $notifications) {}

    /** "Seen by HR", with an optional note visible to HR, the manager and the requester. */
    public function markSeen(int $leaveRequestId, User $actor, ?string $note = null): LeaveRequest
    {
        $note = $this->validNote($note, required: false);

        $leaveRequest = DB::transaction(function () use ($leaveRequestId, $actor, $note): LeaveRequest {
            $leaveRequest = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequestId);

            // After the lock: a concurrent review must observe the committed flag.
            Gate::forUser($actor)->authorize('review', $leaveRequest);

            $leaveRequest->update(['hr_approved' => true]);
            $this->addNote($leaveRequest, $actor, LeaveRequestNote::KIND_NOTE, $note);

            return $leaveRequest;
        });

        $this->notifications->sendHrReview($leaveRequest, $actor);

        return $leaveRequest;
    }

    /**
     * Send a decided request back to the direct manager as pending, with HR's
     * required note (C3D-Q5). An accepted request gives its deducted hours back
     * to the balance in the same transaction (C3D-Q6), so as pending it only
     * reserves them again (DEC-03).
     */
    public function object(int $leaveRequestId, User $actor, ?string $note): LeaveRequest
    {
        $note = $this->validNote($note, required: true);

        $leaveRequest = DB::transaction(function () use ($leaveRequestId, $actor, $note): LeaveRequest {
            $leaveRequest = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequestId);

            Gate::forUser($actor)->authorize('object', $leaveRequest);

            if ((int) $leaveRequest->status === LeaveRequestStatus::Accepted) {
                // Lock order: request, then balance (as in submitDecision()).
                $balance = LeaveBalance::query()->lockForUpdate()->findOrFail($leaveRequest->leave_balance_id);
                $hours = $leaveRequest->deductionHours();

                if ($hours > 0) {
                    $balance->update(['used' => max(0, round((float) $balance->used - $hours, 2))]);
                }
            }

            $leaveRequest->update([
                'status' => LeaveRequestStatus::Pending,
                'reject_reason' => null,
                'approved_at' => null,
                'approved_by' => null,
                'decision_basis' => null,
                'decided_on_behalf_of' => null,
                'hr_approved' => false,
            ]);
            $this->addNote($leaveRequest, $actor, LeaveRequestNote::KIND_OBJECTION, $note);

            return $leaveRequest;
        });

        $this->notifications->sendObjection($leaveRequest, $actor, $note);

        return $leaveRequest;
    }

    private function addNote(LeaveRequest $leaveRequest, User $actor, string $kind, ?string $body): void
    {
        if ($body === null) {
            return;
        }

        $leaveRequest->notes()->create([
            'author_employee_id' => $actor->employee_id,
            'author_role' => $actor->myRole(),
            'kind' => $kind,
            'body' => $body,
        ]);
    }

    /** Trimmed note, or null when empty and optional. Field errors use the key "note". */
    private function validNote(?string $note, bool $required): ?string
    {
        $note = trim((string) $note);

        Validator::make(['note' => $note], [
            'note' => [$required ? 'required' : 'nullable', 'string', 'max:'.self::NOTE_MAX],
        ], [], ['note' => __('leaves.review.note')])->validate();

        return $note === '' ? null : $note;
    }
}
