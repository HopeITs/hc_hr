<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "Employees": the live card of the employees page. A quick search by name or
 * username, and with nothing typed the people added most recently. The same
 * rows the page's table can show: active employees in the viewer's active
 * cities (Employee::inActiveCities), behind the page's own employees.view.
 */
class EmployeesCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public const LIMIT = 6;

    public string $q = '';

    public function render()
    {
        return view('livewire.dashboard.employees-card', [
            'people' => $this->guardedLoad(fn () => $this->people(), fallback: new Collection),
        ]);
    }

    /** @return Collection<int, Employee> */
    protected function people(): Collection
    {
        $user = auth()->user();

        if (! $user || ! $user->hasActivePermission('employees.view')) {
            return new Collection;
        }

        $q = trim(mb_substr($this->q, 0, 60));

        return Employee::query()
            ->inActiveCities($user)
            ->where('employees.is_active', true)
            ->when($q !== '', fn ($query) => $query->whereLikeAny(['first_name_en', 'last_name_en', 'first_name_ar', 'last_name_ar', 'username'], $q))
            ->with(['media', 'contracts.position'])
            ->latest('employees.id')
            ->limit(self::LIMIT)
            ->get();
    }
}
