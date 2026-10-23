<?php

namespace App\Livewire\Position;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use App\Support\DirectoryAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Side drawer showing an employee's position, manager, and direct reports.
 *
 * Everything in it is Directory tier (docs/ux/access-policy.md §4.2, A2D-Q1):
 * photo, name, position, program, department, center, city, work contact,
 * board badge, manager and reports. Under DEC-01 and A2D-Q4 every signed-in
 * user, regional HR included, sees every active colleague at that tier in
 * every city, so the lookups are not city-scoped. What is scoped is who may
 * appear at all: EmployeePolicy::viewDirectory, which withholds former
 * employees from staff (A2D-Q3).
 *
 * openProfile() is a public HTTP endpoint that takes any id the client sends,
 * so the id is untrusted input. Unknown, malformed and not-visible ids all get
 * the same 404 (§4.4), and the check is repeated on every later request, so a
 * role switch cannot leave a drawer open that the new role may not see.
 */
class ProfileDrawer extends Component
{
    #[Locked]
    public ?int $employeeId = null;

    /** Set when this request closed a drawer the viewer may no longer see. */
    protected bool $dropped = false;

    /**
     * Re-check the open employee on every subsequent request, before any
     * action runs. The active role may have changed since it was opened.
     */
    public function hydrate(): void
    {
        if ($this->employeeId !== null && ! $this->visibleEmployee($this->employeeId)) {
            $this->employeeId = null;
            $this->dropped = true;
        }
    }

    /**
     * Load an employee into the drawer. Called by the drawer's own Alpine
     * handler for the 'open-profile' browser event every card dispatches, so
     * there is no #[On] listener — that would load each click twice.
     */
    public function openProfile(mixed $employeeId = null): void
    {
        $employee = $this->visibleEmployee($employeeId);

        // Deny before disclosing anything, including whether the id exists.
        if (! $employee) {
            $this->employeeId = null;

            abort(404);
        }

        $this->employeeId = $employee->id;
        $this->dropped = false;
    }

    /** The employee being shown, with avatar, home city and work email loaded. */
    public function getEmployeeProperty(): ?Employee
    {
        if (! $this->employeeId) {
            return null;
        }

        // Re-checked on render, not only on open: a role switch between the
        // two must not leave a stale authorized drawer on screen.
        return $this->visibleEmployee($this->employeeId, ['media', 'city', DirectoryAccess::ACCOUNT_COLUMNS]);
    }

    /** The employee's current position with all the lookups the drawer shows. */
    public function getPositionProperty(): ?Position
    {
        if (! $this->employee) {
            return null;
        }

        // Same contract as Employee::latestActiveContract(), without loading
        // its leave balances: the drawer only needs the position.
        $positionId = Contract::query()
            ->activeNow()
            ->where('employee_id', $this->employee->id)
            ->latest()
            ->value('position_id');

        if (! $positionId) {
            return null;
        }

        return Position::with([
            'department', 'center', 'program', 'city', 'jobLevel',
            'directManager.activeContracts' => DirectoryAccess::holders(auth()->user()),
            'directManager.activeContracts.employee' => DirectoryAccess::holderEmployee(),
            'directManager.jobLevel',
        ])->find($positionId);
    }

    /** Direct reports (positions reporting to this position, with their holders). */
    public function getDirectReportsProperty(): Collection
    {
        if (! $this->position) {
            return collect();
        }

        return Position::query()
            ->visible()
            ->where('direct_manager_id', $this->position->id)
            ->with([
                'activeContracts' => DirectoryAccess::holders(auth()->user()),
                'activeContracts.employee' => DirectoryAccess::holderEmployee(),
                'jobLevel',
            ])
            ->orderByGrade()
            ->get();
    }

    public function render()
    {
        // Tells the drawer's Alpine state to close, with the same generic
        // message a refused openProfile() shows.
        if ($this->dropped) {
            $this->dispatch('profile-unavailable');
        }

        return view('livewire.position.profile-drawer');
    }

    /**
     * The employee behind a client-supplied id, at Directory columns, or null
     * when the id is malformed, unknown, or not visible to the active role.
     * The three are deliberately indistinguishable to the caller.
     *
     * @param  array<int|string, mixed>  $with
     */
    private function visibleEmployee(mixed $employeeId, array $with = []): ?Employee
    {
        $user = auth()->user();

        if (! $user || ! (is_int($employeeId) || (is_string($employeeId) && ctype_digit($employeeId)))) {
            return null;
        }

        $employee = Employee::query()
            ->select(DirectoryAccess::COLUMNS)
            ->with($with)
            ->find((int) $employeeId);

        if (! $employee || Gate::forUser($user)->denies('viewDirectory', $employee)) {
            return null;
        }

        return $employee;
    }
}
