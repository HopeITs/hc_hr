<?php

namespace App\Services\Assistant;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Submits a leave request the assistant drafted, once the employee confirmed
 * it: the same checks and the same path as the My leaves page.
 *
 * Nothing in the proposal is trusted: it is filled into the page's own
 * LeaveRequestForm (through LeaveRequestDrafter) and validated again, since
 * time may have passed and the balance may have changed since the draft.
 */
class LeaveRequestSubmitter
{
    public function __construct(private LeaveRequestDrafter $drafter) {}

    /**
     * @param  array<string, mixed>  $proposal  an AssistantReply::$leaveProposal
     * @param  string|null  $submissionToken  one per shown draft: a second confirm of
     *                                        the same draft returns the request the first created
     *
     * @throws ValidationException when the request is no longer valid
     */
    public function submit(User $user, array $proposal, ?string $submissionToken = null): LeaveRequest
    {
        // The page's own gate for a new request (MyLeaveRequestModule::create / processSave).
        if (! $user->profile || ! $user->hasActivePermission('leaves.create')) {
            throw ValidationException::withMessages([
                'permission' => Lang::has('assistant.leave.errors.forbidden')
                    ? __('assistant.leave.errors.forbidden')
                    : 'You are not allowed to request leave.',
            ]);
        }

        [$leaveRequest, $replayed] = $this->drafter->store($user, $proposal, $submissionToken);

        if (! $replayed) {
            // As processSave(): only once the row is committed.
            DB::afterCommit(fn () => app(LeaveNotificationService::class)->sendNewRequest($leaveRequest, $user));
        }

        return $leaveRequest->refresh();
    }
}
