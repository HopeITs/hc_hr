<?php

/*
 * Phase 2 Task 8: creates real interview-evaluation data to test by hand.
 * NOTHING here is rolled back. Everything goes through the real services
 * (same rules, locks, history and audit as the UI) — no raw DB writes.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task8-demo.php';"
 *
 * Interviews it creates (all tagged "Task 8 demo" in the location):
 *   A  application 3, HR interview, started 2h ago   — fredy + safir (interviewers), rafi (observer)
 *   B  application 9, technical, started 1h ago       — fredy + safir (interviewers), rafi (observer)
 *   C  application 3, final, tomorrow 10:00           — fredy + safir   (for "before the interview time")
 *   D  application 9, final, cancelled                — fredy           (for "cancelled interview")
 *
 * A and B must be in the past to be evaluated, and scheduling refuses a past
 * slot, so the clock is moved back 4 hours *only while scheduling them*.
 * Every evaluation below happens at the real time.
 *
 * Runs once: if demo interviews already exist it prints their state and
 * stops. Evaluations are never deleted (by design); to start over run
 * task8-demo-cleanup.php, or restore the database backup taken before Task 8.
 * No email is sent: candidate notifications are off and the mailer is "log".
 */

use App\Enums\RecruitmentInterviewStatus as Status;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\User;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;       // lana.abdo    (HR-SYR): schedules, sees the aggregate, corrects, withdraws, closes
$rafiId = 380;    // rafi.louliki (super-admin): observer on A and B — sees his own form AND the HR view
$fredyId = 1;     // fredy.youssef (general-manager, no recruitment permission): interviewer
$safirId = 2;     // safir.salim   (general-manager, no recruitment permission): interviewer
$judyId = 3;      // judy.dadaghlian (manager): on no interview — the non-participant
$appA = 3;        // must be shortlisted / interviewing
$appB = 9;
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$base = rtrim(config('app.url'), '/').'/en';
$tag = 'Task 8 demo';

$hr = User::findOrFail($hrId);
[$rafi, $fredy, $safir, $judy] = [User::findOrFail($rafiId), User::findOrFail($fredyId), User::findOrFail($safirId), User::findOrFail($judyId)];
$scheduling = app(RecruitmentInterviewSchedulingService::class);
$evaluations = app(RecruitmentInterviewEvaluationService::class);
$local = fn (Carbon $c) => $c->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i');
$name = fn (User $u) => $u->profile?->name ?? $u->email;

$ok = fn (string $label, string $detail = '') => print '  OK       '.$label.($detail !== '' ? " — {$detail}" : '').PHP_EOL;
$refused = function (string $label, callable $attempt): void {
    try {
        $attempt();
        echo "  !! NOT REFUSED: {$label}".PHP_EOL;
    } catch (ValidationException $e) {
        echo "  REFUSED  {$label} — ".collect($e->errors())->flatten()->first().PHP_EOL;
    } catch (AuthorizationException) {
        echo "  REFUSED  {$label} — not authorized (403)".PHP_EOL;
    }
};

// "Live" demo data: an open demo interview, or one still holding an active
// evaluation. After task8-demo-cleanup.php there is none, and this runs again.
$existing = RecruitmentInterview::query()
    ->where('location_value', 'like', $tag.'%')
    ->where(fn ($q) => $q->open()->orWhereHas('evaluations', fn ($e) => $e->active()))
    ->orderBy('id')
    ->get();
if ($existing->isNotEmpty()) {
    echo 'Demo interviews already exist — nothing created. Current state:'.PHP_EOL;
    foreach ($existing as $i) {
        echo "  #{$i->id} {$i->location_value} | {$i->status} | evaluations: ".json_encode($evaluations->summary($i)['counts'])
            .' | closed: '.($i->evaluationsClosed() ? 'yes' : 'no')." | {$base}/recruitment/interviews/{$i->id}".PHP_EOL;
    }

    return;
}

foreach ([$appA, $appB] as $id) {
    $status = RecruitmentApplication::findOrFail($id)->status;
    if (! in_array($status, RecruitmentInterviewSchedulingService::SCHEDULABLE, true)) {
        echo "Application {$id} is '{$status}', not shortlisted: pick another one at the top of this file.".PHP_EOL;

        return;
    }
}

auth()->login($hr);
$real = now();
$input = fn (string $type, Carbon $at, string $label, array $participants) => [
    'type' => $type, 'startsAt' => $local($at), 'duration' => 60,
    'locationType' => 'in_person', 'locationValue' => "{$tag} — {$label}", 'participants' => $participants,
];

echo '1. Scheduling the demo interviews'.PHP_EOL;
Carbon::setTestNow($real->copy()->subHours(4));
$A = $scheduling->schedule($hr, RecruitmentApplication::find($appA), $input('hr', $real->copy()->subHours(2)->startOfMinute(), 'A (HR)', [$fredyId => 'interviewer', $safirId => 'interviewer', $rafiId => 'observer']), false);
$B = $scheduling->schedule($hr, RecruitmentApplication::find($appB), $input('technical', $real->copy()->subHour()->startOfMinute(), 'B (technical)', [$fredyId => 'interviewer', $safirId => 'interviewer', $rafiId => 'observer']), false);
$D = $scheduling->schedule($hr, RecruitmentApplication::find($appB)->fresh(), $input('final', $real->copy()->subMinutes(30)->startOfMinute(), 'D (to be cancelled)', [$fredyId => 'interviewer']), false);
Carbon::setTestNow();
$C = $scheduling->schedule($hr, RecruitmentApplication::find($appA)->fresh(), $input('final', $real->copy()->addDay()->setTime(10, 0), 'C (tomorrow)', [$fredyId => 'interviewer', $safirId => 'interviewer']), false);
$scheduling->cancel($hr, $D->fresh(), $D->fresh()->status, $D->scheduled_at->toIso8601String(), 'candidate_declined', 'Demo: candidate declined.', false);
foreach ([$A, $B, $C, $D] as $i) {
    $i->refresh();
    $ok("#{$i->id} {$i->location_value}", "{$i->status}, starts ".$i->scheduled_at->format('Y-m-d H:i'));
}
$decisionsBefore = RecruitmentApplicationDecision::query()->whereIn('application_id', [$appA, $appB])->count();

echo PHP_EOL.'2. A participant submits an evaluation'.PHP_EOL;
$fredyOnA = $evaluations->submit($fredy, $A->fresh(), [
    'recommendation' => 'strong_yes',
    'pros' => 'Clear answers on donor reporting; strong Excel.',
    'cons' => 'Limited Arabic report writing.',
    'notes' => 'Would fit the Homs team.',
], null);
$ok("{$name($fredy)} on A", "strong_yes, version {$fredyOnA->version}");

echo PHP_EOL.'3. A second reviewer, independently — two rows, two opinions'.PHP_EOL;
$safirOnA = $evaluations->submit($safir, $A->fresh(), ['recommendation' => 'maybe', 'cons' => 'Hesitant on budgeting questions.'], null);
$ok("{$name($safir)} on A", "maybe, version {$safirOnA->version}");
$ok('Rows on A', RecruitmentInterviewEvaluation::where('interview_id', $A->id)->count().' (one per reviewer); fredy still says '.$fredyOnA->fresh()->recommendation);

echo PHP_EOL.'4. Refused attempts'.PHP_EOL;
$refused("{$name($fredy)} evaluates C before its time", fn () => $evaluations->submit($fredy, $C->fresh(), ['recommendation' => 'yes'], null));
$refused("{$name($fredy)} evaluates the cancelled interview D", fn () => $evaluations->submit($fredy, $D->fresh(), ['recommendation' => 'yes'], null));
$refused("{$name($judy)} (not a participant) evaluates A", fn () => $evaluations->submit($judy, $A->fresh(), ['recommendation' => 'no'], null));
$refused("{$name($hr)} (HR, not a participant) evaluates A", fn () => $evaluations->submit($hr, $A->fresh(), ['recommendation' => 'no'], null));
$refused("{$name($safir)} corrects {$name($fredy)}'s evaluation", fn () => $evaluations->correct($safir, $fredyOnA->fresh(), 1, ['recommendation' => 'strong_no'], 'I disagree with this'));
$refused("{$name($safir)} withdraws {$name($fredy)}'s evaluation", fn () => $evaluations->withdraw($safir, $fredyOnA->fresh(), 1, 'I disagree with this'));
$refused("{$name($fredy)} saves from a tab opened before his first save", fn () => $evaluations->submit($fredy, $A->fresh(), ['recommendation' => 'no'], null));
$ok("{$name($fredy)}'s evaluation untouched", $fredyOnA->fresh()->recommendation.', version '.$fredyOnA->fresh()->version);

echo PHP_EOL.'5. HR aggregated view of A (what HR sees; reviewers see only their own)'.PHP_EOL;
$ok('Tally', json_encode($evaluations->summary($A->fresh())));
foreach (RecruitmentInterviewEvaluation::where('interview_id', $A->id)->with('reviewer.profile')->get() as $e) {
    $ok('  '.$name($e->reviewer), $e->recommendation);
}
$ok('Can fredy open the aggregate?', $fredy->can('viewEvaluations', $A->fresh()) ? 'YES (wrong)' : 'no');
$ok('Can lana open the aggregate?', $hr->can('viewEvaluations', $A->fresh()) ? 'yes' : 'NO (wrong)');

echo PHP_EOL.'6. HR corrects a mistake, with a reason'.PHP_EOL;
$evaluations->correct($hr, $safirOnA->fresh(), 1, ['recommendation' => 'no', 'cons' => 'Hesitant on budgeting questions.'], 'Safir confirmed he meant "No", not "Maybe".');
$ok("{$name($safir)}'s evaluation on A", $safirOnA->fresh()->recommendation.', version '.$safirOnA->fresh()->version.', history: '.$safirOnA->revisions()->orderBy('version')->pluck('action')->implode(' → '));

echo PHP_EOL.'7. B: two evaluations, then HR withdraws one (audited, not deleted)'.PHP_EOL;
$evaluations->submit($fredy, $B->fresh(), ['recommendation' => 'yes', 'pros' => 'Solid on the technical test.'], null);
$safirOnB = $evaluations->submit($safir, $B->fresh(), ['recommendation' => 'strong_no', 'notes' => 'Evaluated the wrong candidate by mistake.'], null);
$evaluations->withdraw($hr, $safirOnB, 1, 'Safir evaluated the wrong candidate on this interview.');
$safirOnB->refresh();
$ok("{$name($safir)}'s evaluation on B", 'withdrawn at '.$safirOnB->withdrawn_at->format('H:i').', row kept, content kept: '.($safirOnB->notes ? 'yes' : 'no'));
$ok('Tally on B (withdrawn one left out)', json_encode($evaluations->summary($B->fresh())['counts']));
$ok('Audit entry', Activity::where('description', 'recruitment_interview_evaluation.withdrawn')->where('subject_id', $safirOnB->id)->exists() ? 'recruitment_interview_evaluation.withdrawn logged' : 'MISSING');
$refused("{$name($safir)} re-submits after the withdrawal", fn () => $evaluations->submit($safir, $B->fresh(), ['recommendation' => 'yes'], 2));

echo PHP_EOL.'8. A is marked as held, then HR closes evaluation'.PHP_EOL;
$scheduling->recordOutcome($hr, $A->fresh(), $A->fresh()->status, $A->scheduled_at->toIso8601String(), Status::Completed, null);
$evaluations->close($hr, $A->fresh(), Status::Completed);
$ok('A', $A->fresh()->status.', evaluation closed at '.$A->fresh()->evaluations_closed_at->format('H:i'));
$refused("{$name($fredy)} edits his evaluation after closing", fn () => $evaluations->submit($fredy, $A->fresh(), ['recommendation' => 'yes'], 1));
$evaluations->correct($hr, $fredyOnA->fresh(), 1, ['recommendation' => 'yes', 'pros' => 'Clear answers on donor reporting; strong Excel.', 'cons' => 'Limited Arabic report writing.', 'notes' => 'Would fit the Homs team.'], 'Fredy asked to soften it after the panel discussion.');
$ok('HR corrects after closing', "{$name($fredy)}'s now ".$fredyOnA->fresh()->recommendation.' (history: '.$fredyOnA->revisions()->orderBy('version')->pluck('action')->implode(' → ').')');

echo PHP_EOL.'9. The tally stays separate from the hiring decision'.PHP_EOL;
foreach ([$appA, $appB] as $id) {
    $application = RecruitmentApplication::find($id);
    $ok("Application {$id}", "status {$application->status} (set by the interviews, never by a recommendation)");
}
$ok('Decisions created by the evaluation steps', (string) (RecruitmentApplicationDecision::query()->whereIn('application_id', [$appA, $appB])->count() - $decisionsBefore).' (only the scheduling / completion moves, none from recommendations)');

echo PHP_EOL.'Open these pages:'.PHP_EOL
    ."  Interview A (closed, aggregate + history): {$base}/recruitment/interviews/{$A->id}".PHP_EOL
    ."  Interview B (open, one withdrawn):         {$base}/recruitment/interviews/{$B->id}".PHP_EOL
    ."  Interview C (not started yet):             {$base}/recruitment/interviews/{$C->id}".PHP_EOL
    ."  Interview D (cancelled):                   {$base}/recruitment/interviews/{$D->id}".PHP_EOL
    ."  Applicant profile, application {$appA}:    {$base}/recruitment/applicants/".RecruitmentApplication::find($appA)->applicant_id.PHP_EOL
    ."  Applicant profile, application {$appB}:    {$base}/recruitment/applicants/".RecruitmentApplication::find($appB)->applicant_id.PHP_EOL
    ."  Activity log (audit):                      {$base}/activity-log".PHP_EOL;
