<?php

/*
 * Phase 2 Task 8 (interview evaluations) — final check on the real MySQL
 * database. Every line prints PASS / FAIL. Everything runs in one
 * transaction that is ROLLED BACK at the end: nothing is left behind.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task8-final-check.php';"
 *
 * It builds a throwaway application and interview on an existing campaign,
 * moves the clock to the interview's start, and drives the evaluation
 * service, the policies and the Livewire pages as the UI does.
 */

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Livewire\Recruitment\Interviews\EvaluationForm;
use App\Livewire\Recruitment\Interviews\EvaluationSummary;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterviewEvaluation as Evaluation;
use App\Models\User;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewEvaluationService;
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
$hrId = 11;           // HR-SYR: sees, corrects and closes evaluations
$interviewerId = 1;   // an active user, invited as interviewer
$observerId = 124;    // a manager invited as observer (also a reviewer role); not HR, not the requester
$requesterId = 34;    // a manager made the requester of the position (direct-open field, rolled back)
$mirnaId = 346;       // HR of another city: out of scope
$judyId = 3;          // manager: not a participant, no evaluation permission
$campaignId = 28;     // an existing campaign to hang the throwaway application on
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

$hr = User::findOrFail($hrId);
[$interviewer, $observer, $mirna, $judy] = [User::findOrFail($interviewerId), User::findOrFail($observerId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
$requester = User::findOrFail($requesterId);
$scheduling = app(RecruitmentInterviewSchedulingService::class);
$evaluations = app(RecruitmentInterviewEvaluationService::class);
$statuses = app(RecruitmentApplicationStatusService::class);
$template = RecruitmentApplication::query()->where('campaign_id', $campaignId)->firstOrFail();

DB::beginTransaction();
$real = now();

try {
    auth()->login($hr);

    $email = 'task8-check-'.Str::lower(Str::random(10)).'@example.test';
    $applicant = RecruitmentApplicant::query()->forceCreate([
        'full_name' => 'Check Evaluated', 'email_normalized' => $email, 'source' => 'public_link',
        'identity_key_hash' => hash_hmac('sha256', $email, (string) config('app.key')), 'city_id' => 2,
    ]);
    $application = RecruitmentApplication::query()->forceCreate([
        'applicant_id' => $applicant->id, 'campaign_id' => $template->campaign_id, 'vacancy_id' => $template->vacancy_id,
        'form_version_id' => $template->form_version_id, 'public_link_id' => $template->public_link_id,
        'submission_key_hash' => hash('sha256', $email), 'email_normalized' => $email, 'status' => AppStatus::Submitted,
        'privacy_accepted_at' => now(), 'submitted_at' => now(),
    ]);
    $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::UnderReview, null);
    $statuses->change($hr, $application->fresh(), AppStatus::UnderReview, AppStatus::Shortlisted, null);

    $starts = $real->copy()->addDay()->setTime(10, 0);
    $interview = $scheduling->schedule($hr, $application->fresh(), [
        'type' => 'final', 'startsAt' => $starts->copy()->setTimezone($scheduling->timezone())->format('Y-m-d\TH:i'), 'duration' => 60,
        'locationType' => 'in_person', 'locationValue' => 'Room 1',
        'participants' => [$interviewer->id => 'interviewer', $observer->id => 'observer'],
    ], false)->fresh();

    echo 'قواعد العمل — before the interview'.PHP_EOL;
    $early = $errors(fn () => $evaluations->submit($interviewer, $interview, ['recommendation' => Recommendation::Yes], null));
    $check('No evaluation before the interview', $early !== [] && $evaluations->closedReason($interview) === 'not_held');

    // Its time has come, but evaluation opens only when HR records it as held.
    Carbon::setTestNow($starts->copy()->addMinutes(70));
    $interview = $interview->fresh();
    $check('Not at its start time either: evaluation waits until HR records it as held', $evaluations->closedReason($interview) === 'not_held'
        && $errors(fn () => $evaluations->submit($interviewer, $interview, ['recommendation' => Recommendation::Yes], null)) !== []);
    $interview = $scheduling->recordOutcome($hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), Status::Completed, null)->fresh();
    $check('Held: evaluation is open', $evaluations->closedReason($interview) === null);

    echo PHP_EOL.'نطاق التنفيذ — scope'.PHP_EOL;
    $statusBefore = $application->fresh()->status;
    $e1 = $evaluations->submit($interviewer, $interview, ['recommendation' => Recommendation::Yes, 'pros' => 'CHECK-PROS', 'cons' => 'CHECK-CONS'], null);
    $e2 = $evaluations->submit($observer, $interview, ['recommendation' => Recommendation::Maybe, 'notes' => 'CHECK-NOTES'], null);
    $check('An interviewer evaluates: recommendation, strengths, weaknesses', $e1->recommendation === Recommendation::Yes && $e1->pros === 'CHECK-PROS' && $e1->version === 1);
    $check('An observer evaluates too (reviewer role)', $e2->recommendation === Recommendation::Maybe);
    $check('A recommendation is required', isset($errors(fn () => $evaluations->submit($interviewer, $interview, ['pros' => 'no recommendation'], 1))['recommendation']));
    $check('Only yes / maybe / no', isset($errors(fn () => $evaluations->submit($interviewer, $interview, ['recommendation' => 'strong_yes'], 1))['recommendation']));
    $e1 = $evaluations->submit($interviewer, $interview, ['recommendation' => Recommendation::No, 'pros' => 'CHECK-PROS'], 1);
    $check('One evaluation per reviewer: a second save edits it (version 2)', $e1->version === 2 && Evaluation::where('interview_id', $interview->id)->where('reviewer_user_id', $interviewer->id)->count() === 1);
    $check('A save on an old copy is refused', $errors(fn () => $evaluations->submit($interviewer, $interview, ['recommendation' => Recommendation::Yes], 1)) !== []);
    $summary = $evaluations->summary($interview);
    $check('HR summary counts the recommendations', $summary['total'] === 2 && ($summary['counts'][Recommendation::No] ?? 0) === 1 && ($summary['counts'][Recommendation::Maybe] ?? 0) === 1, json_encode($summary['counts']));
    $check('Advisory only: the application does not move', $application->fresh()->status === $statusBefore);

    echo PHP_EOL.'HR corrections'.PHP_EOL;
    $check('Correcting needs a reason', $errors(fn () => $evaluations->correct($hr, $e1->fresh(), 2, ['recommendation' => Recommendation::Yes], '')) !== []);
    $e1 = $evaluations->correct($hr, $e1->fresh(), 2, ['recommendation' => Recommendation::Yes, 'pros' => 'CHECK-PROS'], 'The reviewer ticked the wrong box.');
    $check('HR corrects a mistake; the history keeps every version', $e1->recommendation === Recommendation::Yes
        && $e1->revisions()->orderBy('version')->pluck('action')->all() === [Evaluation::ACTION_CREATED, Evaluation::ACTION_UPDATED, Evaluation::ACTION_CORRECTED]);
    $e2 = $evaluations->withdraw($hr, $e2->fresh(), 1, 'Observer evaluated the wrong candidate.');
    $check('HR withdraws an evaluation with a reason; it leaves the count', $e2->isWithdrawn() && $evaluations->summary($interview->fresh())['total'] === 1);

    echo PHP_EOL.'الأمان والصلاحيات — security'.PHP_EOL;
    $check('Someone not on the interview cannot evaluate', isset($errors(fn () => $evaluations->submit($judy, $interview, ['recommendation' => Recommendation::Yes], null))['403']));
    $check('Only HR in scope sees the evaluations', Gate::forUser($hr)->allows('viewEvaluations', $interview)
        && Gate::forUser($judy)->denies('viewEvaluations', $interview) && Gate::forUser($mirna)->denies('viewEvaluations', $interview));
    $check('Only HR with the permission corrects', Gate::forUser($hr)->allows('correct', $e1) && Gate::forUser($interviewer)->denies('correct', $e1));
    $form = Livewire::actingAs($interviewer)->test(EvaluationForm::class, ['interviewId' => $interview->id])->html();
    $check("A reviewer sees their own evaluation, not another reviewer's", str_contains($form, 'CHECK-PROS') && ! str_contains($form, 'CHECK-NOTES'));
    $audit = Activity::query()->where('log_name', RecruitmentInterviewEvaluationService::AUDIT_LOG)->where('properties->interview_id', $interview->id)->pluck('description')->unique()->values()->all();
    $check('Every save, correction and withdrawal is audited', count($audit) >= 3, implode(', ', $audit));

    echo PHP_EOL.'Closing evaluation'.PHP_EOL;
    $held = $interview->fresh();
    $evaluations->close($hr, $held, $held->status);
    $check('Closed after the interview: reviewers can no longer save', $evaluations->closedReason($held->fresh()) === 'closed'
        && $errors(fn () => $evaluations->submit($interviewer, $held->fresh(), ['recommendation' => Recommendation::Maybe], 3)) !== []);

    echo PHP_EOL.'The requester of the position'.PHP_EOL;
    // HR opened it on his behalf: actual_requester_user_id. Rolled back with the rest.
    $template->vacancy->createdFromRequest->forceFill(['actual_requester_user_id' => $requester->id])->save();
    $interview = $interview->fresh();
    $summaryHtml = Livewire::actingAs($requester)->test(EvaluationSummary::class, ['interviewId' => $interview->id])->html();
    $check('Requester (not on the panel): every evaluation and the summary, read-only', str_contains($summaryHtml, 'CHECK-PROS') && str_contains($summaryHtml, 'CHECK-NOTES')
        && Gate::forUser($requester)->denies('correct', $e1) && Gate::forUser($requester)->denies('closeEvaluations', $interview)
        && rescue(fn () => Livewire::actingAs($requester)->test(EvaluationSummary::class, ['interviewId' => $interview->id])->call('beginCorrect', $e1->id)->assertForbidden() !== null, false, false));
    $check('Requester (not on the panel): no evaluation form, no save', Gate::forUser($requester)->denies('evaluate', $interview)
        && isset($errors(fn () => $evaluations->submit($requester, $interview, ['recommendation' => Recommendation::Yes], null))['403']));
    $requestedBy = (int) $template->vacancy->createdFromRequest->requester_user_id;
    $check('The request\'s own requester on the panel: both rights — every evaluation, and his own form only', $requestedBy !== $interviewer->id
        || (Gate::forUser($interviewer)->allows('viewEvaluations', $interview) && Gate::forUser($interviewer)->allows('update', $e1) && Gate::forUser($interviewer)->denies('update', $e2)),
        $requestedBy === $interviewer->id ? 'user '.$requestedBy.' raised the request and sits on the panel' : 'not applicable on this database');
    $check('A participant who is not the requester: no summary', Gate::forUser($observer)->denies('viewEvaluations', $interview)
        && rescue(fn () => Livewire::actingAs($observer)->test(EvaluationSummary::class, ['interviewId' => $interview->id])->assertForbidden() !== null, false, false));

    echo PHP_EOL.'Pages HR uses'.PHP_EOL;
    $page = Livewire::actingAs($hr)->test(EvaluationSummary::class, ['interviewId' => $interview->id]);
    $html = $page->html();
    $check('The summary page shows both evaluations and the corrected badge', str_contains($html, 'CHECK-PROS') && str_contains($html, 'CHECK-NOTES')
        && str_contains($html, e(__('recruitment-applicant.evaluations.corrected_badge'))));
    $page->call('beginCorrect', $e1->id);
    $opened = $page->get('panelOpen') === true && str_contains($page->html(), 'id="evaluationActionModal"');
    $page->call('dismissPanel');
    $check('Correct opens a popup (Leaves style) and closing it closes it', $opened && $page->get('panelOpen') === false && $page->get('panel') === null);
    $page->call('beginCorrect', $e1->id)->set('recommendation', Recommendation::Maybe)->set('reason', 'Corrected again from the popup.')->call('saveCorrection');
    $check('Correcting from the popup saves (even after closing)', $e1->fresh()->recommendation === Recommendation::Maybe && $page->get('panelOpen') === false);
} finally {
    Carbon::setTestNow();
    DB::rollBack();
}

echo PHP_EOL."RESULT: {$pass} passed, {$fail} failed".PHP_EOL;
echo 'Rolled back: throwaway applications '.RecruitmentApplication::where('email_normalized', 'like', 'task8-check-%')->count().PHP_EOL;
