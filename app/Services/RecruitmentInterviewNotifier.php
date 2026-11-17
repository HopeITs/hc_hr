<?php

namespace App\Services;

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\User;
use App\Notifications\RecruitmentInterviewParticipantNotice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells staff participants what happened to an interview they are on, on the
 * same three rules as RecruitmentNotifier: called only after the commit that
 * made it true, never able to fail the thing it describes, and granting
 * nothing — the link leads into the system, where access is checked again.
 *
 * A participant whose account or employee record is no longer active is not
 * written to; their row is marked skipped so HR can see it.
 */
class RecruitmentInterviewNotifier
{
    /** @param  array<int, int>  $userIds */
    public function notify(RecruitmentInterview $interview, array $userIds, string $kind): void
    {
        if ($userIds === []) {
            return;
        }

        $users = User::query()->whereKey($userIds)->with('profile')->get();

        foreach ($users as $user) {
            $status = Participant::NOTIFICATION_SENT;

            if (! RecruitmentInterviewSchedulingService::isActiveUser($user)) {
                $status = Participant::NOTIFICATION_SKIPPED;
            } else {
                try {
                    $user->notify(new RecruitmentInterviewParticipantNotice($interview, $kind));
                } catch (Throwable $exception) {
                    $status = Participant::NOTIFICATION_FAILED;
                    Log::warning('A recruitment interview notification could not be sent.', [
                        'interview_id' => $interview->getKey(),
                        'user_id' => $user->getKey(),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }

            rescue(fn () => Participant::query()
                ->where('interview_id', $interview->getKey())
                ->where('user_id', $user->getKey())
                ->update(['notification_status' => $status]), report: false);
        }
    }
}
