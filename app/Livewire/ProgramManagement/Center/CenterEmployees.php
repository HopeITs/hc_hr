<?php

namespace App\Livewire\ProgramManagement\Center;

use App\Models\Center;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CenterEmployees extends Component
{
    use WithPagination;

    #[Locked]
    public int $centerId;

    public string $search = '';

    #[Locked]
    public int $perPage = 12;

    public function mount(int $centerId): void
    {
        $this->centerId = $centerId;
        $this->authorizeCenter();
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage('centerEmployeesPage');
        }
    }

    public function render()
    {
        $center = $this->authorizeCenter();
        $employees = $this->employeeQuery($center)
            ->with([
                'media',
                'allContracts' => fn ($contracts) => $contracts
                    ->activeNow()
                    ->servingCenter($center)
                    ->with('position.program')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
            ])
            ->paginate($this->perPage, ['*'], 'centerEmployeesPage');

        return view('livewire.program-management.center.center-employees', [
            'center' => $center,
            'employees' => $employees,
            'totalEmployees' => $center->activeEmployeesQuery()->count(),
        ]);
    }

    private function authorizeCenter(): Center
    {
        $center = Center::query()->findOrFail($this->centerId);

        Gate::authorize('view', $center);
        abort_unless(auth()->user()?->hasActivePermission('employees.view'), 403);

        return $center;
    }

    private function employeeQuery(Center $center): Builder
    {
        $parts = preg_split('/\s+/u', trim($this->search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $center->activeEmployeesQuery()
            ->when($parts !== [], function (Builder $employees) use ($parts, $center): void {
                foreach ($parts as $part) {
                    $term = "%{$part}%";

                    $employees->where(function (Builder $match) use ($term, $center): void {
                        $match->where('first_name_en', 'like', $term)
                            ->orWhere('first_name_ar', 'like', $term)
                            ->orWhere('last_name_en', 'like', $term)
                            ->orWhere('last_name_ar', 'like', $term)
                            ->orWhere('username', 'like', $term)
                            ->orWhereHas('allContracts', function (Builder $contracts) use ($term, $center): void {
                                $contracts
                                    ->activeNow()
                                    ->servingCenter($center)
                                    ->where(function (Builder $assignment) use ($term): void {
                                        $assignment
                                            ->whereHas('position', fn (Builder $position): Builder => $position
                                                ->where('title_en', 'like', $term)
                                                ->orWhere('title_ar', 'like', $term)
                                                ->orWhereHas('program', fn (Builder $program): Builder => $program
                                                    ->where('name', 'like', $term)));
                                    });
                            });
                    });
                }
            })
            ->orderBy(transColumn('first_name'))
            ->orderBy(transColumn('last_name'));
    }
}
