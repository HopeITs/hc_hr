<?php

namespace App\Livewire\Contract\Concerns;

use App\Livewire\Forms\ContractForm;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;
use Livewire\Attributes\Renderless;

/**
 * Shared dropdown queries and position/city consistency checks for
 * ContractCreate and ContractEdit.
 *
 * @property ContractForm $form
 * @property ?int $city_id
 * @property ?int $program_id
 * @property ?int $center_id
 */
trait ResolvesContractLocationOptions
{
    /**
     * Return the complete, compact option graph for one authorized city.
     *
     * This method is intentionally renderless: changing a city performs one
     * small JSON request without rebuilding the contract form or its Select2
     * instances. Program, center, and position filtering then happens locally.
     */
    #[Renderless]
    public function locationOptions(int $cityId): array
    {
        $user = auth()->user();

        abort_unless($user, 403);
        $user->loadMissing(['profile:id,city_id', 'roles']);

        abort_unless($user->hasActivePermission($this->locationOptionsPermission()), 403);

        $activeCityIds = $user->activeCityIds();

        $city = City::query()
            ->whereKey($cityId)
            ->where('is_active', true)
            ->when($activeCityIds !== null, fn ($query) => $query->whereKey($activeCityIds))
            ->first(['id', 'slug']);

        abort_unless($city !== null, 403);

        $locale = app()->getLocale();
        $positionTitleColumn = "title_{$locale}";
        $centerNameColumn = "name_{$locale}";
        $ensureEmployeeId = $this->locationOptionsEmployeeId();

        $positions = Position::query()
            ->inCity($cityId)
            ->with([
                'program:id,name,slug',
                'center' => fn ($query) => $query->select('id', 'city_id', $centerNameColumn),
            ])
            ->select('id', 'program_id', 'center_id', 'city_id', 'max_emp', 'exist_emp', $positionTitleColumn)
            ->orderBy($positionTitleColumn)
            ->get();

        $employees = $this->employeeOptionsForCity($city, $locale);

        if ($ensureEmployeeId && ! collect($employees)->contains('value', $ensureEmployeeId)) {
            $currentEmployee = Employee::query()
                ->select('id', 'city_id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar')
                ->find($ensureEmployeeId);

            if ($currentEmployee) {
                $employees[] = [
                    'value' => $currentEmployee->id,
                    'label' => $currentEmployee->nameForLocale($locale),
                    'avatar' => $currentEmployee->avatar_url,
                    'activeContractEndsAt' => null,
                ];
            }
        }

        return [
            'employees' => collect($employees)
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'programs' => $positions
                ->filter(fn (Position $position) => $position->program !== null)
                ->unique('program_id')
                ->map(fn (Position $position) => [
                    'value' => $position->program_id,
                    'label' => $position->program->name,
                    // 'SZ' is the Study Zone program's actual data — see
                    // contract-composer.js::isStudyZoneProgram.
                    'slug' => $position->program->slug,
                ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'centers' => $positions
                ->filter(fn (Position $position) => $position->center !== null)
                ->groupBy('center_id')
                ->map(function (Collection $centerPositions) use ($centerNameColumn): array {
                    /** @var Position $first */
                    $first = $centerPositions->first();

                    return [
                        'value' => $first->center_id,
                        'label' => $first->center->{$centerNameColumn},
                        'programIds' => $centerPositions->pluck('program_id')->unique()->values()->all(),
                    ];
                })
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'positions' => $positions
                ->map(fn (Position $position) => [
                    'value' => $position->id,
                    'label' => $position->{$positionTitleColumn},
                    'programId' => $position->program_id,
                    'centerId' => $position->center_id,
                    // Capacity travels with the option so the create screen can
                    // show "4 of 5" before the choice instead of failing after it.
                    // Additive: contract-location-picker.js ignores both keys.
                    'maxEmp' => (int) $position->max_emp,
                    'existEmp' => (int) $position->exist_emp,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The full employee list this user may assign a contract to, with no city
     * chosen yet — lets the Assignment step open employee-first: picking
     * someone derives their city (see contract-composer.js::onEmployeeChange()),
     * instead of requiring a city choice before anyone even appears.
     *
     * Scoped by the same rule as locationOptions() itself (activeCityIds()), so
     * this opens no wider a door than a nationwide user already has by picking
     * the "Syria" headquarters scope in the city dropdown — a city-scoped role
     * still sees only its own employees here.
     */
    #[Renderless]
    public function employeeSearchOptions(): array
    {
        $user = auth()->user();

        abort_unless($user, 403);
        $user->loadMissing(['profile:id,city_id', 'roles']);

        abort_unless($user->hasActivePermission($this->locationOptionsPermission()), 403);

        $locale = app()->getLocale();
        $activeCityIds = $user->activeCityIds();

        $employees = Employee::query()
            ->select('id', 'city_id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar')
            ->with(['media' => fn ($query) => $query->where('collection_name', 'avatar')])
            ->where('is_active', true)
            ->when($activeCityIds !== null, fn ($query) => $query->whereIn('city_id', $activeCityIds))
            ->orderBy("first_name_{$locale}")
            ->orderBy("last_name_{$locale}")
            ->get();

        $activeContractEnds = $this->activeContractEndDates($employees->modelKeys());

        return $employees
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->nameForLocale($locale),
                'avatar' => $employee->avatar_url,
                'cityId' => $employee->city_id,
                'activeContractEndsAt' => $activeContractEnds[$employee->id] ?? null,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Apply the locally selected identity graph as one atomic server update.
     */
    protected function applyIdentitySelection(array $selection): void
    {
        $previousContractTypeId = $this->form->contract_type_id;

        $this->city_id = $this->selectionId($selection, 'cityId');
        $this->form->employee_id = $this->selectionId($selection, 'employeeId');
        $this->program_id = $this->selectionId($selection, 'programId');
        $this->center_id = $this->selectionId($selection, 'centerId');
        $this->form->position_id = $this->selectionId($selection, 'positionId');
        $this->form->contract_type_id = $this->selectionId($selection, 'contractTypeId');

        if ($this->form->contract_type_id && $this->form->contract_type_id !== $previousContractTypeId) {
            $this->form->updatedContractTypeId($this->form->contract_type_id, $this->contractTermAnchor());
        }
    }

    /**
     * The date a term recomputed by a contract-type change must start from.
     * Null keeps the "starts next month" default; renewals override it with the
     * date their predecessor hands over on.
     */
    protected function contractTermAnchor(): ?string
    {
        return null;
    }

    /**
     * Seed the city / program / center cascade from an existing contract, so
     * the identity step opens on the position that contract already holds.
     *
     * Shared by editing a contract and renewing one; the effective city comes
     * from the position's center when it has one, because Position::inCity()
     * treats a center's city as authoritative over the position's own city_id.
     */
    protected function initLocationContext(Contract $contract): void
    {
        $position = $contract->position;

        $effectiveCityId = $position?->center_id
            ? Center::whereKey($position->center_id)->value('city_id')
            : $position?->city_id;

        $this->city_id = $effectiveCityId ?? $contract->employee?->city_id;
        $this->program_id = $position?->program_id;
        $this->center_id = $position?->center_id;
    }

    abstract protected function locationOptionsPermission(): string;

    protected function locationOptionsEmployeeId(): ?int
    {
        return null;
    }

    private function getCities(string $col): Collection
    {
        return City::select('id', $col)
            ->where('is_active', true)
            ->when(
                auth()->user()?->activeCityIds(),
                fn ($query, array $cityIds) => $query->whereKey($cityIds),
            )
            ->get()
            ->each(fn (City $city) => $city->name = $city->{$col});
    }

    private function getContractTypes(string $col): Collection
    {
        return ContractType::select('id', $col)
            ->orderBy($col)
            ->get()
            ->each(fn (ContractType $t) => $t->name = $t->{$col});
    }

    /**
     * Hydrate only the six columns needed to build employee dropdown labels.
     */
    private function employeeOptionsForCity(City $city, string $locale): array
    {
        /*
         * No role check here. Which cities this user may touch was already
         * decided by locationOptions() above, from activeCityIds(): a city-scoped
         * role never reaches this method with anything but its own city, and a
         * nationwide one (super-admin, general-manager, HR-SYR) is meant to
         * reach all of them.
         *
         * This method used to pin every non-HR role to its own profile city on
         * top of that, which predates activeCityIds() being the single scoping
         * rule. The two disagreed, and the narrower one won silently: a
         * general-manager was offered every position in a city and no employees
         * at all, which read as "there are none" rather than "you may not".
         * Positions have never carried a second check — Position::inCity() is
         * their whole rule — and employees now match.
         */

        // Syria is the headquarters scope rather than a city of its own, so a
        // contract issued against it may be held by anyone, anywhere.
        $isNationwide = $city->slug === City::NATIONAL_SLUG;

        $employees = Employee::query()
            ->select('id', 'city_id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar')
            // avatar_url reads from the loaded media collection when present, so
            // eager-loading turns one lookup per employee into a single query.
            // Constrained to the avatar collection so it does not drag in every
            // other media row an employee happens to own.
            ->with(['media' => fn ($query) => $query->where('collection_name', 'avatar')])
            ->where('is_active', true)
            ->unless($isNationwide, fn ($query) => $query->where('city_id', $city->id))
            ->orderBy("first_name_{$locale}")
            ->orderBy("last_name_{$locale}")
            ->get();

        $activeContractEnds = $this->activeContractEndDates($employees->modelKeys());

        return $employees
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->nameForLocale($locale),
                // Additive keys, consumed only by the create composer.
                'avatar' => $employee->avatar_url,
                // Lets the composer auto-select a city once this employee is
                // chosen from employeeSearchOptions()'s wider, city-less list —
                // see contract-composer.js::onEmployeeChange().
                'cityId' => $employee->city_id,
                'activeContractEndsAt' => $activeContractEnds[$employee->id] ?? null,
            ])
            ->all();
    }

    /**
     * End dates of the currently active contracts, keyed by employee.
     *
     * Their presence is the duplicate-contract signal the create screen warns
     * about; it never blocks, because renewals legitimately overlap.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, string>
     */
    private function activeContractEndDates(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return Contract::query()
            ->activeNow()
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('end_date')
            ->get(['employee_id', 'end_date'])
            ->mapWithKeys(fn (Contract $contract) => [
                $contract->employee_id => $contract->end_date?->toDateString(),
            ])
            ->filter()
            ->all();
    }

    private function selectionId(array $selection, string $key): ?int
    {
        $value = filter_var($selection[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $value === false ? null : $value;
    }

    private function validateCityConsistency(): bool
    {
        if (! auth()->user()?->canAccessCity((int) $this->city_id)) {
            $this->addError('city_id', __('contracts.errors.city-not-allowed'));

            return false;
        }

        // Same exemption as the dropdown that offered the employee: a contract
        // on the nationwide scope accepts an employee from any city, and the
        // two rules have to agree or the form offers what it then rejects.
        $employeeMatchesCity = Employee::query()
            ->whereKey($this->form->employee_id)
            ->unless(
                City::isNational($this->city_id),
                fn ($query) => $query->where('city_id', $this->city_id),
            )
            ->exists();

        if (! $employeeMatchesCity) {
            $this->addError('form.employee_id', __('contracts.errors.employee-city-mismatch'));

            return false;
        }

        if (! $this->city_id || ! $this->form->position_id) {
            return false;
        }

        $position = Position::select('id', 'program_id', 'center_id', 'city_id')
            ->find($this->form->position_id);

        if (! $position) {
            $this->addError('form.position_id', __('contracts.errors.position-not-found'));

            return false;
        }

        $belongsToCity = Position::whereKey($position->id)
            ->inCity($this->city_id)
            ->exists();

        if (! $belongsToCity) {
            $this->addError('form.position_id', __('contracts.errors.position-not-in-city'));

            return false;
        }

        if ($this->program_id && (int) $position->program_id !== (int) $this->program_id) {
            $this->addError('form.position_id', __('contracts.errors.position-program-mismatch'));

            return false;
        }

        if ($this->center_id && (int) $position->center_id !== (int) $this->center_id) {
            $this->addError('form.position_id', __('contracts.errors.position-center-mismatch'));

            return false;
        }

        return true;
    }
}
