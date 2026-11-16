<?php

namespace App\Services\Attendance;

use App\Enums\ContractType;
use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Whose timesheets a user may see for a month: everyone with a contract running some day of it,
 * in the user's cities (a city HR role sees its own, HR Syria and administrators see all).
 */
class Roster
{
    /** @return Collection<int, Employee> */
    public function employees(User $user, CarbonImmutable $month, ?int $cityId = null): Collection
    {
        $first = $month->startOfMonth();
        $last = $month->endOfMonth();

        return Employee::query()
            ->with('city')
            ->whereHas('contracts', fn ($contract) => $contract
                ->where('type', '!=', ContractType::Draft)
                ->whereDate('start_date', '<=', $last)
                ->whereDate('end_date', '>=', $first))
            ->when($user->activeCityIds(), fn ($query, array $ids) => $query->whereIn('city_id', $ids))
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->orderBy('first_name_en')
            ->orderBy('last_name_en')
            ->get();
    }

    /** May this user see this employee's timesheet? */
    public function covers(User $user, Employee $employee): bool
    {
        $ids = $user->activeCityIds();

        return $ids === null || in_array((int) $employee->city_id, $ids, true);
    }

    /** @return Collection<int, City> */
    public function cities(User $user): Collection
    {
        return City::query()
            ->where('is_active', true)
            ->when($user->activeCityIds(), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy(transColumn('name'))
            ->get();
    }
}
