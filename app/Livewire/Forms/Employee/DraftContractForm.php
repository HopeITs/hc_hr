<?php

namespace App\Livewire\Forms\Employee;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Position;
use Closure;
use Livewire\Attributes\Validate;
use Livewire\Form;

class DraftContractForm extends Form
{
    #[Validate('required')]
    public ?int $position_id = null;

    /**
     * Create a placeholder contract (type = Draft) reserving $position for
     * $employee: no schedule, no pay, no capacity check. It is meant to be
     * upgraded into a full contract later from the same row.
     *
     * $cityId/$programId are what the cascade's own dropdowns narrowed the
     * position list to (see EmployeeCreate::positionOptionsForDraft()) — for
     * a city-scoped HR role $cityId is always the employee's own city (the
     * view never renders an open dropdown for them), but a nationwide role
     * may have picked a different one, so both are re-checked here rather
     * than trusted, the same defense-in-depth `positionCapacityRule()`/
     * `validateCityConsistency()` already apply on the main contract form.
     */
    public function createDraft(Employee $employee, ?int $cityId, ?int $programId): Contract
    {
        $this->validate([
            'position_id' => ['required', $this->belongsToCityAndProgramRule($cityId, $programId)],
        ]);

        $volunteerType = ContractType::all()->first(fn (ContractType $type): bool => $type->category === 'annual');

        return Contract::create([
            'employee_id' => $employee->id,
            'position_id' => (int) $this->position_id,
            'contract_type_id' => $volunteerType?->id,
            'type' => ContractTypeEnum::Draft,
            'is_active' => true,
        ]);
    }

    /**
     * Position::scopeInCity() is the single source of truth for city
     * scoping (CLAUDE.md) — the dropdown offering positions and this rule
     * validating the chosen one must both go through it, or a request that
     * bypasses the dropdown could reserve a position in another city. The
     * program check is the same idea one level down: the position dropdown
     * is only ever populated for one program at a time. A city-scoped HR
     * role is additionally pinned to its own city, matching
     * ResolvesContractLocationOptions::validateCityConsistency() on the main
     * contract form — the view never offers such a user any other city, but
     * a direct request could still try to name one.
     */
    private function belongsToCityAndProgramRule(?int $cityId, ?int $programId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($cityId, $programId): void {
            if (! auth()->user()?->canAccessCity($cityId)) {
                $fail(__('employees.validation.city-scope'));

                return;
            }

            $query = Position::query()->inCity($cityId ?? 0)->whereKey($value);

            if ($programId) {
                $query->where('program_id', $programId);
            }

            if (! $query->exists()) {
                $fail(__('validation.exists', ['attribute' => __('general.position')]));
            }
        };
    }
}
