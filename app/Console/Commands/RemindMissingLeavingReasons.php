<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\LeavingReasonsMissing;
use App\Services\ProbationReviews;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tells HR of each city about people who left 30 to 365 days ago with no
 * termination reason on the contract. At most one note per HR user every 25
 * days, so a second run or a retry sends nothing. Older gaps are left to the
 * report itself: nagging about them for ever would only train people to ignore it.
 */
class RemindMissingLeavingReasons extends Command
{
    protected $signature = 'leavers:remind-missing-reasons';

    protected $description = 'Remind HR to record the termination reason of people who left over a month ago';

    public function handle(ProbationReviews $people): int
    {
        $contracts = Contract::query()
            ->latestPerEmployee()
            ->whereNull('contracts.termination_reason_id')
            ->whereNotNull('contracts.terminated_at')
            ->whereDate('contracts.terminated_at', '<=', now()->subDays(30)->toDateString())
            ->whereDate('contracts.terminated_at', '>=', now()->subDays(365)->toDateString())
            ->with('employee.city')
            ->get();

        /** @var array<int, array{user: User, count: int}> $perUser */
        $perUser = [];
        foreach ($contracts as $contract) {
            foreach ($people->hrUsers($contract->employee) as $user) {
                $perUser[$user->id]['user'] = $user;
                $perUser[$user->id]['count'] = ($perUser[$user->id]['count'] ?? 0) + 1;
            }
        }

        $sent = 0;
        foreach ($perUser as $row) {
            $recent = $row['user']->notifications()
                ->where('type', LeavingReasonsMissing::class)
                ->where('created_at', '>', now()->subDays(25))
                ->exists();

            if ($recent) {
                continue;
            }

            try {
                $row['user']->notify(new LeavingReasonsMissing($row['count']));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Reminded {$sent} HR user(s) about {$contracts->count()} leaver(s) with no reason.");

        return self::SUCCESS;
    }
}
