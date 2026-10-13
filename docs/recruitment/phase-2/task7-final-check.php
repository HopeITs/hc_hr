<?php

/*
 * Phase 2 Task 7 (interviews) — final check on the real MySQL database.
 * Every line prints PASS / FAIL. Everything runs in one transaction that is
 * ROLLED BACK at the end: nothing is left behind, and no email is sent (the
 * candidate notices go out only after a commit, which never happens here).
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task7-final-check.php';"
 *
 * It builds its own throwaway applications on an existing campaign, moves
 * them through the decision service, and drives the scheduling service, the
 * policies and the Livewire pages as the UI does.
 */

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Livewire\Recruitment\Interviews\Show;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewNotice as Notice;
use App\Models\User;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;           // HR-SYR: schedules interviews
$participantId = 1;   // an active user, invited as interviewer
$inactiveId = 45;     // a user whose profile is inactive
$mirnaId = 346;       // HR of another city: out of scope
$judyId = 3;          // manager: no interview permission, not a participant
$campaignId = 28;     // an existing campaign to hang the throwaway applications on
$requesterId = 34;    // a manager made the requester of that position (direct-open field, rolled back)
$panelOnlyId = 124;   // a manager who is only a participant: neither HR nor the requester
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'array']);
$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $extra = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ').$label.($extra !== '' ? "  [{$extra}]" : '').PHP_EOL;
};
$errors = function (callable $attempt): array {
    try {
        $attempt();

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    } catch (AuthorizationException) {
        return ['403' => ['403']];
    }
};
$sentMail = fn (): int => count(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());

$hr = User::findOrFail($hrId);
[$participant, $inactive, $mirna, $judy] = [User::findOrFail($participantId), User::findOrFail($inactiveId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
[$requester, $panelOnly] = [User::findOrFail($requesterId), User::findOrFail($panelOnlyId)];
$interviews = app(RecruitmentInterviewSchedulingService::class);
$statuses = app(RecruitmentApplicationStatusService::class);
$template = RecruitmentApplication::query()->where('campaign_id', $campaignId)->firstOrFail();
$local = fn (Carbon $at): string => $at->copy()->setTimezone($interviews->timezone())->format('Y-m-d\TH:i');

DB::beginTransaction();
$real = now();

try {
    auth()->login($hr);

    $fresh = function (string $name, string $to = AppStatus::Shortlisted) use ($template, $statuses, $hr): RecruitmentApplication {
        $email = 'task7-check-'.Str::lower(Str::random(10)).'@example.test';
        $applicant = RecruitmentApplicant::query()->forceCreate([
            'full_name' => $name, 'email_normalized' => $email, 'source' => 'public_link',
            'identity_key_hash' => hash_hmac('sha256', $email, (string) config('app.key')), 'city_id' => 2,
        ]);
        $application = RecruitmentApplication::query()->forceCreate([
            'applicant_id' => $applicant->id, 'campaign_id' => $template->campaign_id, 'vacancy_id' => $template->vacancy_id,
            'form_version_id' => $template->form_version_id, 'public_link_id' => $template->public_link_id,
            'submission_key_hash' => hash('sha256', $email), 'email_normalized' => $email, 'status' => AppStatus::Submitted,
            'privacy_accepted_at' => now(), 'submitted_at' => now(),
        ]);
        if ($to === AppStatus::Shortlisted) {
            $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::UnderReview, null);
            $statuses->change($hr, $application->fresh(), AppStatus::UnderReview, AppStatus::Shortlisted, null);
        }

        return $application->fresh();
    };
    $input = fn (array $o = []) => array_merge([
        'type' => 'hr', 'startsAt' => $local($real->copy()->addDays(3)->setTime(10, 0)), 'duration' => 60,
        'locationType' => 'online', 'locationValue' => 'Teams',
        'meetingUrl' => 'https://teams.microsoft.com/l/meetup-join/task7-check',
        'participants' => [$participant->id => 'interviewer'],
    ], $o);
    $expect = fn (RecruitmentInterview $i): array => [$i->status, $i->scheduled_at->toIso8601String()];

    echo 'نطاق التنفيذ — scope'.PHP_EOL;
    $app1 = $fresh('Check One');
    $mailBefore = $sentMail();
    $i1 = $interviews->schedule($hr, $app1, $input())->fresh();
    $check('Schedule an interview for a shortlisted candidate', $i1->status === Status::Scheduled && $i1->application_id === $app1->id);
    $check('Date, time, length, place and meeting link kept', $i1->ends_at->diffInMinutes($i1->scheduled_at, true) == 60 && $i1->location_value === 'Teams' && str_contains((string) $i1->meeting_url, 'task7-check'));
    $check('Participants (interviewer) attached', $i1->participants()->where('user_id', $participant->id)->where('role', 'interviewer')->exists());
    $check('The application moves to "interview scheduled"', $app1->fresh()->status === AppStatus::InterviewScheduled);
    $check('Candidate notice prepared once, nothing sent before the commit', $i1->notices()->where('kind', Notice::KIND_SCHEDULED)->count() === 1 && $sentMail() === $mailBefore);

    echo PHP_EOL.'قواعد العمل — rules'.PHP_EOL;
    $submitted = $fresh('Check Submitted', AppStatus::Submitted);
    $check('A candidate not yet shortlisted cannot be scheduled', isset($errors(fn () => $interviews->schedule($hr, $submitted, $input()))['interview']));
    $check('"Interview scheduled" is never a manual decision', ! in_array(AppStatus::InterviewScheduled, $statuses->targets($fresh('Check Manual')), true));
    $past = $interviews->schedule($hr, $fresh('Check Past'), $input(['startsAt' => $local($real->copy()->subDay()->setTime(10, 0))]))->fresh();
    $check('Any date may be chosen, a past one included; no invitation to a slot already over', $past->scheduled_at->isPast() && $past->notices()->count() === 0);
    $check('An impossible date (2026-02-30) is refused, not rolled over', isset($errors(fn () => $interviews->schedule($hr, $fresh('Check Date'), $input(['startsAt' => '2026-02-30T10:00'])))['startsAt']));
    $check('An inactive participant is refused', isset($errors(fn () => $interviews->schedule($hr, $fresh('Check Inactive'), $input(['participants' => [$inactive->id => 'interviewer']])))['participants']));
    $check('A meeting link that is not http(s) is refused', isset($errors(fn () => $interviews->schedule($hr, $fresh('Check Link'), $input(['meetingUrl' => 'javascript:alert(1)'])))['meetingUrl']));

    // Reschedule: a reason, the history, and the candidate told again.
    [$st, $at] = $expect($i1);
    $noReason = $errors(fn () => $interviews->update($hr, $i1, $st, $at, $input(['startsAt' => $local($real->copy()->addDays(4)->setTime(11, 0))]), ''));
    $i1 = $interviews->update($hr, $i1, $st, $at, $input(['startsAt' => $local($real->copy()->addDays(4)->setTime(11, 0))]), 'The candidate asked for another day.')->fresh();
    $check('Rescheduling needs a reason, and keeps the history', isset($noReason['reason']) && $i1->status === Status::Rescheduled && $i1->reschedules()->count() === 1);
    $check('The candidate is told about the new time', $i1->notices()->where('kind', Notice::KIND_RESCHEDULED)->count() === 1);
    $check('A change made on an old copy of the interview is refused', $errors(fn () => $interviews->update($hr, $i1, $st, $at, $input(), 'Another change on a stale page.')) !== []);

    // Outcome: only once the interview has started.
    [$st, $at] = $expect($i1);
    $early = $errors(fn () => $interviews->recordOutcome($hr, $i1, $st, $at, Status::Completed, null));
    Carbon::setTestNow($i1->scheduled_at->copy()->addMinutes(90));
    $i1 = $interviews->recordOutcome($hr, $i1->fresh(), $st, $at, Status::Completed, 'Held as planned.')->fresh();
    Carbon::setTestNow();
    $check('Held / no-show only after the start; "held" moves the application to "interviewed"', isset($early['outcome']) && $i1->status === Status::Completed && $app1->fresh()->status === AppStatus::Interviewed);

    // Cancel: a reason from the list.
    $app2 = $fresh('Check Two');
    $i2 = $interviews->schedule($hr, $app2, $input())->fresh();
    [$st, $at] = $expect($i2);
    $noCode = $errors(fn () => $interviews->cancel($hr, $i2, $st, $at, '', null));
    $i2 = $interviews->cancel($hr, $i2, $st, $at, $interviews->cancellationReasons()[0], 'Internal note.')->fresh();
    $check('Cancelling needs a reason; the candidate is told', isset($noCode['cancelReason']) && $i2->status === Status::Cancelled && $i2->notices()->where('kind', Notice::KIND_CANCELLED)->count() === 1);

    // Rejecting the application closes its open interviews.
    $app3 = $fresh('Check Three');
    $i3 = $interviews->schedule($hr, $app3, $input())->fresh();
    $statuses->change($hr, $app3->fresh(), AppStatus::InterviewScheduled, AppStatus::Rejected, null, 'qualifications_mismatch', notify: false);
    $check('Rejecting the candidate cancels their open interview', $i3->fresh()->status === Status::Cancelled);

    echo PHP_EOL.'الأمان والصلاحيات — security'.PHP_EOL;
    $check('Scheduling needs the permission (a manager without it is refused)', Gate::forUser($hr)->allows('schedule', [RecruitmentInterview::class, $app2->fresh()])
        && Gate::forUser($judy)->denies('schedule', [RecruitmentInterview::class, $app2->fresh()]));
    $check('Out of scope: HR of another city cannot see the interview', ! RecruitmentInterview::query()->visibleTo($mirna)->whereKey($i2->id)->exists());
    $check('The meeting link only for HR in scope and the participants', Gate::forUser($hr)->allows('viewMeetingLink', $i1) && Gate::forUser($participant)->allows('viewMeetingLink', $i1)
        && Gate::forUser($judy)->denies('viewMeetingLink', $i1) && Gate::forUser($mirna)->denies('viewMeetingLink', $i1));
    $audit = Activity::query()->where('log_name', RecruitmentInterviewSchedulingService::AUDIT_LOG)->whereIn('subject_id', [$i1->id, $i2->id])->pluck('description')->unique()->values()->all();
    $check('Every step is audited', count(array_intersect(['recruitment_interview.scheduled', 'recruitment_interview.rescheduled', 'recruitment_interview.cancelled'], $audit)) === 3, implode(', ', $audit));

    echo PHP_EOL.'Pages HR uses'.PHP_EOL;
    $app4 = $fresh('Check Four');
    $i4 = $interviews->schedule($hr, $app4, $input())->fresh();
    $page = Livewire::actingAs($hr)->test(Show::class, ['interviewId' => $i4->id]);
    $check('Interview page opens for HR, with the meeting link', str_contains($page->html(), 'task7-check'));
    $page->call('beginCancel');
    $opened = $page->get('panelOpen') === true && str_contains($page->html(), 'id="interviewActionModal"');
    $page->call('dismissPanel');
    $check('Cancel opens a popup (Leaves style) and closing it closes it', $opened && $page->get('panelOpen') === false && $page->get('panel') === null);
    $page->call('beginCancel')->set('cancelReason', $interviews->cancellationReasons()[0])->call('cancelInterview');
    $check('Cancelling from the popup cancels the interview', $i4->fresh()->status === Status::Cancelled && $page->get('panelOpen') === false);
    $held = Livewire::actingAs($participant)->test(Show::class, ['interviewId' => $i1->id])->html();
    $check('His held interview: the link as plain text for the record, no join button', str_contains($held, 'task7-check')
        && ! str_contains($held, 'href="https://teams.microsoft.com/l/meetup-join/task7-check"') && str_contains($held, e(__('recruitment-applicant.interviews.meeting_over'))));
    $check('Out of scope is a 404', rescue(fn () => Livewire::actingAs($mirna)->test(Show::class, ['interviewId' => $i1->id])->assertNotFound() !== null, false, false));

    echo PHP_EOL.'The manager who requested the position (read-only viewer)'.PHP_EOL;
    // HR opened it on his behalf: actual_requester_user_id. Rolled back with the rest.
    $template->vacancy->createdFromRequest->forceFill(['actual_requester_user_id' => $requester->id])->save();
    $ours = RecruitmentInterview::query()->whereIn('application_id', RecruitmentApplication::where('email_normalized', 'like', 'task7-check-%')->pluck('id'))->pluck('id');
    $check('He sees every interview of his position, every candidate', Gate::forUser($requester)->allows('viewAny', RecruitmentInterview::class)
        && $ours->diff(RecruitmentInterview::query()->visibleTo($requester)->pluck('id'))->isEmpty(), $ours->count().' interviews');
    $app5 = $fresh('Check Five');
    $i5 = $interviews->schedule($hr, $app5, $input(['participants' => [$panelOnly->id => 'interviewer']]))->fresh();
    $page = Livewire::actingAs($requester)->test(Show::class, ['interviewId' => $i5->id]);
    $html = $page->html();
    $check('His page: meeting link and applicant button, no HR action', str_contains($html, 'task7-check')
        && str_contains($html, route('recruitment.applicants.show', ['applicantId' => $app5->applicant_id])) && ! str_contains($html, 'id="interview-action-cancel"'));
    $check('Every HR action refused, on the page and in the service', rescue(fn () => $page->call('beginCancel')->assertForbidden() !== null, false, false)
        && isset($errors(fn () => $interviews->cancel($requester, $i5, $i5->status, $i5->scheduled_at->toIso8601String(), $interviews->cancellationReasons()[0], null))['403'])
        && isset($errors(fn () => $interviews->schedule($requester, $app5->fresh(), $input()))['403']));
    $check('The applicant button leads to the applicant page, which still needs its own permission (403 today)', Gate::forUser($requester)->allows('viewApplicant', $i5)
        && ! $requester->hasActivePermission('recruitment-applicants.view'));
    $html = Livewire::actingAs($panelOnly)->test(Show::class, ['interviewId' => $i5->id])->html();
    $check('A participant who is not the requester: his interview, the link, no applicant button', str_contains($html, 'task7-check')
        && ! str_contains($html, route('recruitment.applicants.show', ['applicantId' => $app5->applicant_id])));
    $check('Another manager still gets a 404', rescue(fn () => Livewire::actingAs($panelOnly)->test(Show::class, ['interviewId' => $i1->id])->assertNotFound() !== null, false, false));
} finally {
    Carbon::setTestNow();
    DB::rollBack();
}

echo PHP_EOL."RESULT: {$pass} passed, {$fail} failed".PHP_EOL;
echo 'Rolled back: throwaway applications '.RecruitmentApplication::where('email_normalized', 'like', 'task7-check-%')->count().', emails sent '.$sentMail().PHP_EOL;
