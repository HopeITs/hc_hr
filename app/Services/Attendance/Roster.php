<?php

namespace App\Services\Attendance;

use App\Enums\ContractType;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whose timesheets a user may see, by the active role, the way the other pages scope people:
 *  - an employee: their own;
 *  - a manager: their own and their direct reports' (the people on positions that report to the
 *    manager's current positions, as Contract::scopeVisibleTo does);
 *  - an HR role of a city: everyone of that city; HR Syria, the general manager and the
 *    administrators: everyone.
 * `attendance.view` is what lifts a user above "own"; `attendance.view-own` is every role's.
 */
class Roster
{
    public const OWN = 'own';

    public const TEAM = 'team';

    public const CITY = 'city';

    public const ALL = 'all';

    /** How far this user sees: own, team, city or all. */
    public function mode(User $user): string
    {
        if (! $user->hasActivePermission('attendance.view')) {
            return self::OWN;
        }

        if ($user->activeRoleIs('manager')) {
            return self::TEAM;
        }

        if ($user->activeRoleIs('HR')) {
            return $user->activeCityIds() === null ? self::ALL : self::CITY;
        }

        if ($user->activeRoleIs('general-manager', ...config('access-control.administrator_roles', []))) {
            return self::ALL;
        }

        // Granted `attendance.view` by hand to a role that is not a team, city or head-office role.
        return self::OWN;
    }

    /** @return Collection<int, Employee> */
    public function employees(User $user, CarbonImmutable $month, ?int $cityId = null): Collection
    {
        return $this->scope($user, Employee::query(), $month)
            ->with('city')
            ->whereHas('contracts', fn ($contract) => $this->overlapping($contract, $month))
            ->when($cityId && in_array($this->mode($user), [self::CITY, self::ALL, self::TEAM], true), fn ($query) => $query->where('city_id', $cityId))
            ->orderBy('first_name_en')
            ->orderBy('last_name_en')
            ->get();
    }

    /** Restrict an employee query to the people this user may see for the month. */
    public function scope(User $user, Builder $query, CarbonImmutable $month): Builder
    {
        switch ($this->mode($user)) {
            case self::ALL:
                return $query;

            case self::CITY:
                return $query->whereIn('city_id', $user->activeCityIds() ?? []);

            case self::TEAM:
                $positionIds = $user->employee_id
                    ? Contract::query()->activeNow()->where('employee_id', $user->employee_id)->pluck('position_id')->all()
                    : [];

                return $query->where(function (Builder $q) use ($user, $positionIds, $month) {
                    $user->employee_id ? $q->whereKey($user->employee_id) : $q->whereRaw('1 = 0');

                    $q->orWhereHas('contracts', fn ($contract) => $this->overlapping($contract, $month)
                        ->whereHas('position', fn ($position) => $position->whereIn('direct_manager_id', $positionIds)));
                });

            default:
                return $user->employee_id ? $query->whereKey($user->employee_id) : $query->whereRaw('1 = 0');
        }
    }

    /** May this user see this employee's timesheet for the month? */
    public function covers(User $user, Employee $employee, ?CarbonImmutable $month = null): bool
    {
        return $this->scope($user, Employee::query(), $month ?? CarbonImmutable::now()->startOfMonth())
            ->whereKey($employee->id)
            ->exists();
    }

    /**
     * The cities to filter by: only for the roles that see more than one place at a time.
     *
     * @return Collection<int, City>
     */
    public function cities(User $user): Collection
    {
        if (! in_array($this->mode($user), [self::CITY, self::ALL], true)) {
            return collect();
        }

        return City::query()
            ->where('is_active', true)
            ->when($user->activeCityIds(), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy(transColumn('name'))
            ->get();
    }

    /**
     * The city the page starts on. Only for those who see every city (HR Syria, the general
     * manager, the administrators): their own city, else the first. Everyone else is already
     * limited by role (own, team or their cities), so the page loads that and nothing is chosen.
     */
    public function defaultCityId(User $user): ?int
    {
        if ($this->mode($user) !== self::ALL) {
            return null;
        }

        $cities = $this->cities($user);
        $own = $user->profile?->city_id;

        return (int) ($cities->contains('id', $own) ? $own : $cities->first()?->id) ?: null;
    }

    private function overlapping(Builder|\Illuminate\Database\Eloquent\Relations\Relation $contract, CarbonImmutable $month): Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return $contract
            ->where('type', '!=', ContractType::Draft)
            ->whereDate('start_date', '<=', $month->endOfMonth())
            ->whereDate('end_date', '>=', $month->startOfMonth());
    }
}
