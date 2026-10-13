<?php

namespace App\Console\Commands;

use App\Enums\RecruitmentRequestStatus as Status;
use App\Models\RecruitmentRequest;
use App\Services\RecruitmentRequestApplicationService;
use Illuminate\Console\Command;

/**
 * Carries out approved requests whose effective date has arrived.
 *
 * A reduction or a closure dated in the future is approved and then waits.
 * Something has to notice the date passing, and this is it. It also picks up
 * requests whose application failed earlier, so a transient failure — a locked
 * row, a position briefly held by another write — heals overnight instead of
 * needing somebody to find it.
 *
 * Running it twice in a day changes nothing: the service refuses to apply a
 * request that is already applied.
 */
class ApplyDueRecruitmentRequests extends Command
{
    protected $signature = 'recruitment:apply-due';

    protected $description = 'Apply approved recruitment requests whose effective date has arrived';

    public function handle(RecruitmentRequestApplicationService $applications): int
    {
        $pending = RecruitmentRequest::query()
            ->whereIn('status', [Status::Approved, Status::FailedApplication])
            ->orderBy('id')
            ->get();

        $applied = 0;
        $waiting = 0;
        $failed = 0;

        foreach ($pending as $request) {
            if (! $applications->isDue($request)) {
                $waiting++;

                continue;
            }

            // No actor: this is the schedule acting, not a person, and the
            // event records that rather than attributing it to somebody.
            $result = $applications->apply($request);

            match ((int) $result->status) {
                Status::Applied => $applied++,
                default => $failed++,
            };
        }

        $this->info("Applied {$applied}, still waiting on a date {$waiting}, failed {$failed}.");

        return self::SUCCESS;
    }
}
