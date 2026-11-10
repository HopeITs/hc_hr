<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Employee;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One person's contracts (DashboardLayout::RECORDS "contracts"), added from
 * the career history section of their profile. The same rule as that
 * section: EmployeePolicy::viewRecords, asked on every render; never pay.
 */
class EmployeeContractsCard extends Component
{
    use HandlesLoadFailure;

    #[Locked]
    public int $employeeId;

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::recordKey('contracts', (int) ($params['employeeId'] ?? 0));

        return view('livewire.dashboard.card-placeholder', ['title' => app(DashboardLayout::class)->label($key)]);
    }

    public function render()
    {
        $employee = Employee::query()->find($this->employeeId);
        $allowed = $employee && Gate::allows('viewRecords', $employee);

        return view('livewire.dashboard.employee-contracts-card', [
            'employee' => $allowed ? $employee : null,
            // The profile's own list (Employee::allContracts), newest first; pay is never selected.
            'contracts' => $allowed
                ? $this->guardedLoad(fn () => $employee->allContracts()
                    ->with(['position.department', 'contractType'])
                    ->reorder('start_date', 'desc')
                    ->limit(6)
                    ->get(['contracts.id', 'contracts.employee_id', 'contracts.position_id', 'contracts.contract_type_id', 'contracts.start_date', 'contracts.end_date', 'contracts.terminated_at', 'contracts.is_active']), fallback: collect())
                : collect(),
        ]);
    }
}
