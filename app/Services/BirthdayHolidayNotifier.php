<?php

namespace App\Services;

use App\Models\BirthdayHolidayChoice;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BirthdayChoiceDecided;
use App\Notifications\BirthdayChoiceSubmitted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a birthday-day choice (meeting 19/11, track 3):
 * the direct manager to decide and HR of the volunteer's city to know
 * (HR-SYR when that city has no HR), and the volunteer once it is decided.
 * The rules of the holiday itself stay in BirthdayHoliday.
 */
class BirthdayHolidayNotifier
{
    public function submitted(BirthdayHolidayChoice $choice): void
    {
        $choice->loadMissing('employee.city');
        $managers = $this->managersOf($choice->employee);
        $hr = $this->cityHr($choice->employee)
            ->reject(fn (User $u) => $managers->contains($u) || (int) $u->employee_id === (int) $choice->employee_id);

        DB::afterCommit(function () use ($choice, $managers, $hr): void {
            Notification::send($managers, new BirthdayChoiceSubmitted($choice));
            Notification::send($hr, new BirthdayChoiceSubmitted($choice, forHr: true));
        });
    }

    public function decided(BirthdayHolidayChoice $choice): void
    {
        $volunteer = $this->accountsOf($choice->employee_id);

        DB::afterCommit(fn () => Notification::send($volunteer, new BirthdayChoiceDecided($choice)));
    }

    /**
     * The people holding, now, the position the volunteer's position reports
     * to: the same rule as BirthdayHoliday::canDecide.
     *
     * @return Collection<int, User>
     */
    public function managersOf(?Employee $employee): Collection
    {
        $managerPositionId = $employee?->latestActiveContract()?->position?->direct_manager_id;

        if ($managerPositionId === null) {
            return collect();
        }

        $managerIds = Contract::query()->activeNow()
            ->where('position_id', $managerPositionId)
            ->where('employee_id', '<>', $employee->id)
            ->pluck('employee_id')
            ->all();

        return $this->accountsOf($managerIds);
    }

    /** @return Collection<int, User> */
    public function cityHr(?Employee $employee): Collection
    {
        $slug = $employee?->city?->slug;
        $users = $slug ? $this->withRoles(City::hrRolesForSlug($slug)) : collect();

        return $users->isNotEmpty() ? $users : $this->withRoles(['HR-'.City::NATIONAL_SLUG]);
    }

    /**
     * Active accounts of the given employee(s).
     *
     * @param  int|array<int, int>  $employeeIds
     * @return Collection<int, User>
     */
    public function accountsOf(int|array $employeeIds): Collection
    {
        return User::query()
            ->whereIn('employee_id', (array) $employeeIds ?: [0])
            ->whereHas('profile', fn (Builder $q): Builder => $q->where('is_active', true))
            ->get();
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function withRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $q): Builder => $q->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $q): Builder => $q->where('is_active', true))
            ->get();
    }
}
