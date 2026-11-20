<?php

namespace App\Services\Attendance;

use App\Enums\ContractType;
use App\Enums\LeaveTypeCases;
use App\Models\AttendanceMonth;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The life of a person's month: the employee may confirm it, the manager (or HR) approves it,
 * HR locks it. Approving approves the overtime; locking gives it back as free days, added to the
 * annual leave balance as overtime credit.
 *
 * Nobody approves or decides their own month. A change to an approved month (a decision, new
 * punches) takes the approval back, so what was approved is what is locked. A locked month does
 * not change; HR reopens it, which takes the credit back unless it has been used.
 */
class MonthWorkflow
{
    public function __construct(
        private readonly Roster $roster,
        private readonly Timesheet $timesheet,
    ) {}

    /**
     * What this user may do with this person's month now.
     *
     * @return array{decide: bool, confirm: bool, approve: bool, lock: bool, reopen: bool, signed: bool}
     */
    public function abilities(User $user, Employee $employee, CarbonImmutable $month, AttendanceMonth $record, int $toDecide, ?bool $covers = null): array
    {
        $state = $record->state();
        $locked = $state === AttendanceMonth::LOCKED;
        $covers ??= $this->roster->covers($user, $employee, $month);
        $own = $user->employee_id !== null && (int) $user->employee_id === (int) $employee->id;
        $supervises = $covers && ! $own && in_array($this->roster->mode($user), [Roster::TEAM, Roster::CITY, Roster::ALL], true);
        $manages = $covers && $user->hasActivePermission('attendance.manage');
        $ready = $toDecide === 0;
        $needsConfirmation = (bool) config('attendance.require_employee_confirmation');

        return [
            'decide' => $supervises && ! $locked,
            'confirm' => $own && $user->hasActivePermission('attendance.view-own') && $state === AttendanceMonth::OPEN && $ready,
            'approve' => $supervises && $ready
                && ($needsConfirmation ? $state === AttendanceMonth::CONFIRMED : in_array($state, [AttendanceMonth::OPEN, AttendanceMonth::CONFIRMED], true)),
            'lock' => $manages && $state === AttendanceMonth::APPROVED,
            'reopen' => $manages && $state !== AttendanceMonth::OPEN,
            'signed' => $manages,
        ];
    }

    public function confirm(User $user, Employee $employee, CarbonImmutable $month): AttendanceMonth
    {
        $record = $this->allowed('confirm', $user, $employee, $month);

        $record->forceFill(['employee_confirmed_at' => now(), 'employee_confirmed_by' => $user->id])->save();

        return $record;
    }

    public function approve(User $user, Employee $employee, CarbonImmutable $month): AttendanceMonth
    {
        $record = $this->allowed('approve', $user, $employee, $month);
        $totals = $this->timesheet->forEmployee($employee, $month)['totals'];

        $record->forceFill([
            'approved_at' => now(),
            'approved_by' => $user->id,
            'overtime_hours_approved' => $totals['overtime_hours'],
        ])->save();

        return $record;
    }

    public function lock(User $user, Employee $employee, CarbonImmutable $month): AttendanceMonth
    {
        $record = $this->allowed('lock', $user, $employee, $month);

        return DB::transaction(function () use ($record, $user, $employee, $month) {
            $record->forceFill(['locked_at' => now(), 'locked_by' => $user->id]);
            $this->credit($record, $employee, $month);
            $record->save();

            return $record;
        });
    }

    public function reopen(User $user, Employee $employee, CarbonImmutable $month): AttendanceMonth
    {
        $record = $this->allowed('reopen', $user, $employee, $month);

        return DB::transaction(function () use ($record) {
            $this->takeCreditBack($record);

            $record->forceFill([
                'employee_confirmed_at' => null, 'employee_confirmed_by' => null,
                'approved_at' => null, 'approved_by' => null, 'overtime_hours_approved' => 0,
                'locked_at' => null, 'locked_by' => null,
            ])->save();

            return $record;
        });
    }

    /** A decision or new punches changed an approved month: the approval no longer holds. */
    public function withdrawApproval(Employee|int $employee, CarbonImmutable $month): void
    {
        AttendanceMonth::query()
            ->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)
            ->whereDate('month', $month->startOfMonth()->toDateString())
            ->whereNotNull('approved_at')
            ->whereNull('locked_at')
            ->update([
                'employee_confirmed_at' => null, 'employee_confirmed_by' => null,
                'approved_at' => null, 'approved_by' => null, 'overtime_hours_approved' => 0,
            ]);
    }

    public function isLocked(Employee|int $employee, CarbonImmutable $month): bool
    {
        return AttendanceMonth::query()
            ->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)
            ->whereDate('month', $month->startOfMonth()->toDateString())
            ->whereNotNull('locked_at')
            ->exists();
    }

    private function allowed(string $ability, User $user, Employee $employee, CarbonImmutable $month): AttendanceMonth
    {
        $record = AttendanceMonth::for($employee, $month);
        $toDecide = $this->timesheet->forEmployee($employee, $month)['totals']['to_decide'];

        if (! ($this->abilities($user, $employee, $month, $record, $toDecide)[$ability] ?? false)) {
            // Name the reason a person can act on: days still waiting come before "not allowed".
            throw WorkflowRefused::because($toDecide > 0 && in_array($ability, ['confirm', 'approve'], true) ? 'days-waiting' : $ability);
        }

        return $record;
    }

    /** Give the approved overtime back as free days: overtime credit on the annual balance. */
    private function credit(AttendanceMonth $record, Employee $employee, CarbonImmutable $month): void
    {
        $hours = (float) $record->overtime_hours_approved;

        if ($hours <= 0) {
            return;
        }

        $contract = Contract::query()
            ->where('employee_id', $employee->id)
            ->where('type', '!=', ContractType::Draft)
            ->whereDate('start_date', '<=', $month->endOfMonth())
            ->whereDate('end_date', '>=', $month->startOfMonth())
            ->orderByDesc('start_date')
            ->first();

        // A service agreement has no leave balance: the overtime stays on the timesheet only.
        $balance = $contract
            ? LeaveBalance::query()->where('contract_id', $contract->id)->where('type_id', LeaveTypeCases::Annual)->lockForUpdate()->first()
            : null;

        if ($balance === null) {
            return;
        }

        $balance->update(['overtime_credit' => round($balance->creditHours() + $hours, 2)]);

        $record->forceFill(['credited_hours' => $hours, 'credited_balance_id' => $balance->id, 'credited_at' => now()]);
    }

    private function takeCreditBack(AttendanceMonth $record): void
    {
        $hours = (float) $record->credited_hours;

        if ($hours <= 0 || $record->credited_balance_id === null) {
            return;
        }

        $balance = LeaveBalance::query()->lockForUpdate()->find($record->credited_balance_id);

        if ($balance !== null) {
            // Free days already taken as leave cannot be taken back.
            if ($balance->availableHours() < $hours) {
                throw WorkflowRefused::because('credit-used');
            }

            $balance->update(['overtime_credit' => max(0, round($balance->creditHours() - $hours, 2))]);
        }

        $record->forceFill(['credited_hours' => 0, 'credited_balance_id' => null, 'credited_at' => null]);
    }
}
