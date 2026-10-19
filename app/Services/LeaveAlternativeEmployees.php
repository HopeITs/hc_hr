<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Position;
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
 */
class LeaveAlternativeEmployees
{
    private const MANAGEMENT_GRADES = [1, 2, 3, 4, 5];

    private const PROGRAM_TEAM_GRADES = [6, 7];

    private const PROJECT_STAFF_GRADES = [3, 4, 6, 7];

    public function for(Employee $employee): Collection
    {
        return $this->query($employee)->orderBy(transColumn('first_name'))->get();
    }

    public function query(Employee $employee): Builder
    {
        $position = $employee->latestActiveContract()?->position;

        if (! $position) {
            return Employee::query()->whereRaw('1 = 0');
        }

        $position->loadMissing('jobLevel');

        return Employee::query()
            ->whereKeyNot($employee->getKey())
            ->where(fn (Builder $candidates) => $this->applyRule($candidates, $position, $employee->city_id));
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
