<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Position;
use App\Support\Leave\LeaveWindow;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may cover for an employee while they are on leave.
 *
 * The single source of truth for the "alternative employee" of a leave
 * request: the request forms list through it and LeaveRequestForm validates
 * through it, so a submitted id can never be one the list would not show.
 *
 * The rule follows the job level (grade) of the requester's position. Every
 * candidate must hold an active contract on a position in the requester's
 * city (Position::scopeInCity):
 *
 * - 1   all management (grades 1-5).
 * - 2   all management, plus grades 6-7 in the same program (and center, if any).
 * - 3   grades 3, 4, 6, 7 in the same program, plus the same grades at the
 *       same center whatever their program.
 * - 4   as grade 3, plus the holders of the position's direct and technical
 *       manager positions.
 * - 5   the same department.
 * - 6+  the same department within the same program.
 *
 * Given the period asked for (a LeaveWindow), only the colleagues available
 * for all of it are kept: nobody with an accepted or pending leave that
 * overlaps it, and nobody off on a working day of the requester's because of
 * a holiday of their own (their approved birthday holiday, or a project
 * holiday that does not reach the requester). Without a period the list is
 * the rule alone, as before.
 */
class LeaveAlternativeEmployees
{
    private const MANAGEMENT_GRADES = [1, 2, 3, 4, 5];

    private const PROGRAM_TEAM_GRADES = [6, 7];

    private const PROJECT_STAFF_GRADES = [3, 4, 6, 7];

    public function __construct(private readonly LeaveRequestPricing $pricing) {}

    public function for(Employee $employee, ?LeaveWindow $period = null): Collection
    {
        $period = $this->usable($period);
        $candidates = $this->query($employee, $period)->orderBy(transColumn('first_name'))->get();

        return $period ? $this->withoutHolidaysIn($candidates, $employee, $period) : $candidates;
    }

    /**
     * The rule, and with a period the leave overlap, as a query. Holidays are
     * calendar work done in PHP: for() is the complete list.
     */
    public function query(Employee $employee, ?LeaveWindow $period = null): Builder
    {
        $position = $employee->latestActiveContract()?->position;

        if (! $position) {
            return Employee::query()->whereRaw('1 = 0');
        }

        $position->loadMissing('jobLevel');
        $period = $this->usable($period);

        return Employee::query()
            ->whereKeyNot($employee->getKey())
            ->where(fn (Builder $candidates) => $this->applyRule($candidates, $position, $employee->city_id))
            ->when($period, fn (Builder $candidates) => $candidates->whereDoesntHave(
                'allContracts.leaveBalances.leaveRequests',
                fn (Builder $leave) => $this->overlapping($leave, $period),
            ));
    }

    /**
     * Accepted or pending leave overlapping $period. A day leave covers its
     * dates whole (end date inclusive); an hourly one its own hours, so two
     * hourly windows on the same day clash only when their hours do.
     */
    private function overlapping(Builder $leave, LeaveWindow $period): Builder
    {
        [$from, $until] = $period->isHourly()
            ? [$period->startsAt(), $period->endsAt()]
            : [$period->startDate->startOfDay(), $period->endDate->addDay()->startOfDay()];

        return $leave
            ->whereIn('status', [LeaveRequestStatus::Accepted, LeaveRequestStatus::Pending])
            ->where('start_at', '<', $until->toDateTimeString())
            ->where(fn (Builder $ends) => $ends
                ->where(fn (Builder $hourly) => $hourly
                    ->where('type', 'hour')
                    ->where('end_at', '>', $from->toDateTimeString()))
                ->orWhere(fn (Builder $daily) => $daily
                    ->where('type', '!=', 'hour')
                    ->where('end_at', '>=', $from->startOfDay()->toDateTimeString())));
    }

    /**
     * Drops the candidates off on a working day of the requester's in
     * $period because of a holiday the requester does not have. A holiday
     * everyone shares (a national one) is no reason: the requester is off then too.
     */
    private function withoutHolidaysIn(Collection $candidates, Employee $requester, LeaveWindow $period): Collection
    {
        $from = $period->startDate->startOfDay();
        $to = $period->endDate->startOfDay();
        $requesterContract = $requester->latestActiveContract();

        if ($candidates->isEmpty() || ! $requesterContract || ! Holiday::query()->occurringBetween($from, $to)->exists()) {
            return $candidates;
        }

        $requesterContract->loadMissing(['position.center', 'employee']);
        $requesterOff = $this->pricing->officialHolidayDates($requesterContract, $from, $to);
        $workingDays = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $this->pricing->weekendFor($requesterContract, $day)[0], true) && ! isset($requesterOff[$day->toDateString()])) {
                $workingDays[$day->toDateString()] = true;
            }
        }

        if ($workingDays === []) {
            return $candidates;
        }

        $candidates->load(['contracts.position.center']);

        return $candidates->reject(function (Employee $candidate) use ($from, $to, $workingDays): bool {
            $contract = $candidate->latestActiveContract();

            return $contract instanceof Contract
                && array_intersect_key($this->pricing->holidayDates($contract->setRelation('employee', $candidate), $from, $to), $workingDays) !== [];
        })->values();
    }

    /** A period that can filter: an inverted, half-typed window filters nothing. */
    private function usable(?LeaveWindow $period): ?LeaveWindow
    {
        return $period && ! $period->isInverted() ? $period : null;
    }

    private function applyRule(Builder $candidates, Position $position, int $cityId): void
    {
        $grade = $position->jobLevel?->grade;

        if (in_array($grade, [1, 2], true)) {
            $this->management($candidates, $cityId);
        }

        if ($grade === 2) {
            $this->programTeam($candidates, $position, $cityId);
        }

        if (in_array($grade, [3, 4], true)) {
            $this->projectStaff($candidates, $position, $cityId);
        }

        if ($grade === 4) {
            $this->ownManagers($candidates, $position);
        }

        if ($grade === 5) {
            $this->department($candidates, $position, $cityId);
        }

        if ($grade === null || $grade >= 6) {
            $this->departmentInProgram($candidates, $position, $cityId);
        }
    }

    private function management(Builder $candidates, int $cityId): void
    {
        $this->holding($candidates, $cityId, fn (Builder $held) => $this->atGrades($held, self::MANAGEMENT_GRADES));
    }

    private function programTeam(Builder $candidates, Position $position, int $cityId): void
    {
        $this->holding($candidates, $cityId, fn (Builder $held) => $this->atGrades($held, self::PROGRAM_TEAM_GRADES)
            ->where('program_id', $position->program_id)
            ->when($position->center_id, fn (Builder $held) => $held->where('center_id', $position->center_id)));
    }

    private function projectStaff(Builder $candidates, Position $position, int $cityId): void
    {
        $this->holding($candidates, $cityId, fn (Builder $held) => $this->atGrades($held, self::PROJECT_STAFF_GRADES)
            ->where('program_id', $position->program_id));

        if ($position->center_id) {
            $this->holding($candidates, $cityId, fn (Builder $held) => $this->atGrades($held, self::PROJECT_STAFF_GRADES)
                ->where('center_id', $position->center_id));
        }
    }

    private function ownManagers(Builder $candidates, Position $position): void
    {
        $candidates->orWhereHas('contracts', fn (Builder $contract) => $contract->whereIn(
            'position_id',
            array_filter([$position->direct_manager_id, $position->tech_manager_id]),
        ));
    }

    private function department(Builder $candidates, Position $position, int $cityId): void
    {
        $this->holding($candidates, $cityId, fn (Builder $held) => $held->where('department_id', $position->department_id));
    }

    private function departmentInProgram(Builder $candidates, Position $position, int $cityId): void
    {
        $this->holding($candidates, $cityId, fn (Builder $held) => $held
            ->where('department_id', $position->department_id)
            ->where('program_id', $position->program_id));
    }

    /** Candidates holding an active contract on a position in the city that matches $scope. */
    private function holding(Builder $candidates, int $cityId, Closure $scope): void
    {
        $candidates->orWhereHas('contracts.position', fn (Builder $held) => $scope($held->inCity($cityId)));
    }

    private function atGrades(Builder $positions, array $grades): Builder
    {
        return $positions->whereHas('jobLevel', fn (Builder $level) => $level->whereIn('grade', $grades));
    }
}
