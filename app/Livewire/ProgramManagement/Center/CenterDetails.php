<?php

namespace App\Livewire\ProgramManagement\Center;

use App\Models\Center;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CenterDetails extends Component
{
    #[Locked]
    public int $centerId;

    public function mount(Center $center): void
    {
        // Route-model binding resolves any id in the URL; without this a
        // regional user could read another city's centre detail page directly.
        Gate::authorize('view', $center);

        $this->centerId = (int) $center->getKey();
    }

    public function render()
    {
        $center = Center::query()->with('city')->findOrFail($this->centerId);
        Gate::authorize('view', $center);

        // Its own positions' contracts and the shared ones of the programs it serves (Study Zone).
        $activeContracts = Contract::query()
            ->activeNow()
            ->servingCenter($center)
            ->with(['contractType', 'position.program'])
            ->get();

        return view('livewire.program-management.center-details', [
            'center' => $center,
            'contractData' => $this->contractData($activeContracts),
            'programData' => $this->programData($activeContracts),
            'totalEmployees' => $center->activeEmployeesQuery()->count(),
            'totalAssignments' => $activeContracts->count(),
            'canViewEmployees' => auth()->user()?->hasActivePermission('employees.view') ?? false,
        ]);
    }

    /**
     * Load employee counts by contract type for this center.
     */
    private function contractData(Collection $contracts): array
    {
        $locale = app()->getLocale();

        // Group contracts by contract_type_id and count unique employees
        $grouped = $contracts->groupBy('contract_type_id')
            ->map(fn ($group) => $group->unique('employee_id')->count());

        if ($grouped->isEmpty()) {
            return [];
        }

        // Prepare groups (card titles are the only thing written by hand)
        $data = [
            'annual' => [
                'label' => $locale === 'ar' ? 'عقد سنوي' : 'Annual',
                'items' => [],
            ],
            'service' => [
                'label' => $locale === 'ar' ? 'عقد اتفاق خدمة' : 'Service',
                'items' => [],
            ],
            'trial' => [
                'label' => $locale === 'ar' ? 'عقد تجريبي' : 'Trial',
                'items' => [],
            ],
        ];

        foreach ($grouped as $typeId => $count) {
            $ct = $contracts->firstWhere('contract_type_id', $typeId)?->contractType;
            if (! $ct) {
                continue;
            }

            // Use English name to detect the group (annual / service / trial)
            $nameEn = strtolower($ct->name_en ?? '');
            $displayName = $locale === 'ar'
                ? ($ct->name_ar ?: $ct->name_en)
                : ($ct->name_en ?: $ct->name_ar);

            $groupKey = null;
            if (str_contains($nameEn, 'annual')) {
                $groupKey = 'annual';
            } elseif (str_contains($nameEn, 'agreement')) {
                $groupKey = 'service';
            } elseif (str_contains($nameEn, 'trial')) {
                $groupKey = 'trial';
            }

            // Unknown type → skip or put in a separate group if you like
            if (! $groupKey) {
                continue;
            }

            $data[$groupKey]['items'][] = [
                'label' => $displayName,
                'count' => $count,
            ];
        }

        // Remove empty groups so you don't get empty cards
        return array_filter(
            $data,
            fn ($group) => ! empty($group['items']),
        );
    }

    /**
     * Load employee counts by program for this center.
     */
    private function programData(Collection $contracts): array
    {
        return $contracts
            ->groupBy(fn ($contract) => $contract->position?->program_id ?? 0)
            ->map(function (Collection $programContracts): array {
                $program = $programContracts->first()?->position?->program;

                return [
                    'program' => $program?->name ?? __('general.unassigned-program'),
                    'count' => $programContracts->unique('employee_id')->count(),
                ];
            })
            ->values()
            ->toArray();
    }
}
