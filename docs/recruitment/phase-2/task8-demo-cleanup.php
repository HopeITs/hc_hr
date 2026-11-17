<?php

/*
 * Phase 2 Task 8: puts the demo out of the way — through the services, like
 * everything else. Open "Task 8 demo" interviews are cancelled
 * (scheduled_in_error, no candidate email); their evaluations are withdrawn
 * with a reason. Nothing is deleted, by design: the rows stay, marked.
 * For a truly empty database, restore the backup taken before Task 8.
 *
 *   C:/laragon/bin/php/php-8.5.11/php.exe artisan tinker --execute="require 'docs/recruitment/phase-2/task8-demo-cleanup.php';"
 */

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\User;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewSchedulingService;

config(['queue.default' => 'sync', 'mail.default' => 'log']);
$hr = User::findOrFail(11); // lana.abdo (HR-SYR)
auth()->login($hr);
$evaluations = app(RecruitmentInterviewEvaluationService::class);

foreach (RecruitmentInterview::query()->where('location_value', 'like', 'Task 8 demo%')->get() as $interview) {
    foreach (RecruitmentInterviewEvaluation::query()->where('interview_id', $interview->id)->active()->get() as $evaluation) {
        $evaluations->withdraw($hr, $evaluation, $evaluation->version, 'Demo data from task8-demo.php, withdrawn after manual testing.');
    }

    if ($interview->isOpen()) {
        app(RecruitmentInterviewSchedulingService::class)->cancel($hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), 'scheduled_in_error', 'Demo data from task8-demo.php.', false);
    }

    echo "#{$interview->id}: {$interview->fresh()->status}, evaluations withdrawn".PHP_EOL;
}

echo 'task8-demo.php can now be run again (it only counts demo interviews that are open or still hold active evaluations).'.PHP_EOL;
