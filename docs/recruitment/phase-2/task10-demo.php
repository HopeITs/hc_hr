<?php

/*
 * Phase 2 Task 10: prepares real hiring-conversion data to test BY HAND in
 * the UI. NOTHING here is rolled back, and a hire is final by design (no
 * employee, contract or conversion is ever deleted), so take a backup first
 * and restore it to start over:
 *
 *   C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe -u root hr_db > hr_db_before_task10_demo.sql
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task10-demo.php';"
 *
 * The script does NOT hire anybody: the hires are the manual test. It only
 * brings four accepted offers to the hire page, through the real services
 * (same rules, locks and audit as the UI):
 *
 *   A   offer 2 (app 10, vacancy 22)  already accepted; one hire attempt is made
 *                                     now and refused — position 1 is full (1/1)
 *   A2  offer 3 (app 9,  vacancy 22)  vacancy 22 now needs TWO people; the
 *                                     cancelled offer is revised, sent, accepted
 *   B   offer 4 (app 7,  vacancy 12)  sent -> accepted
 *   C   offer 5 (app 8,  vacancy 19)  sent -> accepted. The SAME applicant as B (same
 *                                     email), on the same position: once B is linked
 *                                     to rafi.louliki, C can only be linked to him too,
 *                                     and he already holds that position — refused
 *                                     both ways (the "one person twice" guard)
 *
 * Then position 1 is given room for the hires (in production that is an
 * approved "increase headcount" request). Every candidate on this database
 * shares the phone +963950018480, which is employee 380's work phone, so each
 * hire page lists a possible existing employee — the duplicate check at work.
 *
 * Hire as lana.abdo (HR-SYR). rafi.louliki's active role is super-admin,
 * which is salary-blind by design and therefore cannot write a contract.
 */

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Enums\SalaryPer;
use App\Models\HiringCampaign;
use App\Models\Position;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentHiringConversionService as Conversions;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

// ---- Adjust if needed -------------------------------------------------
$hrId = 11;     // lana.abdo (HR-SYR): hires, sees salaries
$rafiId = 380;  // rafi.louliki (super-admin): salary-blind, cannot hire
$mirnaId = 346; // mirna.badra (HR-DMS): the offers are outside her scope
$judyId = 3;    // judy.dadaghlian (manager): no hiring permission
$offerIds = ['A' => 2, 'A2' => 3, 'B' => 4, 'C' => 5];
$twoSeatVacancyId = 22;
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$base = rtrim(config('app.url'), '/').'/en';

$hr = User::findOrFail($hrId);
[$rafi, $mirna, $judy] = [User::findOrFail($rafiId), User::findOrFail($mirnaId), User::findOrFail($judyId)];
$offers = app(RecruitmentJobOfferService::class);
$conversions = app(Conversions::class);

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
$state = function (string $label, Offer $offer) use ($conversions, $base): void {
    $offer->refresh();
    $conversion = $offer->hiringConversion;
    $vacancy = $offer->vacancy;
    echo "  {$label}  offer #{$offer->id} | {$offer->status} | application ".$offer->application->status
        .' | conversion '.($conversion ? "{$conversion->status}, attempts {$conversion->attempt_count}".($conversion->failure_reason ? ", last failure: {$conversion->failure_reason}" : '') : '—')
        ." | vacancy {$vacancy->id}: {$vacancy->status} {$vacancy->filled_count}/{$vacancy->needed_count}".PHP_EOL
        .'       blockers now: ['.($conversion ? implode(', ', $conversions->blockers($conversion)) : '—').']'
        .' | possible existing employees: '.($conversion ? ($conversions->matches($conversion)->map(fn ($m) => $m['employee']->username.' ('.implode('+', $m['reasons']).')')->implode(', ') ?: 'none') : '—').PHP_EOL
        ."       hire page: {$base}/recruitment/offers/{$offer->id}/convert".PHP_EOL;
};

// ---- Guard: once only -------------------------------------------------
$demo = collect($offerIds)->map(fn (int $id) => Offer::query()->findOrFail($id));
if ($demo['B']->status === Status::Accepted || (int) Vacancy::findOrFail($twoSeatVacancyId)->needed_count === 2) {
    echo 'The demo has already run — nothing changed. Current state:'.PHP_EOL;
    $demo->each(fn (Offer $offer, string $label) => $state($label, $offer));

    return;
}
if ($demo['A']->status !== Status::Accepted || $demo['A']->hiringConversion === null || $demo['A']->hiringConversion->isConverted()
    || $demo['A2']->status !== Status::Cancelled || $demo['B']->status !== Status::Sent || $demo['C']->status !== Status::Sent) {
    echo 'The offers are not in the state this demo expects (A accepted and not hired yet, A2 cancelled, B and C sent): adjust $offerIds at the top.'.PHP_EOL;
    $demo->each(fn (Offer $offer, string $label) => $state($label, $offer));

    return;
}

auth()->login($hr);
$position = Position::query()->findOrFail($demo['A']->position_id);

echo "1. A — the hire is refused while position {$position->id} is full ({$position->exist_emp}/{$position->max_emp})".PHP_EOL;
$refused('Hiring A now', fn () => $conversions->convert($hr, $demo['A']->hiringConversion, [
    'mode' => Conversions::MODE_CREATE, 'confirmNotDuplicate' => true,
    'employee' => ['firstNameEn' => 'Demo', 'lastNameEn' => 'Candidate', 'firstNameAr' => 'مرشح', 'lastNameAr' => 'تجريبي',
        'fatherNameEn' => 'Father', 'fatherNameAr' => 'الأب', 'motherNameEn' => 'Mother', 'motherNameAr' => 'الأم',
        'nationality' => '1', 'nationalNumber' => '09999000001', 'gender' => '1', 'maritalStatus' => '1', 'username' => 'demo.candidate'],
]));
$ok('A', 'the failed attempt is recorded on the conversion: '.$demo['A']->hiringConversion->fresh()->status.', reason '.$demo['A']->hiringConversion->fresh()->failure_reason);

echo PHP_EOL.'2. Who may hire'.PHP_EOL;
foreach ([[$judy, 'manager, no hiring permission'], [$rafi, 'super-admin, salary-blind'], [$mirna, 'HR of another city']] as [$user, $why]) {
    $refused("{$user->email} ({$why}) hires A", fn () => $conversions->convert($user, $demo['A']->hiringConversion->fresh(), ['mode' => Conversions::MODE_CREATE]));
}

echo PHP_EOL.'3. B and C — the candidates accept'.PHP_EOL;
foreach (['B', 'C'] as $label) {
    $offer = $demo[$label]->fresh();
    $offer = $offers->recordResponse($hr, $offer, $offer->current_version_id, Status::Accepted, 'Accepted by phone (Task 10 demo).')->fresh();
    $ok("{$label} offer #{$offer->id}", "{$offer->status}, conversion {$offer->hiringConversion->status}");
}

echo PHP_EOL."4. A2 — vacancy {$twoSeatVacancyId} now needs two people; the cancelled offer is revised, sent and accepted".PHP_EOL;
Vacancy::query()->whereKey($twoSeatVacancyId)->update(['needed_count' => 2]);
$offer = $demo['A2']->fresh();
$startDate = now()->addMonth()->startOfMonth();
$offer = $offers->revise($hr, $offer, $offer->current_version_id, [
    'contractTypeId' => 3, 'probationDays' => 90, 'salary' => '1100', 'currency' => Currency::USD, 'salaryPer' => SalaryPer::Month,
    'startDate' => $startDate->toDateString(), 'endDate' => $startDate->copy()->endOfYear()->toDateString(),
    'weeklyHours' => 40, 'employmentType' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite, 'notes' => '',
], 'The vacancy was widened to two seats; this candidate is offered the second one.')->fresh();
$offer = $offers->send($hr, $offer, $offer->current_version_id, null)->fresh();
$offer = $offers->recordResponse($hr, $offer, $offer->current_version_id, Status::Accepted, 'Accepted by phone (Task 10 demo).')->fresh();
$ok("A2 offer #{$offer->id}", "v{$offer->currentVersion->version_no} {$offer->status}, conversion {$offer->hiringConversion->status}");

echo PHP_EOL."5. Position {$position->id} is given room for the hires".PHP_EOL;
$before = "{$position->exist_emp}/{$position->max_emp}";
$position->forceFill(['max_emp' => $position->exist_emp + 4])->save();
$ok("Position {$position->id}", "{$before} -> {$position->exist_emp}/{$position->max_emp} (in production: an approved increase-headcount request)");

echo PHP_EOL.'6. Ready to hire by hand'.PHP_EOL;
$demo->each(fn (Offer $offer, string $label) => $state($label, $offer));
$campaigns = HiringCampaign::query()->whereIn('vacancy_id', $demo->pluck('vacancy_id'))->orderBy('id')->get(['id', 'vacancy_id', 'status']);
$ok('Campaigns on these vacancies', $campaigns->map(fn ($c) => "#{$c->id} (vacancy {$c->vacancy_id}) status {$c->status}")->implode(', ').'   (status 1 = published, 2 = open, 3 = closed)');

echo PHP_EOL.'Log in as '.$hr->email.' and follow the manual steps in the Task 10 report.'.PHP_EOL;
echo "  Offers list: {$base}/recruitment/offers".PHP_EOL;
