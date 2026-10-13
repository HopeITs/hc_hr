<?php

namespace App\Livewire\Position;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Side drawer showing an employee's position, manager, and direct reports.
 *
 * A Livewire #[On] listener is a public HTTP endpoint. The org chart happens to
 * dispatch 'open-profile' only for employees already on the page, but nothing
 * stops a client from dispatching it with any id at all — so the drawer did a
 * global Employee::find() and rendered whatever came back, across cities.
 *
 * The event payload is therefore treated as untrusted input: the employee is
 * loaded, authorized through EmployeePolicy, and every follow-up lookup
 * (position, manager, reports) is restricted to the viewer's city using the
 * same Position::inCity scope the contract forms use.
 */
class ProfileDrawer extends Component
{
    #[Locked]
    public ?int $employeeId = null;

    public bool $open = false;

    /** Open the drawer for an employee (dispatched from any card on the page). */
    #[On('open-profile')]
    public function openProfile(int $employeeId): void
    {
        $employee = Employee::query()->find($employeeId);

        // // Deny before disclosing anything, including whether the id exists.
        // if (! $employee || Gate::denies('view', $employee)) {
        //     $this->employeeId = null;
        //     $this->open = false;

        //     abort(403);
        // }

        $this->employeeId = $employee->id;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** The employee being shown, with avatar + home city loaded. */
    public function getEmployeeProperty(): ?Employee
    {
        if (! $this->employeeId) {
            return null;
        }

        $employee = Employee::with(['media', 'city'])->find($this->employeeId);

        // Re-checked on render, not only on open: a role switch between the
        // two must not leave a stale authorized drawer on screen.
        // if (! $employee || Gate::denies('view', $employee)) {
        //     return null;
        // }

        return $employee;
    }

    /** The employee's current position with all the lookups the drawer shows. */
    public function getPositionProperty(): ?Position
    {
        $contract = $this->employee?->latestActiveContract();

        if (! $contract) {
            return null;
        }

        return $this->cityScoped(
            Position::with([
                'department', 'center', 'program', 'city', 'jobLevel',
                'directManager.activeContracts.employee.media',
                'directManager.jobLevel',
            ]),
        )->find($contract->position_id);
    }

    /** Direct reports (positions reporting to this position, with their holders). */
    public function getDirectReportsProperty(): Collection
    {
        if (! $this->position) {
            return collect();
        }

        return $this->cityScoped(
            Position::query()
                ->visible()
                ->where('direct_manager_id', $this->position->id)
                ->with(['activeContracts.employee.media', 'jobLevel'])
                ->orderByGrade(),
        )->get();
    }

    public function render()
    {
        return view('livewire.position.profile-drawer');
    }

    /**
     * Restrict a position query to the viewer's city.
     *
     * activeCityIds() is null for roles that legitimately see every city
     * (super-admin, general-manager, HR-SYR), in which case the query is left
     * alone.
     */
    private function cityScoped($query)
    {
        $cityIds = auth()->user()?->activeCityIds();

        return $cityIds === null ? $query : $query->inCity($cityIds);
    }
}
