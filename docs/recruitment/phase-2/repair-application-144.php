<?php

/*
 * ONE-OFF DATA REPAIR — application 144 (applicant 182, «فريال نعمة»).
 *
 * What went wrong (2026-09-30 11:10): the hire page linked this candidate to
 * employee 380 (Rafi Louliki) only because the phone number she typed is his,
 * and created contract 376 on position 181 for HIM. The identity rule of the
 * Tasks 7-10 review (Point 4b) now forbids such a link. The conversion row
 * (#74) was later deleted outside the app, so the offer shows neither a hire
 * nor a pending one.
 *
 * What this puts back: "offer accepted, waiting to be hired", so HR can hire
 * her again through the fixed page. In ONE transaction:
 *
 *   1. contract 376 (employee 380, position 181, 2026-10-01 → 2026-12-31) is
 *      deleted: the ContractObserver gives the seat back (position 181
 *      exist_emp 2 → 1) and its 7 unused leave balances go with it (ON DELETE
 *      CASCADE; no leave request uses them). With $contractAction =
 *      'deactivate' it is kept, switched off and the seat released instead,
 *      as `php artisan deactivate:contract` does.
 *   2. vacancy 107: filled_count 1 → 0 (it stays open; it needs 2).
 *   3. application 144: hired → offer_accepted, with a decision row (reason
 *      code "data_repair", the reason in words) and its audit entry.
 *   4. applicant 182: employee_id 380 → null. She never signed in as staff
 *      (identity_verified_at is null); the link came from the wrong hire.
 *   5. offer 90: a pending hiring conversion again, rebuilt exactly as Task 9
 *      writes it on acceptance (RecruitmentJobOfferService::handOverToConversion).
 *   6. an audit entry: recruitment_hiring_conversion.repaired.
 *
 * Not touched: employee 380 and his contracts 157 and 364; her other
 * application (216); the audit of the wrong hire (activity 4158), which stays
 * as history.
 *
 * Every row is locked and checked against what was found on 2026-09-30; if
 * anything differs, nothing is changed.
 *
 * DRY RUN (the default): checks, applies inside the transaction, prints the
 * result, then ROLLS BACK. Nothing is kept and no backup is taken.
 *   php artisan tinker --execute="require 'docs/recruitment/phase-2/repair-application-144.php';"
 *
 * APPLY (only after approval): a mysqldump backup of the whole database is
 * taken first and checked; if it fails, nothing is changed. Then the same
 * transaction is committed. $actorId is the user recorded as doing the repair.
 *   php artisan tinker --execute="\$apply = true; \$actorId = <your user id>; require 'docs/recruitment/phase-2/repair-application-144.php';"
 *
 * Optional, set the same way: $contractAction = 'deactivate';
 * $mysqldump = '<path to mysqldump>'; $backupDir = '<folder>'.
 */

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as OfferStatus;
use App\Models\Contract;
use App\Models\Position;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\RecruitmentJobOfferVersion as Version;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ActivityLogger;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentHiringConversionService as Conversions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// ---------------------------------------------------------------- settings
$apply = ($apply ?? false) === true;
$actorId = isset($actorId) ? (int) $actorId : null;
$contractAction = $contractAction ?? 'delete';
$mysqldump = $mysqldump ?? 'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe';
$backupDir = $backupDir ?? storage_path('app/backups'); // storage/app is git-ignored

// What was found on 2026-09-30 — the repair runs only if it is still so.
$ids = [
    'application' => 144, 'applicant' => 182, 'offer' => 90, 'version' => 102,
    'contract' => 376, 'employee' => 380, 'position' => 181, 'vacancy' => 107, 'wrongHireAudit' => 4158,
];

$stop = function (string $why): never {
    throw new RuntimeException('STOPPED, nothing changed: '.$why);
};
$require = function (bool $ok, string $why) use ($stop): void {
    if (! $ok) {
        $stop($why);
    }
};
$line = fn (string $text) => print $text.PHP_EOL;

$line(($apply ? 'APPLY' : 'DRY RUN').' — repair of application '.$ids['application'].' (contract action: '.$contractAction.')');

if (! in_array($contractAction, ['delete', 'deactivate'], true)) {
    $stop('$contractAction must be "delete" or "deactivate".');
}

if ($apply && ($actorId === null || User::query()->whereKey($actorId)->doesntExist())) {
    $stop('set $actorId to the id of the user doing the repair.');
}

$actor = User::query()->findOrFail($actorId ?? Offer::query()->whereKey($ids['offer'])->value('response_recorded_by_user_id'));
$line('Recorded as done by user '.$actor->id.' ('.$actor->email.').');

// ---------------------------------------------------------------- backup (apply only)
if ($apply) {
    $connection = config('database.connections.'.config('database.default'));
    if (($connection['driver'] ?? null) !== 'mysql') {
        $stop('the backup step expects MySQL.');
    }

    if (! is_dir($backupDir) && ! mkdir($backupDir, 0775, true) && ! is_dir($backupDir)) {
        $stop('cannot create the backup folder '.$backupDir);
    }

    $backupFile = rtrim($backupDir, '/\\').DIRECTORY_SEPARATOR.$connection['database'].'-before-repair-application-144-'.now()->format('Ymd_His').'.sql';
    $dump = new Process([
        $mysqldump,
        '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'],
        '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
        '--result-file='.$backupFile,
        $connection['database'],
    ], null, ['MYSQL_PWD' => (string) $connection['password']], null, 900);
    $dump->run();

    $tail = is_file($backupFile) ? file_get_contents($backupFile, false, null, max(0, filesize($backupFile) - 200)) : '';
    if (! $dump->isSuccessful() || ! is_file($backupFile) || filesize($backupFile) === 0 || ! str_contains((string) $tail, 'Dump completed')) {
        $stop('the backup failed: '.trim($dump->getErrorOutput()));
    }

    $line('Backup: '.$backupFile.' ('.number_format(filesize($backupFile)).' bytes, complete).');
}

// ---------------------------------------------------------------- the repair, in one transaction
$activity = app(ActivityLogger::class);

DB::beginTransaction();

try {
    // Locked in the app's own order: application, offer, then the rest.
    $application = RecruitmentApplication::query()->whereKey($ids['application'])->lockForUpdate()->first();
    $offer = Offer::query()->whereKey($ids['offer'])->lockForUpdate()->first();
    $version = Version::query()->whereKey($ids['version'])->lockForUpdate()->first();
    $vacancy = Vacancy::query()->whereKey($ids['vacancy'])->lockForUpdate()->first();
    $position = Position::query()->whereKey($ids['position'])->lockForUpdate()->first();
    $contract = Contract::query()->whereKey($ids['contract'])->lockForUpdate()->first();
    $applicant = RecruitmentApplicant::query()->whereKey($ids['applicant'])->lockForUpdate()->first();
    $wrongHire = DB::table('activity_log')->where('id', $ids['wrongHireAudit'])->first();

    // ---- still exactly what was found?
    $require($application !== null && (int) $application->applicant_id === $ids['applicant']
        && $application->status === AppStatus::Hired && (int) $application->vacancy_id === $ids['vacancy'],
        'application 144 is not "hired" for applicant 182 on vacancy 107 any more.');
    $require($offer !== null && (int) $offer->application_id === $ids['application'] && $offer->status === OfferStatus::Accepted
        && (int) $offer->current_version_id === $ids['version'] && (int) $offer->vacancy_id === $ids['vacancy'] && (int) $offer->position_id === $ids['position'],
        'offer 90 is not the accepted offer (version 102) on vacancy 107 / position 181 any more.');
    $require($version !== null && $version->status === Version::STATUS_ACCEPTED, 'version 102 is not the accepted version.');
    $require(Conversion::query()->where('job_offer_id', $ids['offer'])->doesntExist(), 'offer 90 already has a hiring conversion row.');
    $require($contract !== null && (int) $contract->employee_id === $ids['employee'] && (int) $contract->position_id === $ids['position'] && $contract->is_active,
        'contract 376 is not the active contract of employee 380 on position 181 any more.');
    $require($applicant !== null && (int) $applicant->employee_id === $ids['employee'] && $applicant->identity_verified_at === null && $applicant->anonymized_at === null,
        'applicant 182 is not linked to employee 380 without a sign-in any more.');
    $require($vacancy !== null && (int) $vacancy->filled_count >= 1, 'vacancy 107 has no filled seat to give back.');
    $require($position !== null && (int) $position->exist_emp >= 1, 'position 181 has no counted employee to give back.');

    $wrongProps = json_decode((string) $wrongHire?->properties, true) ?: [];
    $require((int) ($wrongProps['contract_id'] ?? 0) === $ids['contract'] && (int) ($wrongProps['employee_id'] ?? 0) === $ids['employee'] && ($wrongProps['mode'] ?? null) === 'link',
        'activity 4158 is not the record of the wrong hire (link, employee 380, contract 376).');

    // Nothing else may rest on contract 376 (its leave balances go with it).
    $balanceIds = DB::table('leave_balances')->where('contract_id', $ids['contract'])->pluck('id');
    $require(DB::table('leave_requests')->whereIn('leave_balance_id', $balanceIds)->doesntExist(), 'a leave request uses a leave balance of contract 376.');
    $require(DB::table('evaluations')->where('contract_id', $ids['contract'])->doesntExist(), 'an evaluation belongs to contract 376.');
    $require(DB::table('contract_pay_tiers')->where('contract_id', $ids['contract'])->doesntExist(), 'contract 376 has pay tiers.');
    if ($contract->start_date->lte(today())) {
        $line('NOTE: contract 376 started on '.$contract->start_date->toDateString().'; it is still the wrong person\'s contract.');
    }

    $before = [
        'contract 376' => ['employee' => $contract->employee_id, 'position' => $contract->position_id, 'active' => (bool) $contract->is_active, 'leave balances' => $balanceIds->count()],
        'position 181 exist_emp' => (int) $position->exist_emp,
        'vacancy 107' => ['status' => $vacancy->status, 'filled' => (int) $vacancy->filled_count, 'needed' => (int) $vacancy->needed_count],
        'application 144' => $application->status,
        'applicant 182 employee_id' => $applicant->employee_id,
        'offer 90 conversion' => 'none',
    ];

    $reason = 'Data repair: the hire of 2026-09-30 linked this candidate to employee 380 (Rafi Louliki) only because her phone number is his. '
        .'Contract 376 was '.($contractAction === 'delete' ? 'removed' : 'deactivated').'; the application is back to "offer accepted", to be hired again.';

    // ---- 1. contract 376
    if ($contractAction === 'delete') {
        $contract->delete(); // ContractObserver::deleted() releases the seat; leave balances cascade
    } else {
        $contract->update(['is_active' => false, 'terminated_at' => today(), 'reason_of_termination' => $reason]);
        Position::query()->whereKey($ids['position'])->lockForUpdate()->first()?->decrement('exist_emp'); // as deactivate:contract does
    }

    // ---- 2. vacancy 107
    $vacancy->forceFill(['filled_count' => (int) $vacancy->filled_count - 1])->save();

    // ---- 3. application 144, as RecruitmentApplicationStatusService::record() writes a move
    RecruitmentApplication::applyDecision($application, AppStatus::OfferAccepted);
    $decision = RecruitmentApplicationDecision::query()->forceCreate([
        'application_id' => $application->getKey(),
        'from_status' => AppStatus::Hired,
        'to_status' => AppStatus::OfferAccepted,
        'reason_code' => 'data_repair',
        'reason_text' => $reason,
        'decided_by_user_id' => $actor->getKey(),
        'decided_at' => now(),
    ]);
    $activity->log('recruitment_application.status_changed', subject: $application, causer: $actor, properties: [
        'from' => AppStatus::Hired, 'to' => AppStatus::OfferAccepted, 'decision_id' => $decision->getKey(),
        'reason_code' => 'data_repair', 'bulk' => false, 'role' => $actor->myRole(),
    ], logName: RecruitmentApplicationStatusService::AUDIT_LOG);

    // ---- 4. applicant 182
    $applicant->forceFill(['employee_id' => null])->save();

    // ---- 5. offer 90: pending again, as handOverToConversion() writes it
    $conversion = Conversion::query()->forceCreate([
        'job_offer_id' => $offer->getKey(),
        'job_offer_version_id' => $version->getKey(),
        'applicant_id' => $applicant->getKey(),
        'employee_payload_json' => [
            'full_name' => $applicant->full_name,
            'email' => $application->email_normalized,
            'phone' => $applicant->phone_normalized,
            'city_id' => $applicant->city_id,
            'position_id' => $offer->position_id,
            'vacancy_id' => $offer->vacancy_id,
        ],
        'status' => Conversion::STATUS_PENDING,
        'started_at' => $offer->accepted_at,
        'converted_by_user_id' => $offer->response_recorded_by_user_id,
    ]);

    // ---- 6. audit
    $activity->log('recruitment_hiring_conversion.repaired', subject: $conversion, causer: $actor, properties: [
        'job_offer_id' => $offer->getKey(),
        'job_offer_version_id' => $version->getKey(),
        'application_id' => $application->getKey(),
        'wrong_hire_activity_id' => $ids['wrongHireAudit'],
        'wrong_conversion_id' => $wrongHire->subject_id,
        'unlinked_employee_id' => $ids['employee'],
        'contract_id' => $ids['contract'],
        'contract_action' => $contractAction,
        'decision_id' => $decision->getKey(),
        'reason' => $reason,
        'role' => $actor->myRole(),
    ], logName: Conversions::AUDIT_LOG);

    // ---- the result, checked before anything is kept
    $contractAfter = Contract::query()->find($ids['contract']);
    $after = [
        'contract 376' => $contractAfter === null ? 'deleted' : ['active' => (bool) $contractAfter->is_active],
        'leave balances of 376' => DB::table('leave_balances')->where('contract_id', $ids['contract'])->count(),
        'position 181 exist_emp' => (int) Position::query()->whereKey($ids['position'])->value('exist_emp'),
        'vacancy 107' => (array) DB::table('vacancies')->where('id', $ids['vacancy'])->first(['status', 'filled_count', 'needed_count']),
        'application 144' => RecruitmentApplication::query()->whereKey($ids['application'])->value('status'),
        'applicant 182 employee_id' => RecruitmentApplicant::query()->whereKey($ids['applicant'])->value('employee_id'),
        'offer 90 conversion' => ['id' => $conversion->id, 'status' => $conversion->status],
        'employee 380 contracts' => Contract::query()->where('employee_id', $ids['employee'])->orderBy('id')->pluck('id')->all(),
    ];

    $require($after['position 181 exist_emp'] === $before['position 181 exist_emp'] - 1, 'position 181 exist_emp did not go down by one.');
    $require((int) $after['vacancy 107']['filled_count'] === $before['vacancy 107']['filled'] - 1, 'vacancy 107 filled_count did not go down by one.');
    $require($after['application 144'] === AppStatus::OfferAccepted, 'application 144 is not back to offer_accepted.');
    $require($after['applicant 182 employee_id'] === null, 'applicant 182 is still linked.');
    $require($contractAction === 'deactivate' ? $contractAfter?->is_active === false : $contractAfter === null, 'contract 376 is still an active contract.');

    // What the fixed hire page will now say about her.
    $conversions = app(Conversions::class);
    $after['blockers of the new conversion'] = $conversions->blockers($conversion);
    $after['matches now (identity rule)'] = $conversions->matches($conversion)
        ->map(fn (array $match): string => $match['employee']->username.' by '.implode('+', $match['reasons']).' → '.$match['kind'])->all();

    $line('BEFORE: '.json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $line('AFTER:  '.json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    if ($apply) {
        DB::commit();
        $line('COMMITTED. Application 144 waits to be hired again on /recruitment/offers/90/convert.');
    } else {
        DB::rollBack();
        $line('DRY RUN — rolled back. Nothing was changed.');
    }
} catch (Throwable $exception) {
    DB::rollBack();
    $line($exception->getMessage());
    $line('Rolled back. Nothing was changed.');
}
