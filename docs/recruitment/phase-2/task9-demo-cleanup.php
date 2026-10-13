<?php

/*
 * Phase 2 Task 9: puts the demo offers out of the way — through the service,
 * like everything else. Every demo offer that is still live (draft, sent,
 * revision in progress) is cancelled as "created in error"; the application
 * goes back to "selected". Nothing is deleted, by design.
 *
 * What cannot be undone this way, on purpose:
 *   - an accepted offer is final, and its pending hiring conversion stays
 *     (Task 10 would turn it into an employee and contract);
 *   - refused / expired / cancelled offers keep their history;
 *   - an application can never get a second offer (a change is a revision).
 * For a database exactly as it was before the demo, restore the backup that
 * was taken before running task9-demo.php.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task9-demo-cleanup.php';"
 */

use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJobOffer;
use App\Models\User;
use App\Services\RecruitmentJobOfferService;

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$hr = User::findOrFail(11); // lana.abdo (HR-SYR)
auth()->login($hr);
$offers = app(RecruitmentJobOfferService::class);
$demoApplications = [10, 9, 7, 8, 11, 61, 14];

foreach (RecruitmentJobOffer::query()->whereIn('application_id', $demoApplications)->orderBy('id')->get() as $offer) {
    if (Status::canMove($offer->status, Status::Cancelled)) {
        $offers->cancel($hr, $offer, (int) $offer->current_version_id, 'created_in_error', 'Demo data from task9-demo.php, cancelled after manual testing.');
    }

    $offer->refresh();
    echo "offer #{$offer->id} (application {$offer->application_id}, now ".RecruitmentApplication::find($offer->application_id)->status."): {$offer->status}".PHP_EOL;
}
