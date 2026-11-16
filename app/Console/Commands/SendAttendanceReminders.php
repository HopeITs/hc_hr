<?php

namespace App\Console\Commands;

use App\Models\AttendanceMonth;
use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceMonthReminder;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Timesheet;
use App\Services\NotificationDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Each morning of the first days of a month, nudges the people the last month is waiting for:
 * a manager with timesheets to approve (or days to answer), HR with approved months to lock.
 * Once everything is locked nobody is nudged. Run by hand for another month with --month=YYYY-MM.
 */
class SendAttendanceReminders extends Command
{
    public const LEDGER_APPROVE = 'attendance-approve';

    public const LEDGER_LOCK = 'attendance-lock';

    /** The month is nagged about only this many days into the next one. */
    private const DAYS = 10;

    protected $signature = 'attendance:send-reminders {--month= : The month to nudge about (YYYY-MM); default is last month}';

    protected $description = 'Remind managers to approve and HR to lock last month\'s attendance timesheets';

    public function handle(NotificationDeliveryLedger $ledger, Roster $roster, Timesheet $timesheet): int
    {
        $explicit = $this->option('month');
        $month = $explicit
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $explicit)
            : CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow();

        if (! $month instanceof CarbonImmutable) {
            $this->error('Use --month=YYYY-MM.');

            return self::FAILURE;
        }

        if (! $explicit && now()->day > self::DAYS) {
            $this->info('Past the first days of the month: nothing to do.');

            return self::SUCCESS;
        }

        $day = $ledger->today();
        $label = $month->format('Y-m');
        $people = $this->people($month, $timesheet);

        $sent = 0;

        // Managers: ready to approve, and days that wait for an answer.
        foreach ($people->groupBy('manager_user_id')->except(['']) as $managerUserId => $group) {
            $ready = $group->where('ready_to_approve', true);
            $waiting = $group->where('to_decide', '>', 0);

            if ($ready->isEmpty() && $waiting->isEmpty()) {
                continue;
            }

            $won = $ledger->claim(self::LEDGER_APPROVE, (int) $managerUserId, $group->pluck('employee_id')->all(), $day);

            if ($won === [] || ! ($user = User::query()->find($managerUserId))) {
                continue;
            }

            $user->notify(new AttendanceMonthReminder(AttendanceMonthReminder::APPROVE, $label, $ready->count(), (int) $waiting->sum('to_decide')));
            $sent++;
        }

        // HR of each city: approved, waiting to be locked.
        foreach ($people->where('approved', true)->groupBy('city_slug') as $slug => $group) {
            foreach ($this->hrUsers((string) $slug) as $user) {
                if ($ledger->claim(self::LEDGER_LOCK, $user->id, $group->pluck('employee_id')->all(), $day) === []) {
                    continue;
                }

                $user->notify(new AttendanceMonthReminder(AttendanceMonthReminder::LOCK, $label, $group->count()));
                $sent++;
            }
        }

        $this->info("Sent {$sent} attendance reminder(s) for {$label}.");

        return self::SUCCESS;
    }

    /**
     * Everyone with a contract in the month, with where their month stands.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function people(CarbonImmutable $month, Timesheet $timesheet): Collection
    {
        $employees = Employee::query()
            ->with(['city', 'contracts' => fn ($c) => $c->whereDate('start_date', '<=', $month->endOfMonth())->whereDate('end_date', '>=', $month->startOfMonth())->with('position.directManager.activeContracts.employee.account')])
            ->whereHas('contracts', fn ($c) => $c->whereDate('start_date', '<=', $month->endOfMonth())->whereDate('end_date', '>=', $month->startOfMonth()))
            ->where('is_active', true)
            ->get();

        $records = AttendanceMonth::query()
            ->whereDate('month', $month->toDateString())
            ->get()
            ->keyBy('employee_id');

        return $employees->map(function (Employee $employee) use ($month, $timesheet, $records) {
            $state = ($records[$employee->id] ?? null)?->state() ?? AttendanceMonth::OPEN;
            $toDecide = $state === AttendanceMonth::LOCKED || $state === AttendanceMonth::APPROVED
                ? 0
                : $timesheet->forEmployee($employee, $month)['totals']['to_decide'];
            $contract = $employee->contracts->sortByDesc('start_date')->first();

            return [
                'employee_id' => $employee->id,
                'city_slug' => $employee->city?->slug,
                'manager_user_id' => (string) ($contract?->position?->directManager?->employee()?->account?->id ?? ''),
                'to_decide' => $toDecide,
                'ready_to_approve' => in_array($state, [AttendanceMonth::OPEN, AttendanceMonth::CONFIRMED], true) && $toDecide === 0,
                'approved' => $state === AttendanceMonth::APPROVED,
            ];
        });
    }

    /** @return Collection<int, User> */
    private function hrUsers(string $citySlug): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', City::hrRolesForSlug($citySlug)))
            ->whereHas('profile', fn ($profile) => $profile->where('is_active', true))
            ->get();
    }
}
