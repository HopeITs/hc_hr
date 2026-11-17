<?php

/*
 * Phase 2 Task 7: creates real interview data to test by hand.
 * Unlike phase2-integration-check.php, NOTHING here is rolled back.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task7-demo.php';"
 *
 * What it does, through the real services (same rules as the UI):
 *   1. Shortlists two submitted applications (decision history included).
 *   2. Schedules for the first: an online HR interview tomorrow (two
 *      participants) and an in-person technical interview in 3 days.
 *   3. Schedules for the second: a final interview 26 hours from now, so its
 *      24h reminder becomes due in about 2 hours
 *      (php artisan recruitment:interview-reminders).
 *   4. Prints the pages to open.
 *
 * Safe to run twice: an application that already has an open interview is
 * skipped. Emails run in this process (sync queue) through the "log" mailer,
 * so they are written to storage/logs/laravel.log and nothing is sent.
 *
 * Interviews cannot be deleted (by design). To undo, restore the database
 * backup taken before Task 7, or cancel the interviews from the UI.
 */

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewSchedulingService;

// ---- Adjust if needed -------------------------------------------------
$actorId = 11;                // HR who schedules (HR-SYR: lana.abdo)
$participantIds = [1, 380];   // interviewer, observer (fredy.youssef, rafi.louliki)
$applicationIds = [3, 9];     // submitted applications to use
// -----------------------------------------------------------------------

config(['queue.default' => 'sync', 'mail.default' => 'log']);

$actor = User::findOrFail($actorId);
auth()->login($actor);
$statuses = app(RecruitmentApplicationStatusService::class);
$scheduling = app(RecruitmentInterviewSchedulingService::class);
$local = fn ($c) => $c->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i');
$base = rtrim(config('app.url'), '/').'/en';

[$interviewer, $observer] = $participantIds;
$plans = [
    $applicationIds[0] => [
        ['type' => 'hr', 'startsAt' => $local(now()->addDay()->setTime(10, 0)), 'duration' => 60,
            'locationType' => 'online', 'locationValue' => 'Microsoft Teams',
            'meetingUrl' => 'https://teams.microsoft.com/l/meetup-join/demo-task7-'.uniqid(),
            'participants' => [$interviewer => 'interviewer', $observer => 'observer']],
        ['type' => 'technical', 'startsAt' => $local(now()->addDays(3)->setTime(12, 30)), 'duration' => 90,
            'locationType' => 'in_person', 'locationValue' => 'Damascus office, meeting room 2',
            'participants' => [$interviewer => 'interviewer']],
    ],
    $applicationIds[1] => [
        ['type' => 'final', 'startsAt' => $local(now()->addHours(26)->startOfHour()), 'duration' => 45,
            'locationType' => 'online', 'locationValue' => 'Zoom',
            'meetingUrl' => 'https://zoom.us/j/9990001112',
            'participants' => [$interviewer => 'interviewer']],
    ],
];

foreach ($plans as $applicationId => $interviews) {
    $application = RecruitmentApplication::with('applicant')->find($applicationId);

    if ($application === null) {
        echo "Application {$applicationId}: not found, skipped.".PHP_EOL;

        continue;
    }

    if (RecruitmentInterview::where('application_id', $applicationId)->open()->exists()) {
        echo "Application {$applicationId}: already has open interviews, skipped.".PHP_EOL;

        continue;
    }

    if ($application->status === AppStatus::Submitted) {
        $statuses->change($actor, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        echo "Application {$applicationId} ({$application->applicant->full_name}): shortlisted.".PHP_EOL;
    }

    foreach ($interviews as $input) {
        $interview = $scheduling->schedule($actor, $application->fresh(), $input);
        echo "  Interview {$interview->id}: {$input['type']} at {$input['startsAt']} ({$input['locationType']})".PHP_EOL
            ."    {$base}/recruitment/interviews/{$interview->id}".PHP_EOL;
    }

    echo '  Application status now: '.$application->fresh()->status.PHP_EOL
        ."  Applicant profile: {$base}/recruitment/applicants/{$application->applicant_id}".PHP_EOL;
}

echo PHP_EOL.'Interviews list / calendar: '.$base.'/recruitment/interviews'.PHP_EOL;
echo 'Candidate + participant emails were written to storage/logs/laravel.log'.PHP_EOL;
