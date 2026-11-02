<?php

namespace App\Services\Assistant\Drafts;

use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The All leaves page's own "Add" choices, read without the page.
 *
 * Never mounted or rendered: it only exposes the page's protected lists, so
 * the assistant offers exactly the employees, balances and alternatives the
 * page's dialog offers (scopedEmployees(): the HR role's cities; leavesFor();
 * alternativeEmployeesFor()). Each reads the requester from auth(), so the
 * caller runs it as the user (LeaveForEmployeeDrafter::asUser()).
 */
class LeaveForEmployeeScreen extends AllLeaveRequestModule
{
    /**
     * Who may file for an employee: the header's New entry and the page's own
     * gates (route active_permission:leaves.view-all, create()).
     */
    public static function allows(User $user): bool
    {
        return $user->hasActivePermission('leaves.create')
            && $user->hasActivePermission('leaves.view-all')
            && $user->activeRoleIs('HR', 'super-admin');
    }

    /** The page's employee dropdown for the signed-in user. */
    public function employees(): Collection
    {
        return $this->scopedEmployees();
    }

    /** One employee of that dropdown, or null when the user may not file for them. */
    public function employee(mixed $id): ?Employee
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);

        return $id === false ? null : $this->employees()->first(fn (Employee $employee) => (int) $employee->id === $id);
    }

    /** The page's leave-type dropdown for $employee. */
    public function balances(Employee $employee): Collection
    {
        return $this->leavesFor($employee);
    }

    /** The page's alternative-employee dropdown for $employee. */
    public function alternatives(Employee $employee): Collection
    {
        return $this->alternativeEmployeesFor($employee);
    }
}
