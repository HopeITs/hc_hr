<?php

/*
 * Phase 2 Task 9: creates real job-offer data to test by hand.
 * NOTHING here is rolled back. Everything goes through the real services
 * (same rules, locks, snapshots and audit as the UI) — no raw DB writes.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task9-demo.php';"
 *
 * One offer per application (the policy), seven scenarios:
 *   A  app 10 (vacancy 22) : sent -> accepted -> handed over for conversion        [Accepted]
 *   F  app 9  (vacancy 22) : same seat as A -> acceptance refused, vacancy full    [left Sent]
 *   B  app 7               : sent -> refused -> revised (new salary) -> v2 sent     [left Sent]
 *   C  app 8               : draft, salary corrected in the draft                  [left Draft]
 *   D  app 11              : answer date passed -> the expiry sweep expires it      [Expired]
 *   G  app 61              : answer date passed -> a late answer is refused         [Expired]
 *   E  app 14              : draft, candidate rejected before sending -> cancelled  [Cancelled]
 *
 * D and G must have been sent in the past, and sending refuses a past answer
 * date, so the clock is moved back three days only while those two are
 * selected, created and sent. Everything else happens at the real time.
 *
 * Runs once: if any of these applications already has an offer it prints
 * their state and stops (an application only ever has one offer; a change is
 * a revision). To start over, restore the database backup taken before the
 * demo. No email is sent: the offer workflow emails nobody.
 */

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Enums\SalaryPer;
use App\Models\ContractType;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;      // lana.abdo  (HR-SYR): writes offers, sees salaries
$rafiId = 380;   // rafi.louliki (super-admin): follows offers, salary-blind by design
$mirnaId = 346;  // mirna.badra (HR-DMS): HR of another city — out of scope for these Aleppo vacancies
$judyId = 3;     // judy.dadaghlian (manager): no offer permission at all
$apps = ['A' => 10, 'F' => 9, 'B' => 7, 'C' => 8, 'D' => 11, 'G' => 61, 'E' => 14];
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$base = rtrim(config('app.url'), '/').'/en';

$hr = User::findOrFail($hrId);
[$rafi, $mirna, $judy] = [User::findOrFail($rafiId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
$offers = app(RecruitmentJobOfferService::class);
$statuses = app(RecruitmentApplicationStatusService::class);
$contractTypeId = (int) ContractType::query()->recruitmentOptions()->orderBy('id')->value('id'); // trial or service, as on the request

$ok = fn (string $label, string $detail = '') => print '  OK       '.$label.($detail !== '' ? " — {$detail}" : '').PHP_EOL;
$refused = function (string $label, callable $attempt): void {
    try {
        $attempt();
        echo "  !! NOT REFUSED: {$label}".PHP_EOL;
    } catch (ValidationException $e) {
        echo "  REFUSED  {$label} — ".collect($e->errors())->flatten()->first().PHP_EOL;
    } catch (AuthorizationException) {
        echo "  REFUSED  {$label} — not authorized (403)".PHP_EOL;
    } catch (LogicException $e) {
        echo "  REFUSED  {$label} — ".$e->getMessage().PHP_EOL;
    }
};

// ---- Guard: once only -------------------------------------------------
$existing = Offer::query()->whereIn('application_id', $apps)->with('currentVersion')->orderBy('id')->get();
if ($existing->isNotEmpty()) {
    echo 'Demo offers already exist — nothing created. Current state:'.PHP_EOL;
    foreach ($existing as $offer) {
        $label = array_search($offer->application_id, $apps, true);
        echo "  {$label}  offer #{$offer->id} | app {$offer->application_id} (".RecruitmentApplication::find($offer->application_id)->status.") | {$offer->status} | v{$offer->currentVersion?->version_no} | {$base}/recruitment/offers/{$offer->id}".PHP_EOL;
    }

    return;
}

foreach ($apps as $label => $id) {
    $status = RecruitmentApplication::findOrFail($id)->status;
    if (! in_array($status, [AppStatus::Submitted, AppStatus::Interviewed, AppStatus::Selected], true)) {
        echo "Application {$id} ({$label}) is '{$status}': pick another one at the top of this file.".PHP_EOL;

        return;
    }
}

auth()->login($hr);
$real = now();
$startDate = $real->copy()->addMonth()->startOfMonth()->toDateString();
$terms = fn (array $overrides = []) => array_merge([
    'contractTypeId' => $contractTypeId,
    'probationDays' => 90,
    'salary' => '1200',
    'currency' => Currency::USD,
    'salaryPer' => SalaryPer::Month,
    'startDate' => $startDate,
    'endDate' => $real->copy()->addMonth()->startOfMonth()->addMonths(11)->toDateString(),
    'weeklyHours' => 40,
    'employmentType' => EmploymentType::FullTime,
    'place' => ContractPlace::Onsite,
    'notes' => 'Transport allowance included.',
], $overrides);

$select = function (int $id) use ($hr, $statuses): RecruitmentApplication {
    $application = RecruitmentApplication::findOrFail($id);
    if ($application->status === AppStatus::Submitted) {
        $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        $application = $application->fresh();
    }
    if (in_array($application->status, [AppStatus::Shortlisted, AppStatus::Interviewed], true)) {
        $statuses->change($hr, $application, $application->status, AppStatus::Selected, null);
    }

    return $application->fresh();
};
$create = fn (int $id, array $overrides = []) => $offers->create($hr, RecruitmentApplication::findOrFail($id), $terms($overrides))->fresh();
$send = fn (Offer $o, ?string $answerBy = null) => $offers->send($hr, $o->fresh(), $o->fresh()->current_version_id, $answerBy)->fresh();
$respond = fn (Offer $o, string $answer, ?string $note = null) => $offers->recordResponse($hr, $o->fresh(), $o->fresh()->current_version_id, $answer, $note)->fresh();
$revise = fn (Offer $o, array $overrides, string $reason) => $offers->revise($hr, $o->fresh(), $o->fresh()->current_version_id, $terms($overrides), $reason)->fresh();
$vacancyState = fn () => Vacancy::query()->whereIn('id', RecruitmentApplication::whereIn('id', $apps)->pluck('vacancy_id'))->orderBy('id')
    ->get()->mapWithKeys(fn (Vacancy $v) => [$v->id => "{$v->status}, filled {$v->filled_count}/{$v->needed_count}"])->all();
$vacanciesBefore = $vacancyState();

echo '1. Candidates selected (Task 6 decision panel rules, through the decision service)'.PHP_EOL;
foreach (['A', 'F', 'B', 'C', 'E'] as $label) {
    $ok("{$label}: application {$apps[$label]}", $select($apps[$label])->status);
}

echo PHP_EOL.'2. Who may create an offer'.PHP_EOL;
$refused('Offer for application 3 (interview scheduled, not selected)', fn () => $offers->create($hr, RecruitmentApplication::findOrFail(3), $terms()));
$refused("{$judy->email} (no offer permission) creates an offer", fn () => $offers->create($judy, RecruitmentApplication::findOrFail($apps['A']), $terms()));
$refused("{$rafi->email} (super-admin, salary-blind) creates an offer", fn () => $offers->create($rafi, RecruitmentApplication::findOrFail($apps['A']), $terms()));
$refused("{$mirna->email} (HR of another city) creates an offer", fn () => $offers->create($mirna, RecruitmentApplication::findOrFail($apps['A']), $terms()));
$refused('Salary 0', fn () => $create($apps['A'], ['salary' => '0']));
$refused('Start date 2026-02-30', fn () => $create($apps['A'], ['startDate' => '2026-02-30']));
$refused('Start date in the past', fn () => $create($apps['A'], ['startDate' => $real->copy()->subDay()->toDateString()]));

echo PHP_EOL.'3. A and F — two offers out for the one seat of vacancy '.RecruitmentApplication::find($apps['A'])->vacancy_id.PHP_EOL;
$A = $create($apps['A'], ['salary' => '1250']);
$ok("A offer #{$A->id} created", "{$A->status}, v1, application still ".RecruitmentApplication::find($apps['A'])->status);
$refused('A second offer for the same application', fn () => $create($apps['A']));
$A = $send($A);
$ok('A sent', "{$A->status}, answer by ".$A->expires_at->toDateString().', application '.RecruitmentApplication::find($apps['A'])->status);
$refused('Changing the terms of a sent offer in place', fn () => $offers->updateDraft($hr, $A, $A->current_version_id, $terms(['salary' => '1300'])));
$refused('Rewriting the sent version directly (snapshot)', fn () => $A->currentVersion->forceFill(['salary_amount' => '9999'])->save());
$F = $send($create($apps['F'], ['salary' => '1150']));
$ok("F offer #{$F->id} sent", 'a sent offer does not hold the seat, so both can be out: seats left '.$offers->seatsLeft(Vacancy::find($F->vacancy_id)));

echo PHP_EOL.'4. A accepted — handed over for conversion, once'.PHP_EOL;
$A = $respond($A, Status::Accepted, 'Accepted by phone.');
$conversion = RecruitmentHiringConversion::query()->where('job_offer_id', $A->id)->first();
$ok('A accepted', 'application '.RecruitmentApplication::find($apps['A'])->status.", conversion #{$conversion?->id} {$conversion?->status} (offer version {$conversion?->job_offer_version_id})");
$refused('Accepting A a second time', fn () => $respond($A, Status::Accepted));
$refused('Cancelling an accepted offer', fn () => $offers->cancel($hr, $A->fresh(), $A->current_version_id, 'created_in_error', null));
$refused('Deleting an offer', fn () => $A->fresh()->delete());

echo PHP_EOL.'5. F — the vacancy filled (by A) before F was accepted'.PHP_EOL;
$ok('Seats left on vacancy '.$F->vacancy_id, (string) $offers->seatsLeft(Vacancy::find($F->vacancy_id), $F));
$refused('Recording that F accepted', fn () => $respond($F, Status::Accepted));
$ok('F', 'still '.$F->fresh()->status.' — HR records a refusal or cancels it; the vacancy stays as it is');

echo PHP_EOL.'6. B — refused, then a revised offer (a new version, the old one kept)'.PHP_EOL;
$B = $send($create($apps['B'], ['salary' => '1100', 'contractTypeId' => $contractTypeId]));
$B = $respond($B, Status::Rejected, 'Asked for a higher salary.');
$ok("B offer #{$B->id} refused", 'application '.RecruitmentApplication::find($apps['B'])->status.' — the vacancy is not touched');
$refused('Revising without a reason', fn () => $offers->revise($hr, $B, $B->current_version_id, $terms(['salary' => '1300']), 'short'));
$B = $send($revise($B, ['salary' => '1300'], 'Candidate asked for a higher salary; approved by the programme manager.'));
foreach ($B->versions()->orderBy('version_no')->get() as $version) {
    $ok("B v{$version->version_no}", "{$version->status}, salary {$version->salary_amount}".($version->revision_reason ? ", reason: {$version->revision_reason}" : ''));
}
$ok('B', "{$B->status} — application ".RecruitmentApplication::find($apps['B'])->status.'; manual decision moves while it is out: ['.implode(',', $statuses->targets(RecruitmentApplication::find($apps['B']))).']');

echo PHP_EOL.'7. C — a draft whose salary HR corrects before sending'.PHP_EOL;
$C = $create($apps['C'], ['salary' => '1000']);
$C = $offers->updateDraft($hr, $C, $C->current_version_id, $terms(['salary' => '1050']))->fresh();
$audit = Activity::query()->where('description', 'recruitment_job_offer.draft_updated')->where('subject_id', $C->id)->latest('id')->first();
$ok("C offer #{$C->id}", "{$C->status}, v{$C->currentVersion->version_no}, salary now {$C->currentVersion->salary_amount}; audit changed_fields=".json_encode($audit?->properties['changed_fields']).' (no amount in the log)');

echo PHP_EOL.'8. D and G — sent three days ago with yesterday as the answer date'.PHP_EOL;
Carbon::setTestNow($real->copy()->subDays(3));
foreach (['D', 'G'] as $label) {
    $select($apps[$label]);
    ${$label} = $send($create($apps[$label], ['salary' => '1180']), $real->copy()->subDay()->toDateString());
}
Carbon::setTestNow();
$ok("D offer #{$D->id} / G offer #{$G->id}", 'sent '.$D->sent_at->toDateString().', answer by '.$D->expires_at->toDateString());
$refused('G: the candidate accepts after the answer date (before the sweep ran)', fn () => $respond($G, Status::Accepted));
$ok('G', $G->fresh()->status.', application '.RecruitmentApplication::find($apps['G'])->status.', no conversion: '.(RecruitmentHiringConversion::where('job_offer_id', $G->id)->exists() ? 'WRONG' : 'right'));
$ok('Expiry sweep (= php artisan recruitment:expire-offers)', $offers->expireDue().' offer(s) expired');
$ok('D', $D->fresh()->status.', application '.RecruitmentApplication::find($apps['D'])->status.' — HR can revise it or choose another candidate');

echo PHP_EOL.'9. E — selected, then rejected before the offer was sent'.PHP_EOL;
$E = $create($apps['E']);
$statuses->change($hr, RecruitmentApplication::find($apps['E']), AppStatus::Selected, AppStatus::Rejected, 'References came back negative before the offer went out.', 'stronger_candidates');
$E->refresh();
$ok("E offer #{$E->id}", "{$E->status} automatically (reason: {$E->cancellation_reason}), application ".RecruitmentApplication::find($apps['E'])->status);

echo PHP_EOL.'10. Salary and scope'.PHP_EOL;
$raw = (string) DB::table('recruitment_job_offer_versions')->where('id', $A->current_version_id)->value('salary_amount');
$ok('A salary in the database', 'ciphertext of '.strlen($raw).' chars, contains "1250": '.(str_contains($raw, '1250') ? 'YES (wrong)' : 'no'));
foreach ([$hr, $rafi, $mirna, $judy] as $user) {
    $ok(str_pad($user->email, 30), 'view: '.($user->can('view', $A->fresh()) ? 'yes' : 'no').' | salary: '.($user->can('viewSalary', $A->fresh()) ? 'yes' : 'no').' | write: '.($user->can('manage', $A->fresh()) ? 'yes' : 'no'));
}

echo PHP_EOL.'11. The vacancy is never touched by an offer'.PHP_EOL;
$after = $vacancyState();
foreach ($vacanciesBefore as $id => $before) {
    $ok("Vacancy {$id}", ($before === $after[$id] ? 'unchanged' : 'CHANGED')." ({$after[$id]}), seats left ".$offers->seatsLeft(Vacancy::find($id)));
}

echo PHP_EOL.'12. The trail'.PHP_EOL;
$ok('Application '.$apps['B'].' decisions', RecruitmentApplicationDecision::where('application_id', $apps['B'])->orderBy('id')->get()->map(fn ($d) => $d->to_status)->implode(' -> '));
$ok('Audit entries for demo offers', Activity::query()->where('log_name', RecruitmentJobOfferService::AUDIT_LOG)
    ->whereIn('subject_id', Offer::whereIn('application_id', $apps)->pluck('id'))->pluck('description')
    ->map(fn ($d) => str_replace('recruitment_job_offer.', '', $d))->countBy()->map(fn ($n, $e) => "{$e}×{$n}")->implode(', '));

echo PHP_EOL.'Open these pages:'.PHP_EOL."  Offers list: {$base}/recruitment/offers".PHP_EOL;
foreach (['A', 'F', 'B', 'C', 'D', 'G', 'E'] as $label) {
    $offer = ${$label}->fresh();
    echo "  {$label}  ".str_pad($offer->status, 9)." {$base}/recruitment/offers/{$offer->id}   (applicant profile: {$base}/recruitment/applicants/".$offer->application->applicant_id.')'.PHP_EOL;
}
echo "  Activity log: {$base}/activity-log".PHP_EOL;
