<?php

namespace App\Console\Commands;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Notifications\LeaveDecisionReminder;
use App\Services\LeaveDecisionAuthority;
use App\Services\LeaveNotificationService;
use App\Services\NotificationDeliveryLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DEC-05 revision 2 (G-C-1): a pending request the manager has not answered
 * 3 days after it was sent, or 1 day before it starts (whichever comes
 * first), gets ONE in-app reminder to the direct manager, copied to HR in the
 * requester's city. The ledger claim is keyed to the request, not the day, so
 * a later run, a retry or a second worker never reminds again.
 */
class SendLeaveDecisionReminders extends Command
{
    public const LEDGER_KIND = 'leave-decision-reminder';

    private const AFTER_DAYS = 3;

    protected $signature = 'leaves:send-decision-reminders';

    protected $description = 'Remind direct managers (copy to city HR) once about leave requests still awaiting their decision';

    public function handle(NotificationDeliveryLedger $ledger, LeaveNotificationService $notifications): int
    {
        $today = $ledger->today();
        $sent = 0;

        $authority = app(LeaveDecisionAuthority::class);

        $this->dueRequests($today)->each(function (LeaveRequest $leave) use ($ledger, $notifications, $authority, &$sent): void {
            // Once per request: the claim day is the day it was sent.
            $day = $leave->created_at->copy()->timezone(config('app.timezone'))->startOfDay();
            $audience = $notifications->newRequestAudience($leave, $leave->leaveBalance->contract->employee->account);

            foreach (['manager' => $audience['managers'], 'hr' => $audience['hr']] as $group => $users) {
                foreach ($users as $user) {
                    // HR that decides itself (no direct manager, or every one on
                    // leave) is reminded to decide, not told the manager was (G-C-18).
                    $type = $group === 'hr' && $authority->basisFor($user, $leave) !== null ? 'hr_decider' : $group;

                    if (! $ledger->claim(self::LEDGER_KIND, $user->id, [$leave->id], $day)) {
                        continue;
                    }

                    try {
                        $user->notify(new LeaveDecisionReminder($leave, $type));
                        $ledger->markDelivered(self::LEDGER_KIND, $user->id, [$leave->id], $day);
                        $sent++;
                    } catch (Throwable $e) {
                        $ledger->release(self::LEDGER_KIND, $user->id, [$leave->id], $day);
                        report($e);
                    }
                }
            }
        });

        $this->info("Sent {$sent} leave decision reminder(s).");

        return self::SUCCESS;
    }

    /** Pending, due, of an active employee, and never reminded before. */
    private function dueRequests(\Carbon\CarbonInterface $today)
    {
        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->where(fn (Builder $due) => $due
                ->where('created_at', '<=', now()->subDays(self::AFTER_DAYS))
                ->orWhereDate('start_at', '<=', $today->copy()->addDay()->toDateString()))
            ->whereHas('leaveBalance.contract.employee', fn (Builder $employee) => $employee->where('is_active', true))
            ->whereNotIn('id', DB::table('notification_deliveries')->where('kind', self::LEDGER_KIND)->select('subject_id'))
            ->with([
                'leaveBalance.leaveType',
                'leaveBalance.contract.employee.account',
                'leaveBalance.contract.employee.city',
                'leaveBalance.contract.position.directManager.activeContracts.employee.account',
            ])
            ->orderBy('id')
            ->get();
    }
}
