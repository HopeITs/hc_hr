<?php

namespace App\Services\Attendance;

use App\Enums\ContractType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\AttendanceDayDecision;
use App\Models\AttendancePunch;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveRequestPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One employee's month, day by day, in the terms of the printed Monthly TimeSheet: the leave
 * type and hours, the overtime and the working days.
 *
 * Where each figure comes from:
 *  - the days the contract covers, and its daily hours: the contract;
 *  - weekends: the center's weekend setting, plus the days the contract's work pattern
 *    leaves out on that day (Contract::workWeekdaysOn()); holidays: the Holidays page;
 *  - leave: accepted leave requests (a full day or an hourly leave);
 *  - worked or not, and the time at work: the uploaded punches (first to last of the day);
 *  - a working day with no record, or only one punch: nothing is assumed, it waits for HR
 *    (AttendanceDayDecision) and the day counts for no working day until then.
 *
 * Overtime is not paid; it is shown in hours and as free days (hours / daily hours). It is not
 * added to a leave balance here.
 */
class Timesheet
{
    public const OUTSIDE = 'outside';

    public const WEEKEND = 'weekend';

    public const HOLIDAY = 'holiday';

    public const LEAVE = 'leave';

    public const WORKED = 'worked';

    public const ONE_PUNCH = 'one_punch';

    public const NO_RECORD = 'no_record';

    /** A working day still ahead: nothing to decide yet, and it counts for nothing. */
    public const UPCOMING = 'upcoming';

    public const DECIDED_WORKED = 'decided_worked';

    public const DECIDED_ABSENT = 'decided_absent';

    /** One for the whole page, so the program centers' weekend is read once (it caches). */
    private ?LeaveRequestPricing $pricing = null;

    /**
     * @return array{
     *     month: CarbonImmutable,
     *     daily_hours: float,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, float|int>,
     *     balances: list<array<string, mixed>>
     * }
     */
    public function forEmployee(Employee $employee, CarbonImmutable $month): array
    {
        return $this->forEmployees(collect([$employee]), $month)[$employee->id];
    }

    /**
     * The same sheets for many people at once, in a handful of queries however many there are
     * (contracts, punches, decisions, leave, balances, holidays), instead of a set per person.
     * Each sheet is exactly what forEmployee() returns for that person.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>> the sheets, by employee id
     */
    public function forEmployees(Collection $employees, CarbonImmutable $month): array
    {
        $ids = $employees->map(fn (Employee $employee) => $employee->id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $first = $month->startOfMonth();
        $last = $month->endOfMonth()->startOfDay();

        $contracts = Contract::query()
            ->with('position.center', 'position.program', 'position.department', 'workPatterns')
            ->whereIn('employee_id', $ids)
            ->where('type', '!=', ContractType::Draft)
            ->whereDate('start_date', '<=', $last)
            ->whereDate('end_date', '>=', $first)
            ->orderBy('start_date')
            ->get()
            ->groupBy('employee_id');

        $punches = [];

        AttendancePunch::query()
            ->whereIn('employee_id', $ids)
            ->where('punched_at', '>=', $first->startOfDay())
            ->where('punched_at', '<=', $last->endOfDay())
            ->orderBy('punched_at')
            ->get(['employee_id', 'punched_at'])
            ->each(function (AttendancePunch $punch) use (&$punches) {
                $at = CarbonImmutable::instance($punch->punched_at);
                $punches[$punch->employee_id][$at->toDateString()][] = $at;
            });

        $decisions = AttendanceDayDecision::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('work_date', '>=', $first)
            ->whereDate('work_date', '<=', $last)
            ->get()
            ->groupBy('employee_id');

        $leaves = LeaveRequest::query()
            ->with('leaveBalance.leaveType', 'leaveBalance.contract:id,employee_id')
            // A leave that is asked for (pending) already answers the day: HR never decides it; the print waits for its answer.
            ->whereIn('status', [LeaveRequestStatus::Accepted, LeaveRequestStatus::Pending])
            ->whereHas('leaveBalance.contract', fn ($contract) => $contract->whereIn('employee_id', $ids))
            ->whereDate('start_at', '<=', $last)
            ->whereDate('end_at', '>=', $first)
            ->get()
            ->groupBy(fn (LeaveRequest $leave) => $leave->leaveBalance?->contract?->employee_id);

        // The balances page 2 shows are those of each person's latest contract in the month.
        $latest = $contracts->map(fn (Collection $all) => $all->sortByDesc('start_date')->first()?->id)->filter();
        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('contract_id', $latest->values())
            ->orderBy('type_id')
            ->get()
            ->groupBy('contract_id');

        $unpaid = LeaveType::query()->find(LeaveTypeCases::Unpaid);
        $holidayCache = [];
        $sheets = [];

        foreach ($employees as $employee) {
            $sheets[$employee->id] = $this->build(
                $first,
                $last,
                $contracts->get($employee->id, collect()),
                $punches[$employee->id] ?? [],
                ($decisions->get($employee->id) ?? collect())->keyBy(fn (AttendanceDayDecision $d) => $d->work_date->toDateString()),
                $leaves->get($employee->id, collect()),
                $unpaid,
                $holidayCache,
                $balances->get($latest->get($employee->id), collect()),
            );
        }

        return $sheets;
    }

    /**
     * One person's month from what forEmployees() has already read.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  array<string, list<CarbonImmutable>>  $punches  times by day
     * @param  Collection<string, AttendanceDayDecision>  $decisions  by date
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  array<string, array<string, string>>  $holidayCache  shared by everyone of the page
     * @param  Collection<int, LeaveBalance>  $balanceRows
     */
    private function build(CarbonImmutable $first, CarbonImmutable $last, Collection $contracts, array $punches, Collection $decisions, Collection $leaves, ?LeaveType $unpaid, array &$holidayCache, Collection $balanceRows): array
    {
        $overtime = config('attendance.overtime');

        $rows = [];

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $contract = $this->coveringContract($contracts, $day);
            $row = [
                'date' => $day,
                'kind' => self::OUTSIDE,
                'leave_type' => null,
                'leave_hours' => 0.0,
                'overtime_minutes' => 0,
                'working_days' => 0.0,
                'note' => null,
                'first' => null,
                'last' => null,
                'worked_minutes' => null,
                'punch_count' => count($punches[$date] ?? []),
                'decidable' => false,
                'decision' => null,
            ];

            if ($contract === null) {
                $rows[] = $row;

                continue;
            }

            $daily = (float) ($contract->weekly_hours ? $contract->weekly_hours / 5 : config('attendance.default_daily_hours'));
            $times = $punches[$date] ?? [];
            $workedMinutes = null;

            if (count($times) >= 2) {
                $row['first'] = $times[0];
                $row['last'] = end($times);
                $workedMinutes = (int) $times[0]->diffInMinutes(end($times), true);
                $row['worked_minutes'] = $workedMinutes;
            } elseif (count($times) === 1) {
                $row['first'] = $times[0];
            }

            $position = $contract->position;
            $key = ($position?->program_id ?? 0).'-'.($position?->city_id ?? 0);
            $holidayCache[$key] ??= $this->holidaysFor($position?->program_id, $position?->city_id, $first, $last);

            // The days off in force on this day (Timesheet::daysOff): the same rule
            // the calendar line and leave read.
            $weekends = $this->daysOff($contract, $day)['days'];
            $block = max(1, (int) $overtime['block_minutes']);

            if (in_array($day->dayOfWeek, $weekends, true) || isset($holidayCache[$key][$date])) {
                $isHoliday = ! in_array($day->dayOfWeek, $weekends, true);
                $row['kind'] = $isHoliday ? self::HOLIDAY : self::WEEKEND;
                $row['leave_hours'] = $daily;
                $row['working_days'] = 1.0;
                $row['note'] = $isHoliday ? $holidayCache[$key][$date] : null;
                $row['paid_off_day'] = true;

                if ($overtime['weekend_counts_fully'] && $workedMinutes !== null) {
                    $row['overtime_minutes'] = intdiv($workedMinutes, $block) * $block;
                }

                $rows[] = $row;

                continue;
            }

            [$leaveHours, $leaveType, $leavePending] = $this->leaveOn($leaves, $day, $daily);

            if ($leaveHours >= $daily) {
                $row['kind'] = self::LEAVE;
                $row['leave_type'] = $leaveType;
                $row['leave_hours'] = $daily;
                $row['working_days'] = 0.0;
                $row['leave_pending'] = $leavePending;
                $rows[] = $row;

                continue;
            }

            if ($leaveHours > 0) {
                // An hourly leave inside a working day: the rest of the day is worked.
                $row['kind'] = self::WORKED;
                $row['leave_pending'] = $leavePending;
                $row['leave_type'] = $leaveType;
                $row['leave_hours'] = $leaveHours;
                $row['working_days'] = 1 - ($leaveHours / $daily);
                $row['overtime_minutes'] = $this->overtimeMinutes($workedMinutes, $daily, $overtime);
                $rows[] = $row;

                continue;
            }

            $decision = $decisions[$date] ?? null;

            if ($decision !== null) {
                $row['decidable'] = true;
                $row['decision'] = $decision->kind;
                $row['note'] = $decision->note;

                if ($decision->kind === AttendanceDayDecision::ABSENT) {
                    $row['kind'] = self::DECIDED_ABSENT;
                    $row['leave_type'] = $unpaid;
                    $row['leave_hours'] = $daily;
                } else {
                    $row['kind'] = self::DECIDED_WORKED;
                    $row['working_days'] = 1.0;
                    $row['overtime_minutes'] = $this->overtimeMinutes($workedMinutes, $daily, $overtime);
                }

                $rows[] = $row;

                continue;
            }

            if ($workedMinutes !== null) {
                $row['kind'] = self::WORKED;
                $row['working_days'] = 1.0;
                $row['overtime_minutes'] = $this->overtimeMinutes($workedMinutes, $daily, $overtime);
            } elseif ($times === [] && $day->isAfter(CarbonImmutable::today())) {
                // Not come yet: no one can answer for it, so it waits for nobody.
                $row['kind'] = self::UPCOMING;
            } else {
                // One punch, or none: nothing is assumed. HR decides, and until then it counts for nothing.
                $row['kind'] = count($times) === 1 ? self::ONE_PUNCH : self::NO_RECORD;
                $row['decidable'] = true;
            }

            $rows[] = $row;
        }

        $dailyHours = $this->dailyHours($contracts);

        return [
            'month' => $first,
            'placement' => $this->placement($contracts, $last),
            'daily_hours' => $dailyHours,
            'rows' => $rows,
            'totals' => $this->totals($rows, $dailyHours),
            'balances' => $this->balances($balanceRows, $contracts, $rows, $dailyHours),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function totals(array $rows, float $dailyHours): array
    {
        $leave = 0.0;
        $minutes = 0;
        $days = 0.0;
        $toDecide = 0;
        $leavePending = 0;
        $workedDays = 0;

        foreach ($rows as $row) {
            if (! empty($row['leave_pending'])) {
                $leavePending++;
            }

            if (empty($row['paid_off_day'])) {
                $leave += $row['leave_hours'];
            }

            $minutes += $row['overtime_minutes'];
            $days += $row['working_days'];

            if (in_array($row['kind'], [self::ONE_PUNCH, self::NO_RECORD], true)) {
                $toDecide++;
            }

            if (in_array($row['kind'], [self::WORKED, self::DECIDED_WORKED], true)) {
                $workedDays++;
            }
        }

        $overtimeHours = $minutes / 60;

        return [
            'leave_hours' => round($leave, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'overtime_days' => $dailyHours > 0 ? round($overtimeHours / $dailyHours, 2) : 0.0,
            'working_days' => round($days, 2),
            'worked_days' => $workedDays,
            'to_decide' => $toDecide,
            // Days covered by a leave request nobody has answered yet: never HR's to decide, but the paper waits for it.
            'leave_pending' => $leavePending,
        ];
    }

    private function coveringContract(Collection $contracts, CarbonImmutable $day): ?Contract
    {
        return $contracts
            ->filter(function (Contract $contract) use ($day) {
                $end = $contract->terminated_at && $contract->terminated_at->lt($contract->end_date)
                    ? $contract->terminated_at
                    : $contract->end_date;

                return $contract->start_date->startOfDay()->lte($day) && $end->startOfDay()->gte($day);
            })
            ->sortByDesc('start_date')
            ->first();
    }

    /**
     * Where the person works this month: the project (program), department and center of their
     * latest contract running in the month. Timesheets are grouped by it.
     *
     * @return array{program_id: ?int, program: ?string, department_id: ?int, department: ?string, center: ?string}
     */
    private function placement(Collection $contracts, CarbonImmutable $last): array
    {
        $contract = $contracts->sortByDesc('start_date')->first();
        $position = $contract?->position;
        // The days off as the month ends, so a past month shows the days it had.
        $off = $contract ? $this->daysOff($contract, $last) : ['days' => [], 'from' => null];

        return [
            // The weekly days off of the latest contract in the month, and where they come from.
            'days_off' => $off['days'],
            'days_off_from' => $off['from'],
            'program_id' => $position?->program_id,
            'program' => $position?->program?->name,
            'department_id' => $position?->department_id,
            'department' => $position?->department?->name,
            'center' => $position?->center?->name,
        ];
    }

    /**
     * A contract's weekly days off on $on (0 = Sunday … 6 = Saturday), and where they come from:
     *  - 'contract': the days the contract works then (Contract::workWeekdaysOn(): its picked days
     *    or day range, a day off moved from a date, an amendment's) — every other day is off, plus
     *    the place's own day off: the same days leave is priced with (LeaveRequestPricing::weekendFor);
     *  - else the center of its position, else the configured default.
     *
     * @return array{days: list<int>, from: 'contract'|'center'|'default'}
     */
    private function daysOff(Contract $contract, CarbonImmutable $on): array
    {
        if ($contract->workWeekdaysOn($on) !== null) {
            return [
                'days' => ($this->pricing ??= app(LeaveRequestPricing::class))->weekendFor($contract, $on)[0],
                'from' => 'contract',
            ];
        }

        // "0" is Sunday, not "nothing set": only a missing or blank value falls back to the default.
        $center = trim((string) $contract->position?->center?->weekends);
        $set = $center !== '' && Contract::parseWeekdays($center) !== [];

        return [
            'days' => Contract::parseWeekdays($set ? $center : (string) config('attendance.default_weekends')),
            'from' => $set ? 'center' : 'default',
        ];
    }

    private function dailyHours(Collection $contracts): float
    {
        $contract = $contracts->sortByDesc('start_date')->first();

        return (float) ($contract?->weekly_hours ? $contract->weekly_hours / 5 : config('attendance.default_daily_hours'));
    }

    /** Minutes of overtime for a worked day, by the configured rule. */
    private function overtimeMinutes(?int $workedMinutes, float $dailyHours, array $rule): int
    {
        if ($workedMinutes === null) {
            return 0;
        }

        $over = $workedMinutes - (int) round($dailyHours * 60);
        $block = max(1, (int) $rule['block_minutes']);

        return $over >= (int) $rule['threshold_minutes'] ? intdiv($over, $block) * $block : 0;
    }

    /**
     * Hours of leave on a working day (accepted or still asked for), its type, and whether any of it is still pending.
     *
     * @return array{0: float, 1: ?LeaveType, 2: bool}
     */
    private function leaveOn(Collection $leaves, CarbonImmutable $day, float $daily): array
    {
        $hours = 0.0;
        $type = null;
        $pending = false;

        foreach ($leaves as $leave) {
            if ($leave->type === 'hour') {
                if (! $leave->start_at->isSameDay($day)) {
                    continue;
                }

                $hours += $leave->computed_hours ?? ($leave->end_at->diffInMinutes($leave->start_at, true) / 60);
            } elseif ($leave->start_at->startOfDay()->lte($day) && $leave->end_at->startOfDay()->gte($day)) {
                $hours += $daily;
            } else {
                continue;
            }

            $type ??= $leave->leaveBalance?->leaveType;
            $pending = $pending || (int) $leave->status === LeaveRequestStatus::Pending;
        }

        return [min($hours, $daily), $type, $pending];
    }

    /**
     * The dates of the month that are holidays for a program in a city, with their Arabic names
     * (the printed form is in Arabic). Birthday holidays are personal and are left out.
     *
     * @return array<string, string>
     */
    private function holidaysFor(?int $programId, ?int $cityId, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $dates = [];

        Holiday::query()
            ->where('kind', '!=', Holiday::KIND_BIRTHDAY)
            ->applicableTo($programId, $cityId)
            ->occurringBetween($first, $last)
            ->get()
            ->each(function (Holiday $holiday) use (&$dates, $first, $last) {
                $length = max(0, (int) $holiday->start_date->diffInDays($holiday->end_date, true));
                $years = $holiday->is_recurring ? array_unique([$first->year, $last->year]) : [null];

                foreach ($years as $year) {
                    $start = $year === null
                        ? CarbonImmutable::instance($holiday->start_date)->startOfDay()
                        : CarbonImmutable::create($year, $holiday->start_date->month, $holiday->start_date->day);

                    for ($i = 0; $i <= $length; $i++) {
                        $d = $start->addDays($i);

                        if ($d->between($first, $last)) {
                            $dates[$d->toDateString()] = $holiday->name_ar ?: $holiday->name_en;
                        }
                    }
                }
            });

        return $dates;
    }

    /**
     * The leave balances of the employee's current contract, for page 2 of the form: what was
     * entitled, what was used in the month, and what is left, as the leave module holds them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function balances(Collection $balanceRows, Collection $contracts, array $rows, float $dailyHours): array
    {
        $contract = $contracts->sortByDesc('start_date')->first();

        if ($contract === null || $dailyHours <= 0) {
            return [];
        }

        $usedThisMonth = [];

        foreach ($rows as $row) {
            if (empty($row['paid_off_day']) && $row['leave_type'] !== null && $row['leave_hours'] > 0) {
                $usedThisMonth[$row['leave_type']->id] = ($usedThisMonth[$row['leave_type']->id] ?? 0) + $row['leave_hours'];
            }
        }

        return $balanceRows
            ->map(function (LeaveBalance $balance) use ($usedThisMonth, $dailyHours) {
                $entitled = (float) ($balance->deserved ?? 0);
                $used = (float) ($balance->used ?? 0);
                $month = (float) ($usedThisMonth[$balance->type_id] ?? 0);

                return [
                    'type' => $balance->leaveType,
                    'entitled_days' => round($entitled / $dailyHours, 2),
                    'month_hours' => round($month, 2),
                    'month_days' => round($month / $dailyHours, 2),
                    'remaining_hours' => round($entitled - $used, 2),
                    'remaining_days' => (int) floor(max(0, $entitled - $used) / $dailyHours),
                    'remaining_rest_hours' => (int) round(fmod(max(0, $entitled - $used), $dailyHours)),
                    'exceeded' => ($entitled - $used) < 0,
                ];
            })
            ->all();
    }
}
