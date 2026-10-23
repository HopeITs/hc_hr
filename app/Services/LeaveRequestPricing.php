<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\ScheduleMode;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Program;
use App\Support\Leave\LeaveQuote;
use App\Support\Leave\LeaveWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The one place that prices a leave request (A3-D contract,
 * docs/ux/leave-calculation-contract.md, rule set `leave-calc/1`).
 *
 * Preview, validation, the balance card's pending figure, the approval
 * deduction and the calendars are meant to call quote() instead of keeping
 * their own date arithmetic.
 *
 * Pure by contract: it only reads. It never writes a row, and it never touches
 * Carbon's process-wide weekend list (no Carbon::setWeekendDays(), no
 * isWeekend()/isWeekday()) — the weekend is always passed around explicitly,
 * so the answer cannot depend on which request ran first in the same worker.
 *
 * The caller authorizes the contract (role, city scope, policies) before
 * asking; this service does no authorization and returns no pay data.
 */
class LeaveRequestPricing
{
    /** Code default when neither the center nor the program's governorate has a weekend. */
    public const DEFAULT_WEEKEND = [0, 6];

    /** D-9: weekly hours below this are part-time (hours only, no day promotion). */
    public const FULL_TIME_WEEKLY_HOURS = 40;

    /** D-3a: shortest hourly request. */
    public const MINIMUM_MINUTES = 15;

    /** DEC-03 / D-3b: a full-time hourly request OVER this many hours is one full day. */
    public const HALF_DAY_HOURS = 4.0;

    /** HR policy: probation leave beyond this many days is deducted from pay. */
    public const PROBATION_FREE_DAYS = 3;

    /** D-2: only maternity is counted in calendar days. */
    public const CALENDAR_DAY_TYPES = [
        LeaveTypeCases::MaternityFirst,
        LeaveTypeCases::MaternitySecond,
    ];

    /** Same defaults as the form's existing open/close rule. */
    private const DEFAULT_OPEN_AT = '09:00';

    private const DEFAULT_CLOSE_AT = '16:30';

    private const EPSILON = 0.005;

    /**
     * Price $window against $balance of $contract.
     *
     * @param  LeaveRequest|null  $excluding  the request being edited; it is left out of pending_hours
     */
    public function quote(Contract $contract, LeaveBalance $balance, LeaveWindow $window, ?LeaveRequest $excluding = null): LeaveQuote
    {
        if ((int) $balance->contract_id !== (int) $contract->getKey()) {
            throw new InvalidArgumentException('The leave balance does not belong to the given contract.');
        }

        if ($this->isExemptFromLeave($contract)) {
            return new LeaveQuote(
                requestedHours: 0.0,
                requestedDaysDisplay: null,
                countedDays: 0,
                excludedDates: [],
                availableHours: 0.0,
                pendingHours: 0.0,
                projectedHours: 0.0,
                explanation: [$this->line('no_entitlement')],
                warnings: [],
                errors: [LeaveQuote::NO_LEAVE_ENTITLEMENT],
                hoursPerDay: null,
                calendarDays: false,
            );
        }

        $errors = [];
        $warnings = [];
        $explanation = [];

        // --- Schedule ---------------------------------------------------------
        [$weekend, $weekendSource] = $this->weekendFor($contract);
        $explanation[] = $this->line('weekend_'.$weekendSource, ['weekdays' => implode(',', $weekend)]);

        if ($weekendSource === 'default') {
            $warnings[] = LeaveQuote::BALANCE_UNKNOWN_WEEKEND;
        }

        $weeklyHours = (float) ($contract->weekly_hours ?? 0);
        $fullTime = $weeklyHours >= self::FULL_TIME_WEEKLY_HOURS;
        $irregular = $this->isIrregular($contract);
        $hoursPerDay = $irregular ? null : $this->hoursPerDay($weeklyHours, $weekend);

        if ($weeklyHours <= 0 || (! $irregular && $hoursPerDay === null)) {
            $errors[] = LeaveQuote::SCHEDULE_UNKNOWN;
        }

        $calendarDays = in_array((int) $balance->type_id, self::CALENDAR_DAY_TYPES, true);
        $isAnnual = (int) $balance->type_id === LeaveTypeCases::Annual;

        $available = $this->availableHours($balance, $isAnnual);
        $pending = $this->pendingHours($contract, $balance, $excluding);

        // --- Dates ------------------------------------------------------------
        if ($window->isInverted()) {
            $errors[] = LeaveQuote::INVALID_WINDOW;

            return $this->finish($contract, $balance, $isAnnual, 0.0, $hoursPerDay, 0, [], $available, $pending, $explanation, $warnings, $errors, $calendarDays);
        }

        $holidays = $calendarDays ? [] : $this->holidayDates($contract, $window->startDate, $window->endDate);

        $countedDates = [];
        $excludedDates = [];

        for ($date = $window->startDate; $date->lte($window->endDate); $date = $date->addDay()) {
            $key = $date->toDateString();

            // A holiday that falls on a weekend is listed once, as weekend.
            if (! $calendarDays && in_array($date->dayOfWeek, $weekend, true)) {
                $excludedDates[] = ['date' => $key, 'reason' => 'weekend'];
            } elseif (isset($holidays[$key])) {
                $excludedDates[] = ['date' => $key, 'reason' => 'holiday'];
            } else {
                $countedDates[] = $date;
            }
        }

        $countedDays = count($countedDates);
        $skippedWeekends = count(array_filter($excludedDates, fn (array $d) => $d['reason'] === 'weekend'));
        $skippedHolidays = count($excludedDates) - $skippedWeekends;

        if ($skippedWeekends > 0) {
            $explanation[] = $this->line('skipped_weekends', ['count' => $skippedWeekends]);
        }

        if ($skippedHolidays > 0) {
            $warnings[] = LeaveQuote::HOLIDAY_INSIDE;
            $explanation[] = $this->line('skipped_holidays', ['count' => $skippedHolidays]);
        }

        if ($countedDays === 0) {
            $errors[] = LeaveQuote::NO_COUNTABLE_DAYS;
        }

        // --- Hours --------------------------------------------------------------
        $requested = 0.0;

        if ($window->isHourly()) {
            $minutes = $window->minutes();
            $exact = $minutes / 60;

            if ($minutes < self::MINIMUM_MINUTES) {
                $errors[] = LeaveQuote::BELOW_MINIMUM;
            }

            if ($this->outsideWorkingHours($contract, $window)) {
                $errors[] = LeaveQuote::OUTSIDE_WORKING_HOURS;
            }

            if ($countedDays > 0) {
                if ($fullTime && $hoursPerDay !== null && $exact > self::HALF_DAY_HOURS + 1e-9) {
                    $requested = $hoursPerDay;
                    $explanation[] = $this->line('hours_promoted', [
                        'hours' => round($exact, 2),
                        'threshold' => self::HALF_DAY_HOURS,
                        'hours_per_day' => round($hoursPerDay, 2),
                    ]);
                } else {
                    $requested = $exact;
                    $explanation[] = $this->line('hours_exact', ['hours' => round($exact, 2)]);

                    if (! $fullTime) {
                        $explanation[] = $this->line('part_time', ['weekly_hours' => $weeklyHours]);
                    }
                }
            }
        } else {
            if ($irregular) {
                // D-8: only weekly hours are known, so a day cannot be priced.
                $errors[] = LeaveQuote::SCHEDULE_UNKNOWN;
                $explanation[] = $this->line('hours_only', ['weekly_hours' => $weeklyHours]);
            } elseif ($hoursPerDay !== null && $countedDays > 0) {
                $requested = $countedDays * $hoursPerDay;
                $explanation[] = $this->line($calendarDays ? 'calendar_days' : 'days', [
                    'days' => $countedDays,
                    'hours_per_day' => round($hoursPerDay, 2),
                    'hours' => round($requested, 2),
                ]);
            }
        }

        // --- Type rules ---------------------------------------------------------
        if (! $isAnnual && $countedDays > (int) ($balance->leaveType?->base_days ?? 0)) {
            // Ported from LeaveRequestForm::validateMaxDuration (rule #5).
            $errors[] = LeaveQuote::EXCEEDS_TYPE_MAX;
        }

        $inDecember = array_filter($countedDates, fn (CarbonImmutable $d) => $d->month === 12) !== [];

        if ($inDecember) {
            if ($window->exceptional) {
                $explanation[] = $this->line('december_exceptional');
            } else {
                $errors[] = LeaveQuote::DECEMBER_RESTRICTED;
            }
        }

        return $this->finish($contract, $balance, $isAnnual, $requested, $hoursPerDay, $countedDays, $excludedDates, $available, $pending, $explanation, $warnings, $errors, $calendarDays);
    }

    /**
     * Hours a request was deducted at under the rule in force before
     * `leave-calc/1` (LeaveRequest::getDurationInHoursAttribute, §4.2).
     *
     * For historical rows only, so the screen still reconciles with
     * leave_balances.used. Same arithmetic — including its known faults
     * (`+ 1` after an end-exclusive weekday diff, `/ 5` divisor) — but with the
     * weekend passed explicitly instead of through Carbon::setWeekendDays().
     */
    public function quoteLegacy(LeaveRequest $request): float
    {
        return $this->legacyHours($request, $request->leaveBalance?->contract);
    }

    /**
     * Hours reserved by $balance's pending requests (§3.4), the same figure
     * quote() reports as pending_hours. For the balance card, which shows a
     * pending total without pricing a new window.
     */
    public function pendingHoursFor(LeaveBalance $balance, ?LeaveRequest $excluding = null): float
    {
        $contract = $balance->contract;

        return $contract ? $this->pendingHours($contract, $balance, $excluding) : 0.0;
    }

    /**
     * Length of one working day of $contract in hours (weekly hours ÷ working
     * days of its weekend), or null when it cannot be priced (unknown hours,
     * irregular schedule). Display conversion only: storage stays in hours.
     */
    public function hoursPerDayFor(Contract $contract): ?float
    {
        if ($this->isIrregular($contract)) {
            return null;
        }

        [$weekend] = $this->weekendFor($contract);

        return $this->hoursPerDay((float) ($contract->weekly_hours ?? 0), $weekend);
    }

    /**
     * $hours as [whole days, remaining hours] with $hoursPerDay as the day
     * length. Amounts are stored to 0.01 h while a day length can be a
     * repeating decimal (40 ÷ 6), so five such days come back as 33.33 h:
     * anything within 0.01 h of a whole day counts as that day, and the
     * remainder is rounded to the minute. Display only; without a day length
     * everything stays in hours.
     *
     * @return array{0: int, 1: float}
     */
    public static function splitDays(float $hours, ?float $hoursPerDay): array
    {
        $hours = round($hours, 2);

        if ($hoursPerDay === null || $hoursPerDay <= 0) {
            return [0, $hours];
        }

        $days = (int) floor(($hours + 0.01) / $hoursPerDay);
        // To the whole minute, so the same 0.01 h drift never shows as "1 minute".
        $rest = round(($hours - $days * $hoursPerDay) * 60) / 60;

        return [$days, $rest <= 0 ? 0.0 : round($rest, 4)];
    }

    /**
     * May this contract ask for whole days at all? False for exempt contracts,
     * an unknown schedule, and irregular part-time schedules (D-8, fixture F17):
     * those are offered hourly requests only.
     */
    public function offersDayRequests(Contract $contract): bool
    {
        return ! $this->isExemptFromLeave($contract)
            && (float) ($contract->weekly_hours ?? 0) > 0
            && ! $this->isIrregular($contract);
    }

    /**
     * The weekend that applies to $contract, as day-of-week numbers (0 = Sunday).
     *
     * @return array{0: array<int, int>, 1: 'center'|'program'|'default'}
     */
    public function weekendFor(Contract $contract): array
    {
        $position = $contract->position;
        $center = $position?->center;

        $own = $this->parseWeekend($center?->weekends);

        if ($own !== null) {
            return [$own, 'center'];
        }

        // D-1: no center (or a center with no weekend set) → the weekend of any
        // center of the same program in the volunteer's governorate. Centers
        // are uniform within a governorate and program, so the lowest id is
        // as good as any and keeps the answer stable. The governorate is the
        // one the assignment is in (center city, else positions.city_id),
        // then the employee's own city.
        if ($position?->program_id) {
            $cityIds = array_values(array_unique(array_filter([
                $center?->city_id,
                $position->city_id,
                $contract->employee?->city_id,
            ])));

            foreach ($cityIds as $cityId) {
                $candidates = Center::query()
                    ->where('city_id', $cityId)
                    ->whereNotNull('weekends')
                    ->where('weekends', '<>', '')
                    ->whereHas('positions', fn ($q) => $q->where('program_id', $position->program_id))
                    ->orderBy('id')
                    ->pluck('weekends');

                foreach ($candidates as $weekends) {
                    $parsed = $this->parseWeekend($weekends);

                    if ($parsed !== null) {
                        return [$parsed, 'program'];
                    }
                }
            }
        }

        return [self::DEFAULT_WEEKEND, 'default'];
    }

    /**
     * Holiday dates that apply to $contract between $from and $to (inclusive),
     * keyed by Y-m-d.
     *
     * D-4 says "national + the center's city + the program". The holidays
     * table has no national flag and its morph (holidayable) points at City or
     * Program, so — without a schema change — this reads:
     *  - national  = a holiday attached to the City whose slug is
     *                City::NATIONAL_SLUG ('SYR', the nationwide scope that
     *                HR-SYR owns);
     *  - city      = a holiday attached to the center's City (the position's
     *                city when there is no center);
     *  - program   = a holiday attached to the position's Program, in every
     *                governorate (a "project" = program × governorate cannot
     *                be expressed by the morph alone; see the D-4 refinement).
     * Holidays attached to anything else (e.g. a Center) are not read.
     * A recurring holiday repeats on the same month/day every year; a Feb 29
     * recurring date falls on Mar 1 in other years.
     *
     * @return array<string, true>
     */
    public function holidayDates(Contract $contract, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $position = $contract->position;

        $cityIds = array_values(array_unique(array_filter([
            $this->nationalCityId(),
            $position?->center?->city_id ?? $position?->city_id,
        ])));
        $programId = $position?->program_id;

        if ($cityIds === [] && ! $programId) {
            return [];
        }

        $holidays = Holiday::query()
            ->where(function ($scope) use ($cityIds, $programId) {
                $scope->where(fn ($q) => $q
                    ->where('holidayable_type', (new City)->getMorphClass())
                    ->whereIn('holidayable_id', $cityIds ?: [0]));

                if ($programId) {
                    $scope->orWhere(fn ($q) => $q
                        ->where('holidayable_type', (new Program)->getMorphClass())
                        ->where('holidayable_id', $programId));
                }
            })
            ->where(fn ($q) => $q
                ->where('is_recurring', true)
                ->orWhere(fn ($q) => $q
                    ->whereDate('start_date', '<=', $to->toDateString())
                    ->whereDate('end_date', '>=', $from->toDateString())))
            ->get(['start_date', 'end_date', 'is_recurring']);

        $dates = [];

        foreach ($holidays as $holiday) {
            $start = CarbonImmutable::parse($holiday->start_date)->startOfDay();
            $end = CarbonImmutable::parse($holiday->end_date)->startOfDay();

            if ($end->lt($start)) {
                continue;
            }

            if (! $holiday->is_recurring) {
                $this->addRange($dates, $start, $end, $from, $to);

                continue;
            }

            $length = (int) $start->diffInDays($end);

            // year - 1 catches a recurring range that crosses New Year.
            for ($year = $from->year - 1; $year <= $to->year; $year++) {
                $yearStart = $start->setYear($year);
                $this->addRange($dates, $yearStart, $yearStart->addDays($length), $from, $to);
            }
        }

        return $dates;
    }

    // ---------------------------------------------------------------------------

    /**
     * @param  array<int, array{date: string, reason: string}>  $excludedDates
     * @param  array<int, array{key: string, params: array<string, int|float|string>}>  $explanation
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    private function finish(
        Contract $contract,
        LeaveBalance $balance,
        bool $isAnnual,
        float $requested,
        ?float $hoursPerDay,
        int $countedDays,
        array $excludedDates,
        float $available,
        float $pending,
        array $explanation,
        array $warnings,
        array $errors,
        bool $calendarDays,
    ): LeaveQuote {
        $requested = round($requested, 2);
        $projected = round($available - $pending - $requested, 2);

        if ($pending > 0) {
            $warnings[] = LeaveQuote::PENDING_RESERVED;
            $explanation[] = $this->line('pending', ['hours' => $pending]);
        }

        $explanation[] = $this->line('projected', [
            'available' => $available,
            'pending' => $pending,
            'requested' => $requested,
            'projected' => $projected,
        ]);

        if ($requested > 0 && $projected < -self::EPSILON) {
            // D-6: annual blocks, other types warn and HR decides at approval.
            if ($isAnnual) {
                $errors[] = LeaveQuote::EXCEEDS_BALANCE;
            } else {
                $warnings[] = LeaveQuote::EXCEEDS_BALANCE;
            }
        }

        if ($requested > 0 && $hoursPerDay !== null && $this->exceedsProbationAllowance($contract, $pending, $requested, $hoursPerDay)) {
            $warnings[] = LeaveQuote::PROBATION_EXCESS;
        }

        return new LeaveQuote(
            requestedHours: $requested,
            requestedDaysDisplay: $hoursPerDay ? round($requested / $hoursPerDay, 2) : null,
            countedDays: $countedDays,
            excludedDates: $excludedDates,
            availableHours: $available,
            pendingHours: $pending,
            projectedHours: $projected,
            explanation: $explanation,
            warnings: array_values(array_unique($warnings)),
            errors: array_values(array_unique($errors)),
            hoursPerDay: $hoursPerDay !== null ? round($hoursPerDay, 4) : null,
            calendarDays: $calendarDays,
        );
    }

    /**
     * Probation = a trial contract (config('contracts.map_by_id') category
     * 'trial'). HR policy: up to 3 days with approval; beyond that is deducted
     * from pay. Counted over every balance of the contract (used) plus this
     * balance's pending and the request itself. A warning only — never an
     * amount.
     */
    private function exceedsProbationAllowance(Contract $contract, float $pending, float $requested, float $hoursPerDay): bool
    {
        if (! $contract->isTrial()) {
            return false;
        }

        $used = (float) LeaveBalance::query()->where('contract_id', $contract->getKey())->sum('used');

        return $used + $pending + $requested > self::PROBATION_FREE_DAYS * $hoursPerDay + self::EPSILON;
    }

    /**
     * Mirrors LeaveBalanceService::isExemptFromLeave() (private there): a
     * draft or a service-agreement contract has no leave balance at all.
     * Category is read from config('contracts.map_by_id') — the same source as
     * ContractType::$category — without loading the type row.
     */
    private function isExemptFromLeave(Contract $contract): bool
    {
        $category = config('contracts.map_by_id')[$contract->contract_type_id] ?? 'annual';

        return (int) $contract->type === ContractTypeEnum::Draft || $category === 'service';
    }

    /**
     * D-8 / D-9: a part-time contract on a flexible schedule states only its
     * weekly hours; its days are scattered and not written in the contract, so
     * no day length can be derived. A part-time contract on a fixed schedule
     * (fixture F9) keeps a day length.
     */
    private function isIrregular(Contract $contract): bool
    {
        return (float) ($contract->weekly_hours ?? 0) < self::FULL_TIME_WEEKLY_HOURS
            && (int) $contract->schedule_mode === ScheduleMode::Flexible;
    }

    /**
     * weekly_hours ÷ working days per week (7 − weekend days). Float, never
     * truncated. Null when it cannot be priced.
     *
     * @param  array<int, int>  $weekend
     */
    private function hoursPerDay(float $weeklyHours, array $weekend): ?float
    {
        $workingDays = 7 - count($weekend);

        if ($weeklyHours <= 0 || $workingDays <= 0) {
            return null;
        }

        return $weeklyHours / $workingDays;
    }

    /**
     * Annual: eligible-to-date (section rule) − used. Others: deserved − used.
     */
    private function availableHours(LeaveBalance $balance, bool $isAnnual): float
    {
        $base = $isAnnual
            ? (float) $balance->eligibleAnnualLeaveHours()
            : (float) ($balance->deserved ?? 0);

        return round($base - (float) ($balance->used ?? 0), 2);
    }

    /**
     * Hours reserved by this balance's other pending requests: each one's
     * stored snapshot (computed_hours) when present, else the legacy rule.
     */
    private function pendingHours(Contract $contract, LeaveBalance $balance, ?LeaveRequest $excluding): float
    {
        $requests = LeaveRequest::query()
            ->where('leave_balance_id', $balance->getKey())
            ->where('status', LeaveRequestStatus::Pending)
            ->when($excluding?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))
            ->get();

        $total = 0.0;

        foreach ($requests as $request) {
            $total += $request->computed_hours !== null
                ? (float) $request->computed_hours
                : $this->legacyHours($request, $contract);
        }

        return round($total, 2);
    }

    private function legacyHours(LeaveRequest $request, ?Contract $contract): float
    {
        if ($request->type === LeaveWindow::HOUR) {
            return $request->start_at->floatDiffInRealHours($request->end_at);
        }

        $weekends = array_map('intval', explode(',', $contract?->position?->center?->weekends ?? '0,6'));

        // Carbon's diffInWeekdays(): the end takes the start's time of day,
        // then dates are stepped from start up to (excluding) end.
        $start = CarbonImmutable::instance($request->start_at);
        $end = CarbonImmutable::instance($request->end_at)
            ->setTime($start->hour, $start->minute, $start->second, $start->microsecond);

        $inverse = $end->lt($start);
        [$from, $to] = $inverse ? [$end, $start] : [$start, $end];

        $weekdays = 0;

        for ($date = $from; $date->lt($to); $date = $date->addDay()) {
            if (! in_array($date->dayOfWeek, $weekends, true)) {
                $weekdays++;
            }
        }

        $days = ($inverse ? -$weekdays : $weekdays) + 1;

        return $days * ((float) ($contract?->weekly_hours ?? 0) / 5);
    }

    /**
     * The existing rule from LeaveRequestForm::rules(): an hourly request must
     * start at or after the center's opening time and end at or before its
     * closing time (defaults 09:00 / 16:30 when unknown).
     */
    private function outsideWorkingHours(Contract $contract, LeaveWindow $window): bool
    {
        $center = $contract->position?->center;

        $open = LeaveWindow::minuteOfDay((string) ($center?->open_at ?? self::DEFAULT_OPEN_AT));
        $close = LeaveWindow::minuteOfDay((string) ($center?->close_at ?? self::DEFAULT_CLOSE_AT));

        return LeaveWindow::minuteOfDay($window->startTime) < $open
            || LeaveWindow::minuteOfDay($window->endTime) > $close;
    }

    /**
     * "5,6" → [5, 6]. Null when unset or unusable.
     *
     * @return array<int, int>|null
     */
    private function parseWeekend(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $days = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part !== '' && ctype_digit($part) && (int) $part <= 6) {
                $days[] = (int) $part;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? null : $days;
    }

    private function nationalCityId(): ?int
    {
        $id = City::query()->where('slug', City::NATIONAL_SLUG)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, true>  $dates
     */
    private function addRange(array &$dates, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $start = $start->max($from);
        $end = $end->min($to);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dates[$date->toDateString()] = true;
        }
    }

    /**
     * @param  array<string, int|float|string>  $params
     * @return array{key: string, params: array<string, int|float|string>}
     */
    private function line(string $key, array $params = []): array
    {
        return ['key' => 'leave_quote.explain.'.$key, 'params' => $params];
    }
}
