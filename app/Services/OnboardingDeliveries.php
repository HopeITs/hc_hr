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
 * stored: a group of items is done when every item of that group that the
 * volunteer's city has a deliverer for (DefaultDeliverable) is delivered to
 * them (checklists.delivered_status = delivered). ChecklistWorkflow stays the
 * only writer of those rows.
 *
 * Groups (ChecklistItem::onboarding_group): electronics and card are steps of
 * the journey, "other" items count for completion without a deadline, the Mug
 * (after_probation) is outside the journey, and "exit" items are returns.
 */
class OnboardingDeliveries
{
    /**
     * @return array{configured: bool, complete: bool, items: Collection<int, array{id: int, name: string, delivered: bool, deliverer: ?Employee}>, missing: Collection<int, array{id: int, name: string, delivered: bool, deliverer: ?Employee}>}
     */
    public function group(Employee $employee, string $group): array
    {
        $rows = $employee->city_id === null
            ? collect()
            : DefaultDeliverable::query()
                ->where('city_id', $employee->city_id)
                ->whereHas('item', fn ($item) => $item->where('onboarding_group', $group))
                ->with(['item', 'employee.account'])
                ->orderBy('item_id')
                ->get();

        $delivered = $rows->isEmpty()
            ? collect()
            : Checklist::query()
                ->where('employee_id', $employee->id)
                ->whereIn('item_id', $rows->pluck('item_id'))
                ->where('delivered_status', 'delivered')
                ->pluck('item_id')
                ->flip();

        $items = $rows->groupBy('item_id')->map(fn (Collection $perItem): array => [
            'id' => (int) $perItem->first()->item_id,
            'name' => (string) $perItem->first()->item->name,
            'delivered' => $delivered->has($perItem->first()->item_id),
            'deliverer' => $perItem->first()->employee,
        ])->values();

        $missing = $items->reject(fn (array $item) => $item['delivered'])->values();

        return [
            'configured' => $items->isNotEmpty(),
            'complete' => $items->isNotEmpty() && $missing->isEmpty(),
            'items' => $items,
            'missing' => $missing,
        ];
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
}
