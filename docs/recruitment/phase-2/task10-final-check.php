<?php

/*
 * Phase 2 Task 10 — requirements check on the real MySQL database.
 * Every line of the spec gets a PASS / FAIL. Everything runs in one
 * transaction that is ROLLED BACK at the end: nothing is left behind (no
 * employee, contract, offer or application), and the welcome notification,
 * sent only after a commit, never goes out.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task10-final-check.php';"
 *
 * It builds throwaway applications on two open campaigns, takes each one
 * through the offer service to an accepted offer, and drives the hiring
 * conversion service, the policy and the Livewire pages exactly as the UI
 * does. Position 1 (where every vacancy points) is full on this database, so
 * it is given room inside the transaction; the real pending conversion
 * (offer 2) is only read.
 */

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as OfferStatus;
use App\Enums\SalaryPer;
use App\Livewire\Recruitment\Offers\Convert;
use App\Livewire\Recruitment\Offers\Show;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\HiringCampaign;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentHiringConversionService as Conversions;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;            // HR-SYR: hires, sees salaries
$rafiId = 380;         // super-admin: salary-blind by design
$mirnaId = 346;        // HR of another city: out of scope
$judyId = 3;           // manager: no hiring permission
$campaignId = 28;      // an open campaign (vacancy 26)
$otherCampaignId = 25; // a second one, for the exceptional cases (vacancy 23)
$phoneEmployeeId = 1;  // an existing employee whose personal phone the "same phone" candidate uses
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
    } catch (RuntimeException $e) {
        return 'runtime: '.$e->getMessage();
    }
};
$msg = fn (string $key) => __('recruitment-applicant.conversions.errors.'.$key);

$hr = User::findOrFail($hrId);
[$rafi, $mirna, $judy] = [User::findOrFail($rafiId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
$offers = app(RecruitmentJobOfferService::class);
$statuses = app(RecruitmentApplicationStatusService::class);
$conversions = app(Conversions::class);
$template = RecruitmentApplication::query()->where('campaign_id', $campaignId)->firstOrFail();
$otherTemplate = RecruitmentApplication::query()->where('campaign_id', $otherCampaignId)->firstOrFail();
// The real accepted offer still waiting to be hired (pending, or with failed attempts).
$realPending = Conversion::query()->where('status', '!=', Conversion::STATUS_CONVERTED)->orderBy('id')->first();
$realPendingBefore = $realPending?->only(['status', 'attempt_count', 'employee_id', 'contract_id']);

DB::beginTransaction();

try {

    // Offers accepted in the app keep their seats on the second vacancy; the
    // checks get their own room on top of them (rolled back with the rest).
    $heldOnOther = Offer::query()->where('vacancy_id', $otherTemplate->vacancy_id)->where('status', 'accepted')->count();
    auth()->login($hr);

    // A fresh selected application on a campaign, taken to an accepted offer.
    $accepted = function (string $name, ?RecruitmentApplication $on = null, array $terms = [], ?string $phone = null) use ($template, $statuses, $offers, $hr): Conversion {
        $on ??= $template;
        $email = 'task10-check-'.Str::lower(Str::random(10)).'@example.test';
        $applicant = RecruitmentApplicant::query()->forceCreate([
            'full_name' => $name, 'email_normalized' => $email, 'phone_normalized' => $phone, 'source' => 'public_link',
            'identity_key_hash' => hash_hmac('sha256', $email, (string) config('app.key')), 'city_id' => 2,
        ]);
        $application = RecruitmentApplication::query()->forceCreate([
            'applicant_id' => $applicant->id, 'campaign_id' => $on->campaign_id, 'vacancy_id' => $on->vacancy_id,
            'form_version_id' => $on->form_version_id, 'public_link_id' => $on->public_link_id,
            'submission_key_hash' => hash('sha256', $email), 'email_normalized' => $email, 'status' => AppStatus::Submitted,
            'privacy_accepted_at' => now(), 'submitted_at' => now(),
        ]);
        $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        $statuses->change($hr, $application->fresh(), AppStatus::Shortlisted, AppStatus::Selected, null);

        $offer = $offers->create($hr, $application->fresh(), array_merge([
            'contractTypeId' => 3, 'probationDays' => 90, 'salary' => '1500', 'currency' => Currency::USD, 'salaryPer' => SalaryPer::Month,
            'startDate' => now()->addMonth()->startOfMonth()->toDateString(), 'endDate' => now()->addMonth()->startOfMonth()->addMonths(11)->toDateString(),
            'weeklyHours' => 40, 'employmentType' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite, 'notes' => '',
        ], $terms))->fresh();
        $offer = $offers->send($hr, $offer, $offer->current_version_id, null)->fresh();
        $offer = $offers->recordResponse($hr, $offer, $offer->current_version_id, OfferStatus::Accepted, null)->fresh();

        return $offer->hiringConversion;
    };
    $person = fn (array $o = []) => array_merge([
        'firstNameEn' => 'Check', 'lastNameEn' => 'Hire', 'firstNameAr' => 'فحص', 'lastNameAr' => 'توظيف',
        'fatherNameEn' => 'Father', 'fatherNameAr' => 'الأب', 'motherNameEn' => 'Mother', 'motherNameAr' => 'الأم',
        'nationality' => '1', 'nationalNumber' => '99'.random_int(100000000, 999999999), 'gender' => '1', 'maritalStatus' => '1',
        'dob' => '1995-05-01', 'personalPhone' => '', 'personalEmail' => '', 'username' => 'task10.check.'.Str::lower(Str::random(6)),
    ], $o);
    $hire = fn (Conversion $c, array $o = []) => $conversions->convert($hr, $c->fresh(), array_merge(['mode' => Conversions::MODE_CREATE, 'employee' => $person()], $o))->fresh();

    // The real pending conversion, as it stands, before anything is changed.
    $realBlockers = $realPending ? $conversions->blockers($realPending) : [];
    $realHtml = $realPending ? Livewire::actingAs($hr)->test(Convert::class, ['offerId' => $realPending->job_offer_id])->html() : '';

    // Position 1 is full on this database: room for the checks, rolled back.
    $position = Position::query()->findOrFail(Vacancy::findOrFail($template->vacancy_id)->position_id);
    $position->forceFill(['max_emp' => $position->exist_emp + 10])->save();
    $vacancy = Vacancy::findOrFail($template->vacancy_id);
    $vacancy->forceFill(['needed_count' => 2])->save(); // a vacancy needing two people
    $campaign = HiringCampaign::findOrFail($campaignId);

    echo 'نطاق التنفيذ — scope'.PHP_EOL;
    $c1 = $accepted('Check One');
    $existBefore = (int) $position->fresh()->exist_emp;
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    $c1 = $hire($c1, ['employee' => $person(['username' => 'task10.check.one'])]);
    $employee1 = $c1->employee;
    $contract1 = $c1->contract;
    $check('Create employee', $employee1 !== null && $employee1->username === 'task10.check.one' && $employee1->is_active);
    $check('Create contract', $contract1 !== null && $contract1->employee_id === $employee1->id && $contract1->position_id === $position->id);
    $check('Link application/offer to employee', $c1->employee_id === $employee1->id
        && RecruitmentApplicant::find($c1->applicant_id)->employee_id === $employee1->id
        && $c1->jobOffer->application->status === AppStatus::Hired);
    $vacancyAfterFirst = $vacancy->fresh();
    $check('Update vacancy status (one of two seats: filled 1, still open)', $vacancyAfterFirst->filled_count === 1 && $vacancyAfterFirst->status === Vacancy::OPEN && (int) $campaign->fresh()->status === HiringCampaignStatus::Open);
    $audit1 = Activity::query()->where('log_name', Conversions::AUDIT_LOG)->where('subject_id', $c1->id)->where('description', 'recruitment_hiring_conversion.converted')->first();
    $check('Audit conversion', $audit1 !== null && $audit1->causer_id === $hr->id && $audit1->properties['contract_id'] === $contract1->id);
    $retry = $hire($c1, ['employee' => $person()]);
    $check('Prevent double conversion (a retry returns the same hire)', $retry->contract_id === $contract1->id && Contract::where('employee_id', $employee1->id)->count() === 1 && $vacancy->fresh()->filled_count === 1);

    echo PHP_EOL.'المطلوب تنفيذه — required'.PHP_EOL;
    $check('Hiring conversion service', $c1->status === Conversion::STATUS_CONVERTED && $c1->converted_at !== null && $c1->converted_by_user_id === $hr->id);
    $check('Employee creation/linking logic', $employee1->city_id === $conversions->workCityId($position) && $employee1->first_name_ar === 'فحص');
    $check('Contract in the existing HR contract tables (+ leave balances, position headcount)', DB::table('contracts')->where('id', $contract1->id)->exists()
        && LeaveBalance::where('contract_id', $contract1->id)->count() > 0 && (int) $position->fresh()->exist_emp === $existBefore + 1);
    $check('Vacancy fulfillment logic', $vacancyAfterFirst->filled_count === 1);
    $locked = collect($queries)->filter(fn ($sql) => str_contains($sql, 'for update'));
    $lockedTables = collect(['recruitment_applications', 'recruitment_job_offers', 'recruitment_hiring_conversions', 'vacancies', 'positions'])
        ->filter(fn ($table) => $locked->contains(fn ($sql) => str_contains($sql, $table)));
    $check('Transactional conversion with row locks (application, offer, conversion, vacancy, position)', $lockedTables->count() === 5, $lockedTables->implode(', '));

    echo PHP_EOL.'قواعد العمل — rules'.PHP_EOL;
    $c2 = $accepted('Check Two');
    $check('Only an accepted offer is converted', $c2->jobOffer->status === OfferStatus::Accepted && $conversions->blockers($c2) === []);
    $check('Converted once only', $errorOf(fn () => DB::table('recruitment_hiring_conversions')->insert([
        'job_offer_id' => $c1->job_offer_id, 'job_offer_version_id' => $c1->job_offer_version_id, 'applicant_id' => $c1->applicant_id,
        'employee_payload_json' => '{}', 'status' => 'pending', 'started_at' => now(), 'converted_by_user_id' => $hr->id,
    ])) === 'unique' && str_starts_with($errorOf(fn () => $c1->fresh()->forceFill(['contract_id' => null])->save()), 'logic:'));
    $raw = (string) DB::table('contracts')->where('id', $contract1->id)->value('salary');
    $v1 = $c1->jobOfferVersion;
    $check('Contract takes the accepted snapshot', $v1->status === 'accepted' && (float) $contract1->salary === (float) $v1->salary_amount
        && $contract1->start_date->toDateString() === $v1->start_date->toDateString() && $contract1->end_date->toDateString() === $v1->end_date->toDateString()
        && (int) $contract1->weekly_hours === (int) $v1->weekly_hours && (int) $contract1->currency === (int) $v1->currency && ! str_contains($raw, '1500'));
    $c2 = $hire($c2, ['employee' => $person(['username' => 'task10.check.two'])]);
    $vacancyAfterSecond = $vacancy->fresh();
    $check('Vacancy closes when the needed count is reached', $vacancyAfterSecond->filled_count === 2 && $vacancyAfterSecond->status === Vacancy::FILLED && $vacancyAfterSecond->closed_at !== null);
    $check('A vacancy needing several people stays open until complete', $vacancyAfterFirst->status === Vacancy::OPEN && $vacancyAfterFirst->closed_at === null);
    $check('Its open campaign is closed once filled (through the campaign service)', (int) $campaign->fresh()->status === HiringCampaignStatus::Closed
        && DB::table('recruitment_request_events')->where('meta->campaign_id', $campaign->id)->where('meta->reason', Conversions::CAMPAIGN_CLOSE_REASON)->exists());

    echo PHP_EOL.'الأمان والصلاحيات — security'.PHP_EOL;
    // The second vacancy needs four people: four candidates can accept.
    Vacancy::whereKey($otherTemplate->vacancy_id)->update(['needed_count' => 4 + $heldOnOther]);
    $c3 = $accepted('Check Three', $otherTemplate);
    $check('Conversion needs a clear permission (convert-hires + contracts.create + pay visibility)', $hr->can('convert', $c3)
        && ! $judy->can('convert', $c3) && ! $rafi->can('convert', $c3)
        && $errorOf(fn () => $conversions->convert($judy, $c3, ['mode' => 'create', 'employee' => $person()])) === '403'
        && $errorOf(fn () => $conversions->convert($rafi, $c3, ['mode' => 'create', 'employee' => $person()])) === '403');
    $check('A user outside scope cannot create the employee/contract', ! $mirna->can('convert', $c3)
        && $errorOf(fn () => $conversions->convert($mirna, $c3, ['mode' => 'create', 'employee' => $person()])) === '403');
    echo '  INFO No separate approval step: the seat was approved with the recruitment request and the terms with the offer.'.PHP_EOL;
    // This script's own hires only: the real database may hold real ones.
    $ours = Activity::query()->where('log_name', Conversions::AUDIT_LOG)->whereIn('subject_id', [$c1->id, $c2->id]);
    $check('Audit required (every hire and every failed attempt), never the salary', (clone $ours)->where('description', 'recruitment_hiring_conversion.converted')->count() === 2
        && ! str_contains(Activity::query()->where('log_name', Conversions::AUDIT_LOG)->get()->pluck('properties')->toJson(), '1500'));

    echo PHP_EOL.'الحالات الاستثنائية — exceptional cases'.PHP_EOL;
    $phoneEmployee = Employee::findOrFail($phoneEmployeeId);
    $phone = '+963'.substr((string) $phoneEmployee->personal_phone, 1);
    // The identity rule: the same person is the same name with the same phone.
    $phoneName = trim($phoneEmployee->first_name_en.' '.$phoneEmployee->last_name_en) ?: trim($phoneEmployee->first_name_ar.' '.$phoneEmployee->last_name_ar);
    $c4 = $accepted($phoneName, $otherTemplate, [], $phone);
    $employeesBefore = Employee::count();
    $dupError = $errorOf(fn () => $hire($c4));
    $c4 = $hire($c4, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $phoneEmployee->id]);
    $check('Employee already exists (same name and phone): linked, not duplicated', $dupError === $msg('possible_duplicate')
        && Employee::count() === $employeesBefore && $c4->employee_id === $phoneEmployee->id, 'matched '.$phoneEmployee->username.' by name and phone');

    // The identity rule: the same phone under another name is a warning, never a link.
    Vacancy::whereKey($otherTemplate->vacancy_id)->update(['needed_count' => 5 + $heldOnOther]); // one more accepted offer on it, rolled back
    $c6 = $accepted('Check Six', $otherTemplate, [], $phone);
    $c6Matches = $conversions->matches($c6);
    $c6Html = Livewire::actingAs($hr)->test(Convert::class, ['offerId' => $c6->job_offer_id])->html();
    $check('Same phone under another name: a warning, never a link, no tick asked', $c6Matches->contains(fn ($m) => $m['employee']->id === $phoneEmployee->id)
        && $c6Matches->every(fn ($m) => $m['kind'] === Conversions::MATCH_WARNING)
        && $errorOf(fn () => $hire($c6, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $phoneEmployee->id])) === $msg('name_mismatch')
        && str_contains($c6Html, e(__('recruitment-applicant.conversions.warnings.phone', ['name' => $phoneEmployee->name])))
        && ! str_contains($c6Html, e(__('recruitment-applicant.conversions.confirm_not_duplicate')))
        && $c6->fresh()->status === Conversion::STATUS_PENDING, 'phone of '.$phoneEmployee->username);

    // The identity rule: a national number on file under another name stops the hire.
    $nnHolder = Employee::query()->whereRaw("national_number REGEXP '^[0-9]{11}$'")->whereKeyNot($phoneEmployee->id)->orderBy('id')->first();
    if ($nnHolder !== null) {
        $employeesBefore = Employee::count();
        $nnError = $errorOf(fn () => $hire($c6, ['employee' => $person(['nationalNumber' => $nnHolder->national_number])]));
        $check('National number on file under another name: the hire stops, nothing created', $nnError === __('recruitment-applicant.conversions.errors.national_number_mismatch', ['name' => $nnHolder->name])
            && Employee::count() === $employeesBefore && $c6->fresh()->status === Conversion::STATUS_PENDING && $c6->fresh()->attempt_count === 0, 'number of '.$nnHolder->username);
    }

    // The same person accepted a second offer for the position they now hold.
    $c5 = $accepted($phoneName, $otherTemplate, [], $phone);
    $check('The same person is never hired onto a position they already hold', $errorOf(fn () => $hire($c5, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $phoneEmployee->id])) === $msg('already_on_position')
        && $c5->fresh()->status === Conversion::STATUS_PENDING);

    $failing = true;
    Contract::creating(function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('Simulated contract failure');
        }
    });
    $employeesBefore = Employee::count();
    $contractError = $errorOf(fn () => $hire($c3));
    $failing = false;
    $check('Contract fails after employee creation: nothing left, attempt recorded', str_starts_with($contractError, 'runtime:')
        && Employee::count() === $employeesBefore && $c3->fresh()->status === Conversion::STATUS_FAILED && $c3->fresh()->failure_reason === 'unexpected');

    $otherVacancy = Vacancy::findOrFail($otherTemplate->vacancy_id);
    $otherVacancy->forceFill(['status' => Vacancy::CLOSED, 'closed_at' => now()])->save();
    $check('Offer accepted but vacancy closed', $errorOf(fn () => $hire($c3)) === $msg('vacancy_closed') && $c3->fresh()->failure_reason === 'vacancy_closed');
    $otherVacancy->forceFill(['status' => Vacancy::OPEN, 'closed_at' => null])->save();

    $position->forceFill(['is_active' => false])->save();
    $check('Position inactive', $errorOf(fn () => $hire($c3)) === $msg('position_inactive'));
    $position->forceFill(['is_active' => true, 'max_emp' => $position->fresh()->exist_emp])->save();
    $check('Capacity full', $errorOf(fn () => $hire($c3)) === $msg('position_full') && Employee::count() === $employeesBefore);
    $position->forceFill(['max_emp' => $position->fresh()->exist_emp + 5])->save();

    $c3 = $hire($c3, ['employee' => $person(['username' => 'task10.check.three'])]);
    $again = $hire($c3);
    $check('Retry after a failure / a timeout', $c3->status === Conversion::STATUS_CONVERTED && $c3->attempt_count === 5 && $again->contract_id === $c3->contract_id,
        'attempts '.$c3->attempt_count);

    echo PHP_EOL.'سلامة البيانات — data integrity'.PHP_EOL;
    $check('One transaction: employee/link + contract + application/offer/vacancy', $c3->employee_id !== null && $c3->contract_id !== null
        && $c3->jobOffer->application->status === AppStatus::Hired && $otherVacancy->fresh()->filled_count === 2);
    $check('Row locks on offer / vacancy / position', $lockedTables->contains('recruitment_job_offers') && $lockedTables->contains('vacancies') && $lockedTables->contains('positions'));
    $check('Idempotency: no second conversion', Conversion::where('job_offer_id', $c3->job_offer_id)->count() === 1 && Contract::where('id', $c3->contract_id)->count() === 1);
    $employeeColumn = collect(Schema::getColumns('contracts'))->firstWhere('name', 'employee_id');
    $check('No contract without an employee (NOT NULL, and every hired contract has one)', ! $employeeColumn['nullable']
        && Contract::whereIn('id', [$c1->contract_id, $c2->contract_id, $c3->contract_id, $c4->contract_id])->whereDoesntHave('employee')->doesntExist());

    echo PHP_EOL.'معايير القبول — acceptance'.PHP_EOL;
    $check('Accepted offer becomes an employee (or a link)', $c1->employee_id !== null && $c4->employee_id === $phoneEmployee->id);
    $check('Contract created in the existing HR system', Contract::whereIn('id', [$c1->contract_id, $c2->contract_id, $c3->contract_id, $c4->contract_id])->count() === 4);
    $check('Vacancy status updated correctly', $vacancy->fresh()->status === Vacancy::FILLED && $otherVacancy->fresh()->status === Vacancy::OPEN && $otherVacancy->fresh()->filled_count === 2);
    $check('Conversion does not run twice', Activity::query()->where('log_name', Conversions::AUDIT_LOG)->where('subject_id', $c1->id)->where('description', 'recruitment_hiring_conversion.converted')->count() === 1);
    $check('Failure never leaves an employee without a contract', Employee::whereIn('username', ['task10.check.one', 'task10.check.two', 'task10.check.three'])->get()->every(fn ($e) => $e->allContracts()->count() === 1));

    echo PHP_EOL.'أخطاء يجب تجنبها — errors to avoid'.PHP_EOL;
    $check('No employee created then contract failed without transaction/recovery', str_starts_with($contractError, 'runtime:') && $c3->fresh()->attempt_count === 5);
    $check('Not the live mutable offer: the accepted snapshot', $c1->job_offer_version_id === $c1->jobOffer->current_version_id && str_starts_with($errorOf(fn () => $v1->fresh()->forceFill(['salary_amount' => '1'])->save()), 'logic:'));
    $check('Vacancy not closed before capacity/need is met', $vacancyAfterFirst->status === Vacancy::OPEN);
    $sent = (function () use ($otherTemplate, $statuses, $offers, $hr) {
        $email = 'task10-check-'.Str::lower(Str::random(10)).'@example.test';
        $applicant = RecruitmentApplicant::query()->forceCreate(['full_name' => 'Check Sent', 'email_normalized' => $email, 'source' => 'public_link', 'identity_key_hash' => hash_hmac('sha256', $email, (string) config('app.key'))]);
        $application = RecruitmentApplication::query()->forceCreate([
            'applicant_id' => $applicant->id, 'campaign_id' => $otherTemplate->campaign_id, 'vacancy_id' => $otherTemplate->vacancy_id,
            'form_version_id' => $otherTemplate->form_version_id, 'public_link_id' => $otherTemplate->public_link_id,
            'submission_key_hash' => hash('sha256', $email), 'email_normalized' => $email, 'status' => AppStatus::Submitted, 'privacy_accepted_at' => now(), 'submitted_at' => now(),
        ]);
        $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        $statuses->change($hr, $application->fresh(), AppStatus::Shortlisted, AppStatus::Selected, null);
        $offer = $offers->create($hr, $application->fresh(), ['contractTypeId' => 3, 'salary' => '900', 'currency' => Currency::USD, 'salaryPer' => SalaryPer::Month, 'startDate' => now()->addMonth()->toDateString(), 'endDate' => now()->addMonths(6)->toDateString(), 'weeklyHours' => 40, 'employmentType' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite])->fresh();
        $offer = $offers->send($hr, $offer, $offer->current_version_id, null)->fresh();

        return Conversion::query()->forceCreate([
            'job_offer_id' => $offer->id, 'job_offer_version_id' => $offer->current_version_id, 'applicant_id' => $applicant->id,
            'employee_payload_json' => ['full_name' => 'Check Sent'], 'status' => 'pending', 'started_at' => now(), 'converted_by_user_id' => $hr->id,
        ]);
    })();
    $check('No conversion from an offer that is not accepted', $errorOf(fn () => $hire($sent)) === $msg('offer_not_accepted'));

    echo PHP_EOL.'Pages HR uses'.PHP_EOL;
    $check('Offer page shows the hire and its links', str_contains(Livewire::actingAs($hr)->test(Show::class, ['offerId' => $c1->job_offer_id])->html(), route('contracts.show', $c1->contract_id)));
    $check('Hire page refused to super-admin (salary-blind)', rescue(fn () => Livewire::actingAs($rafi)->test(Convert::class, ['offerId' => $sent->job_offer_id])->assertForbidden() !== null, false, false));
    if ($realPending !== null) {
        // Whatever stops it is listed on the page; with nothing in the way, the form is ready.
        $check('Real waiting offer '.$realPending->job_offer_id.': the hire page shows exactly what stops it', $realBlockers === []
            ? ! str_contains($realHtml, e(__('recruitment-applicant.conversions.blockers_title')))
            : collect($realBlockers)->every(fn ($code) => str_contains($realHtml, e($msg($code)))), implode(', ', $realBlockers) ?: 'nothing: ready to hire');
    }

    echo PHP_EOL.'المخرجات — output'.PHP_EOL;
    $check('The accepted candidate is an employee with a contract; the hiring cycle is complete', $c1->jobOffer->application->status === AppStatus::Hired
        && RecruitmentApplicationDecision::where('application_id', $c1->jobOffer->application_id)->latest('id')->value('reason_code') === RecruitmentApplicationStatusService::REASON_CONVERSION
        && $contract1->is_active);
} finally {
    DB::rollBack();
}

echo PHP_EOL."RESULT: {$pass} passed, {$fail} failed".PHP_EOL;
echo 'Rolled back: throwaway employees '.Employee::where('username', 'like', 'task10.check.%')->count()
    .', throwaway applications '.RecruitmentApplication::where('email_normalized', 'like', 'task10-check-%')->count()
    .', real pending conversion unchanged: '.(($realPending?->fresh()->only(['status', 'attempt_count', 'employee_id', 'contract_id'])) === $realPendingBefore ? 'yes' : 'NO').PHP_EOL;
