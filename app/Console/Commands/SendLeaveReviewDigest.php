<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\LeaveReviewBacklog;
use App\Services\WorkInbox;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * T3: once a week, each HR user hears how many decided leave requests still
 * wait for "Seen by HR" in their scope, and how long the oldest has waited.
 * The count is the inbox's own S2 query for the user's active role, so the
 * digest and the inbox always agree. Nothing waiting, nothing sent; at most
 * one digest per user every six days, so a rerun or retry sends nothing.
 */
class SendLeaveReviewDigest extends Command
{
    protected $signature = 'leaves:review-digest';

    protected $description = 'Weekly digest to city HR of decided leave requests still waiting for "Seen by HR"';

    public function handle(WorkInbox $inbox): int
    {
        $sent = 0;

        User::query()
            ->whereHas('roles', fn (Builder $role) => $role->where('name', 'like', 'HR-%'))
            ->each(function (User $user) use ($inbox, &$sent): void {
                $query = $inbox->reviewQuery($user);

                if (! $query) {
                    return;
                }

                $waiting = $query->reorder()
                    ->selectRaw('COUNT(*) AS total, MIN(COALESCE(leave_requests.approved_at, leave_requests.updated_at)) AS oldest')
                    ->toBase()
                    ->first();

                if (! $waiting || (int) $waiting->total === 0) {
                    return;
                }

                $recent = $user->notifications()
                    ->where('type', LeaveReviewBacklog::class)
                    ->where('created_at', '>', now()->subDays(6))
                    ->exists();

                if ($recent) {
                    return;
                }

                try {
                    $oldestDays = (int) Carbon::parse($waiting->oldest)->startOfDay()->diffInDays(today());
                    $user->notify(new LeaveReviewBacklog((int) $waiting->total, $oldestDays));
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Sent the review digest to {$sent} HR user(s).");

        return self::SUCCESS;
    }
}
