<?php

/*
 * Phase 2 Task 9 — final requirements check on the real MySQL database.
 * Every line of the spec gets a PASS / FAIL. Everything runs in one
 * transaction that is ROLLED BACK at the end: nothing is left behind.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task9-final-check.php';"
 *
 * It builds its own throwaway applications on an existing open campaign
 * (every real application already has its one offer), moves them to
 * "selected" through the decision service, and drives the offer service,
 * the policies and the Livewire pages exactly as the UI does.
 */

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Enums\SalaryPer;
use App\Livewire\Recruitment\Offers\Form;
use App\Livewire\Recruitment\Offers\OfferTable;
use App\Livewire\Recruitment\Offers\Revise;
use App\Livewire\Recruitment\Offers\Show;
use App\Models\ContractType;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\RecruitmentJobOfferVersion as Version;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;       // HR-SYR: writes offers, sees salaries
$rafiId = 380;    // super-admin: salary-blind by design
$mirnaId = 346;   // HR of another city: out of scope
$judyId = 3;      // manager: no offer permission
$campaignId = 28; // an open campaign with a free seat (vacancy 26)
$otherCampaignId = 25; // a second one, for the exceptional cases (vacancy 23)
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $extra = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ').$label.($extra !== '' ? "  [{$extra}]" : '').PHP_EOL;
};
$errorOf = function (callable $attempt): string {
    try {
        $attempt();

        return '';
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    } catch (AuthorizationException) {
        return '403';
    } catch (LogicException $e) {
        return 'logic: '.$e->getMessage();
    } catch (UniqueConstraintViolationException) {
        return 'unique';
    }
};
$msg = fn (string $key) => __('recruitment-applicant.offers.errors.'.$key);

$hr = User::findOrFail($hrId);
[$rafi, $mirna, $judy] = [User::findOrFail($rafiId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
$offers = app(RecruitmentJobOfferService::class);
$statuses = app(RecruitmentApplicationStatusService::class);
$template = RecruitmentApplication::query()->where('campaign_id', $campaignId)->firstOrFail();
$otherTemplate = RecruitmentApplication::query()->where('campaign_id', $otherCampaignId)->firstOrFail();
$contractTypeId = (int) ContractType::query()->recruitmentOptions()->orderBy('id')->value('id'); // trial or service, as on the request

DB::beginTransaction();
$real = now();

try {

    // Offers accepted in the app keep their seats on the second vacancy; the
    // checks get their own room on top of them (rolled back with the rest).
    $heldOnOther = Offer::query()->where('vacancy_id', $otherTemplate->vacancy_id)->where('status', 'accepted')->count();
    Vacancy::query()->whereKey($otherTemplate->vacancy_id)->increment('needed_count', $heldOnOther);
    auth()->login($hr);

    // A fresh application on the campaign's vacancy, moved to $to.
    $fresh = function (string $name, string $to = AppStatus::Selected, ?RecruitmentApplication $on = null) use ($template, $statuses, $hr): RecruitmentApplication {
        $on ??= $template;
        $email = 'task9-check-'.Str::lower(Str::random(10)).'@example.test';
        $applicant = RecruitmentApplicant::query()->forceCreate([
            'full_name' => $name, 'email_normalized' => $email, 'source' => 'public_link',
            'identity_key_hash' => hash_hmac('sha256', $email, (string) config('app.key')), 'city_id' => 2,
        ]);
        $application = RecruitmentApplication::query()->forceCreate([
            'applicant_id' => $applicant->id, 'campaign_id' => $on->campaign_id, 'vacancy_id' => $on->vacancy_id,
            'form_version_id' => $on->form_version_id, 'public_link_id' => $on->public_link_id,
            'submission_key_hash' => hash('sha256', $email), 'email_normalized' => $email, 'status' => AppStatus::Submitted,
            'privacy_accepted_at' => now(), 'submitted_at' => now(),
        ]);
        if ($to === AppStatus::Submitted) {
            return $application;
        }
        $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        if ($to === AppStatus::Selected) {
            $statuses->change($hr, $application->fresh(), AppStatus::Shortlisted, AppStatus::Selected, null);
        }

        return $application->fresh();
    };
    $terms = fn (array $o = []) => array_merge([
        'contractTypeId' => $contractTypeId, 'probationDays' => 90, 'salary' => '1500', 'currency' => Currency::USD, 'salaryPer' => SalaryPer::Month,
        'startDate' => $real->copy()->addMonth()->startOfMonth()->toDateString(), 'endDate' => $real->copy()->addYear()->toDateString(),
        'weeklyHours' => 40, 'employmentType' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite,
        'notes' => 'Transport allowance included.',
    ], $o);
    $create = fn (RecruitmentApplication $a, array $o = []) => $offers->create($hr, $a, $terms($o))->fresh();
    $send = fn (Offer $o, ?string $by = null) => $offers->send($hr, $o->fresh(), $o->fresh()->current_version_id, $by)->fresh();
    $respond = fn (Offer $o, string $r, ?string $n = null) => $offers->recordResponse($hr, $o->fresh(), $o->fresh()->current_version_id, $r, $n)->fresh();
    $revise = fn (Offer $o, array $t, string $reason = 'Revised after talking to the candidate.') => $offers->revise($hr, $o->fresh(), $o->fresh()->current_version_id, $terms($t), $reason)->fresh();
    $vacancy = Vacancy::findOrFail($template->vacancy_id);
    $vacancyBefore = $vacancy->only(['status', 'needed_count', 'filled_count']);

    echo 'نطاق التنفيذ — scope'.PHP_EOL;
    $app1 = $fresh('Check One');
    $o1 = $create($app1, ['salary' => '1500']);
    $v1 = $o1->currentVersion;
    $check('Create offer from selected application', $o1->status === Status::Draft && $v1->version_no === 1 && $o1->application_id === $app1->id);
    $raw = (string) DB::table('recruitment_job_offer_versions')->where('id', $v1->id)->value('salary_amount');
    $check('Salary — saved, encrypted at rest', $v1->salary_amount === '1500.00' && ! str_contains($raw, '1500'), strlen($raw).' chars of ciphertext');
    $check('Start date', $v1->start_date->toDateString() === $real->copy()->addMonth()->startOfMonth()->toDateString());
    $check('Probation period (typed by HR)', $v1->probation_days === 90);
    $check('Contract details', $v1->contract_type_id === $contractTypeId && $v1->weekly_hours === 40 && $v1->currency === Currency::USD
        && $v1->salary_per === SalaryPer::Month && $v1->end_date !== null
        && ($v1->terms_json['employment_type'] ?? null) === EmploymentType::FullTime && ($v1->terms_json['notes'] ?? null) === 'Transport allowance included.');
    $o1 = $send($o1);
    $check('Offer status — tracked', $o1->status === Status::Sent && $app1->fresh()->status === AppStatus::OfferSent);
    $o1 = $send($revise($o1, ['salary' => '1600']));
    $versions = $o1->versions()->orderBy('version_no')->get();
    $check('Offer version/history', $versions->count() === 2 && $versions[0]->status === Version::STATUS_SUPERSEDED && $versions[0]->salary_amount === '1500.00' && $versions[1]->salary_amount === '1600.00');

    echo PHP_EOL.'المطلوب تنفيذه — required'.PHP_EOL;
    $check('Job offer model', $o1->application->is($app1) && $o1->currentVersion->is($versions[1]) && $o1->versions()->count() === 2);
    $check('Offer creation service', $o1 instanceof Offer);
    $matrixOk = Status::canMove(Status::Draft, Status::Sent) && Status::canMove(Status::Sent, Status::Accepted)
        && ! Status::canMove(Status::Accepted, Status::Revised) && ! Status::canMove(Status::Draft, Status::Accepted) && ! Status::canMove(Status::Expired, Status::Sent);
    $check('Offer status transitions', $matrixOk);
    $html = Livewire::actingAs($hr)->test(Show::class, ['offerId' => $o1->id])->html();
    $check('Offer view / document (internal view)', str_contains($html, '1,600.00') && str_contains($html, __('recruitment-applicant.offers.document_title')));
    $check('Revised offer handling', $o1->currentVersion->version_no === 2 && $o1->currentVersion->revision_reason !== null);
    $events = Activity::query()->where('log_name', RecruitmentJobOfferService::AUDIT_LOG)->where('subject_id', $o1->id)->orderBy('id')->pluck('description')->all();
    $check('Audit events', $events === ['recruitment_job_offer.created', 'recruitment_job_offer.sent', 'recruitment_job_offer.revised', 'recruitment_job_offer.sent'], implode(', ', $events));

    echo PHP_EOL.'قواعد العمل — rules'.PHP_EOL;
    $submitted = $fresh('Check Submitted', AppStatus::Submitted);
    $check('Offer only for a selected candidate', $errorOf(fn () => $create($submitted)) === $msg('not_selected'));
    $check('No second active offer for the same application', $errorOf(fn () => $create($app1->fresh())) === $msg('already_exists')
        && $errorOf(fn () => DB::table('recruitment_job_offers')->insert(['application_id' => $app1->id, 'vacancy_id' => $vacancy->id, 'position_id' => $o1->position_id, 'status' => 'draft', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()])) === 'unique');
    $o1 = $respond($o1, Status::Rejected, 'Accepted another job.');
    $check('A rejected offer does not close the vacancy', $o1->status === Status::Rejected && $vacancy->fresh()->only(['status', 'needed_count', 'filled_count']) === $vacancyBefore);
    $o1 = $send($revise($o1, ['salary' => '1700'], 'Better salary after the refusal.'));
    $check('After refusal: send a revised offer…', $o1->status === Status::Sent && $app1->fresh()->status === AppStatus::OfferSent);
    $o1 = $respond($o1, Status::Rejected);
    $statuses->change($hr, $app1->fresh(), AppStatus::OfferRejected, AppStatus::Rejected, null, 'stronger_candidates');
    $app2 = $fresh('Check Two');
    $o2 = $send($create($app2));
    $check('…or choose another candidate', $app1->fresh()->status === AppStatus::Rejected && $o2->status === Status::Sent);
    // A second offer out for the same single seat, sent before o2 is accepted
    // (a sent offer holds no seat) — used below for "vacancy filled".
    $o9 = $send($create($fresh('Check Nine')));
    $o2 = $respond($o2, Status::Accepted, 'Accepted by phone.');
    $conversion = RecruitmentHiringConversion::query()->where('job_offer_id', $o2->id)->first();
    $check('Accepted offer starts the conversion to employee/contract', $conversion?->status === 'pending' && $conversion->job_offer_version_id === $o2->current_version_id);

    echo PHP_EOL.'الأمان والصلاحيات — security'.PHP_EOL;
    $app3 = $fresh('Check Three');
    $check('Creating an offer needs a clear permission', $errorOf(fn () => $offers->create($judy, $app3, $terms())) === '403' && $errorOf(fn () => $offers->create($rafi, $app3, $terms())) === '403');
    $rafiHtml = Livewire::actingAs($rafi)->test(Show::class, ['offerId' => $o2->id])->html();
    $listHtml = Livewire::actingAs($hr)->test(OfferTable::class)->html();
    $auditJson = Activity::query()->where('log_name', RecruitmentJobOfferService::AUDIT_LOG)->get()->pluck('properties')->toJson();
    $check('Salary and contract details are sensitive', $hr->can('viewSalary', $o2) && ! $rafi->can('viewSalary', $o2)
        && str_contains($rafiHtml, __('recruitment-applicant.offers.salary_hidden')) && ! str_contains($rafiHtml, '1,500.00')
        && ! str_contains($listHtml, '1,500.00') && ! str_contains($auditJson, '1500') && ! array_key_exists('salary_amount', $o2->currentVersion->toArray()));
    $check('Offer not shown outside scope', ! $mirna->can('view', $o2) && ! Offer::query()->visibleTo($mirna)->whereKey($o2->id)->exists() && ! $judy->can('view', $o2));
    $offerRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'offer'));
    $check('No public acceptance by application id (every offer route needs sign-in)', $offerRoutes->isNotEmpty() && $offerRoutes->every(fn ($r) => in_array('auth', $r->gatherMiddleware(), true)), $offerRoutes->count().' routes');

    echo PHP_EOL.'الحالات الاستثنائية — exceptional cases'.PHP_EOL;
    $draft3 = $create($app3);
    $statuses->change($hr, $app3->fresh(), AppStatus::Selected, AppStatus::Rejected, 'References came back negative.', 'stronger_candidates');
    $draft3->refresh();
    $check('Selected, then rejected before the offer was sent', $draft3->status === Status::Cancelled && $draft3->cancellation_reason === Offer::CANCEL_APPLICATION_CLOSED);
    $app4 = $fresh('Check Four', AppStatus::Selected, $otherTemplate);
    $o4 = $offers->updateDraft($hr, $create($app4, ['salary' => '1000']), Offer::where('application_id', $app4->id)->value('current_version_id'), $terms(['salary' => '1100']))->fresh();
    $draftAudit = Activity::query()->where('description', 'recruitment_job_offer.draft_updated')->where('subject_id', $o4->id)->first();
    $o4 = $send($o4);
    $check('HR changes the salary after creating the offer', $o4->currentVersion->salary_amount === '1100.00' && ($draftAudit?->properties['changed_fields'] ?? []) === ['salary_amount']
        && $errorOf(fn () => $offers->updateDraft($hr, $o4, $o4->current_version_id, $terms(['salary' => '1200']))) === $msg('not_editable'));
    Carbon::setTestNow($real->copy()->subDays(3));
    $app5 = $fresh('Check Five', AppStatus::Selected, $otherTemplate);
    $app6 = $fresh('Check Six', AppStatus::Selected, $otherTemplate);
    $o5 = $send($create($app5), $real->copy()->subDay()->toDateString());
    $o6 = $send($create($app6), $real->copy()->subDay()->toDateString());
    Carbon::setTestNow();
    $lateAnswer = $errorOf(fn () => $respond($o6, Status::Accepted));
    $swept = $offers->expireDue();
    $check('Offer expires (sweep, and a late answer)', $lateAnswer === $msg('expired') && $o6->fresh()->status === Status::Expired
        && $swept >= 1 && $o5->fresh()->status === Status::Expired && $app5->fresh()->status === AppStatus::Selected);
    $check('Candidate rejects the offer', $o1->fresh()->status === Status::Rejected && $o1->fresh()->response_recorded_by_user_id === $hr->id);
    $check('Candidate accepts twice / replay', $errorOf(fn () => $respond($o2, Status::Accepted)) === $msg('already_accepted') && RecruitmentHiringConversion::where('job_offer_id', $o2->id)->count() === 1);
    $check('Vacancy filled before acceptance', $errorOf(fn () => $respond($o9, Status::Accepted)) === $msg('vacancy_full') && $o9->fresh()->status === Status::Sent,
        'the one seat is held by the accepted offer; seats left '.$offers->seatsLeft($vacancy->fresh(), $o9));

    echo PHP_EOL.'سلامة البيانات — data integrity'.PHP_EOL;
    $check('Offer status transitions controlled', $errorOf(fn () => $respond($create($fresh('Check Seven')), Status::Accepted)) === $msg('not_sent')
        && $errorOf(fn () => $send($o4)) === $msg('not_sendable')
        && $errorOf(fn () => $offers->recordResponse($hr, $o4, $o4->current_version_id + 999, Status::Rejected, null)) === $msg('stale'));
    $check('Offer history kept', $o1->versions()->count() === 3 && $o1->versions()->pluck('status')->sort()->values()->all() === ['rejected', 'rejected', 'superseded']);
    $check('Accepted offer is not converted twice', $errorOf(fn () => DB::table('recruitment_hiring_conversions')->insert(['job_offer_id' => $o2->id, 'job_offer_version_id' => $o2->current_version_id, 'applicant_id' => $app2->applicant_id, 'employee_payload_json' => '{}', 'status' => 'pending', 'started_at' => now(), 'converted_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()])) === 'unique');
    $check('Salary / start date / contract details kept as a snapshot', str_starts_with($errorOf(fn () => $o2->currentVersion->forceFill(['salary_amount' => '9'])->save()), 'logic:')
        && str_starts_with($errorOf(fn () => $o2->currentVersion->fresh()->forceFill(['start_date' => '2030-01-01'])->save()), 'logic:')
        && $o2->currentVersion->fresh()->salary_amount === '1500.00' && str_starts_with($errorOf(fn () => $o2->fresh()->delete()), 'logic:'));

    echo PHP_EOL.'معايير القبول — acceptance'.PHP_EOL;
    $check('HR creates an offer for a selected candidate', $o2->creator->is($hr));
    $check('Offer holds salary / start date / probation / contract details', $o2->currentVersion->salary_amount !== null && $o2->currentVersion->start_date !== null && $o2->currentVersion->probation_days !== null && $o2->currentVersion->contract_type_id !== null);
    $check('Status tracking works', RecruitmentApplicationDecision::where('application_id', $app1->id)->orderBy('id')->pluck('to_status')->all()
        === ['shortlisted', 'selected', 'offer_sent', 'offer_rejected', 'offer_sent', 'offer_rejected', 'rejected']);
    $check('Rejected / expired offer does not close the vacancy', $vacancy->fresh()->only(['status', 'needed_count', 'filled_count']) === $vacancyBefore);
    $check('Accepted offer prepares the conversion', ($conversion->employee_payload_json['full_name'] ?? null) === 'Check Two' && $app2->fresh()->status === AppStatus::OfferAccepted);

    echo PHP_EOL.'أخطاء يجب تجنبها — errors to avoid'.PHP_EOL;
    $check('No offer for a non-selected candidate', $errorOf(fn () => $create(RecruitmentApplication::findOrFail(3))) === $msg('not_selected'));
    $check('No change to a sent offer without version/history', $errorOf(fn () => $offers->updateDraft($hr, $o4, $o4->current_version_id, $terms(['salary' => '1']))) === $msg('not_editable'));
    $check('Salary never shown to the unauthorized', ! str_contains($rafiHtml, '1,500.00') && ! $rafi->can('manage', $o2));
    $check('Accepted offer never converted twice', RecruitmentHiringConversion::where('job_offer_id', $o2->id)->count() === 1);

    echo PHP_EOL.'Pages HR uses'.PHP_EOL;
    $app8 = $fresh('Check Eight');
    $check('Create page opens for HR', Livewire::actingAs($hr)->test(Form::class, ['applicationId' => $app8->id])->get('mode') === 'create');
    $o8 = $create($app8);
    $check('Edit-draft page opens for HR', Livewire::actingAs($hr)->test(Form::class, ['offerId' => $o8->id])->get('mode') === 'edit');
    $check('Revise page opens for HR (the fixed route)', Livewire::actingAs($hr)->test(Revise::class, ['offerId' => $o5->id])->get('mode') === 'revise');
    $check('Revise page refused when revising is impossible', rescue(fn () => Livewire::actingAs($hr)->test(Revise::class, ['offerId' => $o2->id])->assertNotFound() !== null, false, false));
    $check('Write pages refused for super-admin (salary-blind)', rescue(fn () => Livewire::actingAs($rafi)->test(Form::class, ['offerId' => $o8->id])->assertForbidden() !== null, false, false));

    echo PHP_EOL.'المخرجات — output'.PHP_EOL;
    $check('Accepted offer can be converted to Employee and Contract (handed over, once)', $conversion !== null && $conversion->status === 'pending'
        && Version::find($conversion->job_offer_version_id)?->status === Version::STATUS_ACCEPTED);
} finally {
    Carbon::setTestNow();
    DB::rollBack();
}

echo PHP_EOL."RESULT: {$pass} passed, {$fail} failed".PHP_EOL;
echo 'Rolled back: offers '.Offer::count().', throwaway applications '.RecruitmentApplication::where('email_normalized', 'like', 'task9-check-%')->count().PHP_EOL;
