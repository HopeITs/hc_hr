<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * What the delivery list (Checklist / DefaultDeliverable) says about a new
 * volunteer, read for the onboarding journey (track 7). Nothing is copied or
 * stored: a group of items is done when every item of that group is delivered
 * to them (checklists.delivered_status = delivered). ChecklistWorkflow stays
 * the only writer of those rows.
 *
 * Groups (ChecklistItem::onboarding_group): electronics and card are steps of
 * the journey, "other" items count for completion without a deadline, the Mug
 * (after_probation) is outside the journey, and "exit" items are returns.
 *
 * Who delivers: the electronics are handed over by IT of the volunteer's city.
 * With one IT person it is that person; with several, HR picks one for the
 * volunteer (OnboardingJourneys::chooseElectronicsDeliverer); with none the
 * step is blocked. Every other group uses the city's default deliverers
 * (DefaultDeliverable), and the items of those groups are the ones the city
 * names there.
 */
class OnboardingDeliveries
{
    public const IT_DEPARTMENT_CODE = 'IT';

    /**
     * @return array{configured: bool, complete: bool, items: Collection<int, array{id: int, name: string, delivered: bool, deliverer: ?Employee}>, missing: Collection<int, array{id: int, name: string, delivered: bool, deliverer: ?Employee}>}
     */
    public function group(Employee $employee, string $group): array
    {
        $electronics = $group === ChecklistItem::GROUP_ELECTRONICS;

        $entries = $electronics
            ? $this->electronicsEntries($employee)
            : $this->defaultEntries($employee, $group);

        $delivered = $entries->isEmpty()
            ? collect()
            : Checklist::query()
                ->where('employee_id', $employee->id)
                ->whereIn('item_id', $entries->pluck('id'))
                ->where('delivered_status', 'delivered')
                ->pluck('item_id')
                ->flip();

        $items = $entries->map(fn (array $entry): array => $entry + ['delivered' => $delivered->has($entry['id'])])->values();
        $missing = $items->reject(fn (array $item) => $item['delivered'])->values();

        return [
            'configured' => $items->isNotEmpty(),
            'complete' => $items->isNotEmpty() && $missing->isEmpty(),
            'items' => $items,
            'missing' => $missing,
        ];
    }

    /**
     * IT of the volunteer's city: active people with a running contract on a
     * position of the IT department, in the same city, never the volunteer.
     *
     * @return Collection<int, Employee>
     */
    public function itStaff(Employee $volunteer): Collection
    {
        if ($volunteer->city_id === null) {
            return collect();
        }

        return Employee::query()
            ->where('is_active', true)
            ->where('city_id', $volunteer->city_id)
            ->whereKeyNot($volunteer->id)
            ->whereHas('contracts', fn ($contract) => $contract
                ->activeNow()
                ->whereHas('position.department', fn ($department) => $department->where('code', self::IT_DEPARTMENT_CODE)))
            ->with('account')
            ->orderBy('id')
            ->get();
    }

    /**
     * People HR may pick to hand the electronics over: IT of the volunteer's
     * city, plus whoever the city's delivery list already names for them.
     *
     * @return Collection<int, Employee>
     */
    public function electronicsCandidates(Employee $volunteer): Collection
    {
        $named = $volunteer->city_id === null
            ? collect()
            : DefaultDeliverable::query()
                ->where('city_id', $volunteer->city_id)
                ->whereHas('item', fn ($item) => $item->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS))
                ->with('employee.account')
                ->get()
                ->pluck('employee')
                ->filter(fn (?Employee $employee) => $employee !== null && $employee->is_active && $employee->id !== $volunteer->id);

        return $this->itStaff($volunteer)->concat($named)->unique('id')->sortBy('id')->values();
    }

    /**
     * The single person handing every electronics item over, or null when it is
     * several people (the city names different ones per item) or nobody.
     */
    public function electronicsDeliverer(Employee $volunteer): ?Employee
    {
        $people = $this->group($volunteer, ChecklistItem::GROUP_ELECTRONICS)['items']
            ->pluck('deliverer')
            ->filter()
            ->unique('id');

        return $people->count() === 1 ? $people->first() : null;
    }

    /**
     * Whether a person may open the hand-over page: IT by position, anyone the
     * delivery list names, anyone with something waiting for them. The rows
     * themselves are scoped to the person (ChecklistWorkflow), so this only
     * decides who sees the page, not what they can touch.
     */
    public function mayHandOut(int $employeeId): bool
    {
        return Employee::query()
            ->whereKey($employeeId)
            ->whereHas('contracts', fn ($contract) => $contract
                ->activeNow()
                ->whereHas('position.department', fn ($department) => $department->where('code', self::IT_DEPARTMENT_CODE)))
            ->exists()
            || DefaultDeliverable::query()->where('employee_id', $employeeId)->exists()
            || Checklist::query()->where('delivered_id', $employeeId)->where('delivered_status', 'pending')->exists();
    }

    /**
     * The deliverers (with their login accounts) of the items still missing in a group.
     *
     * @return Collection<int, Employee>
     */
    public function deliverersOfMissing(Employee $employee, string $group): Collection
    {
        return $this->group($employee, $group)['missing']
            ->pluck('deliverer')
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * The Mug: due once the volunteer has a real contract that is not a trial
     * (after a trial, or from their first contract when they never had one),
     * delivered by whoever the city names for it. Not a step of the journey.
     *
     * @return array{due: bool, configured: bool, delivered: bool, items: Collection<int, array{id: int, name: string, delivered: bool, deliverer: ?Employee}>}
     */
    public function mug(Employee $employee): array
    {
        $group = $this->group($employee, ChecklistItem::GROUP_AFTER_PROBATION);

        return [
            'due' => $this->hasStartedNonTrialContract($employee),
            'configured' => $group['configured'],
            'delivered' => $group['complete'],
            'items' => $group['items'],
        ];
    }

    public function hasStartedNonTrialContract(Employee $employee): bool
    {
        $today = now(config('app.timezone'))->startOfDay();

        return $employee->realContracts()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->get()
            ->contains(fn ($contract) => ! $contract->isTrial());
    }

    /** @return Collection<int, array{id: int, name: string, deliverer: ?Employee}> */
    private function electronicsEntries(Employee $volunteer): Collection
    {
        $items = ChecklistItem::query()->where('onboarding_group', ChecklistItem::GROUP_ELECTRONICS)->orderBy('id')->get();

        $defaults = $volunteer->city_id === null || $items->isEmpty()
            ? collect()
            : DefaultDeliverable::query()
                ->where('city_id', $volunteer->city_id)
                ->whereIn('item_id', $items->pluck('id'))
                ->with('employee.account')
                ->orderBy('id')
                ->get()
                ->groupBy('item_id');

        $staff = $this->itStaff($volunteer);
        $chosenId = $volunteer->onboardingJourney?->electronics_deliverer_id;
        $chosen = $chosenId !== null ? $this->electronicsCandidatesFor($volunteer, $staff, $defaults)->firstWhere('id', $chosenId) : null;
        $only = $staff->count() === 1 ? $staff->first() : null;

        return $items->map(function (ChecklistItem $item) use ($defaults, $chosen, $only): array {
            $named = $defaults->get($item->id)?->pluck('employee')->first(fn (?Employee $e) => $e !== null && $e->is_active);

            return ['id' => (int) $item->id, 'name' => (string) $item->name, 'deliverer' => $chosen ?? $named ?? $only];
        })->values();
    }

    /** @param Collection<int, Employee> $staff */
    private function electronicsCandidatesFor(Employee $volunteer, Collection $staff, Collection $defaults): Collection
    {
        return $staff
            ->concat($defaults->flatten(1)->pluck('employee')->filter(fn (?Employee $e) => $e !== null && $e->is_active && $e->id !== $volunteer->id))
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, array{id: int, name: string, deliverer: ?Employee}> */
    private function defaultEntries(Employee $employee, string $group): Collection
    {
        if ($employee->city_id === null) {
            return collect();
        }

        return DefaultDeliverable::query()
            ->where('city_id', $employee->city_id)
            ->whereHas('item', fn ($item) => $item->where('onboarding_group', $group))
            ->with(['item', 'employee.account'])
            ->orderBy('item_id')
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $perItem): array => [
                'id' => (int) $perItem->first()->item_id,
                'name' => (string) $perItem->first()->item->name,
                'deliverer' => $perItem->first()->employee,
            ])
            ->values();
    }
}
