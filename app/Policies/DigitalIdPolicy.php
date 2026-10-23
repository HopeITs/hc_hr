<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Who may manage an employee's public digital-ID card
 * (docs/ux/access-policy.md §9 A2D-Q5, option A).
 *
 * Registered as plain gates (digital-id.*) in AppServiceProvider rather than on
 * EmployeePolicy, so the card rules live in one place of their own.
 *
 * Every rule reads the *active* role, so a user who switched from HR to
 * another role loses these abilities on the next request.
 */
class DigitalIdPolicy
{
    /**
     * Disable, enable and re-issue: HR for the employee's city (a regional HR
     * role covering several cities covers each of them), national HR (HR-SYR,
     * which has no city scope) and super-admin. Nobody else, the employee
     * included.
     */
    public function manage(User $user, Employee $employee): bool
    {
        if ($user->activeRoleIs('super-admin')) {
            return true;
        }

        return $user->activeRoleIs('HR') && $user->canAccessCity($employee->city_id);
    }

    /**
     * Staff report their own card lost; HR then acts. Reporting changes
     * nothing on the card itself.
     */
    public function reportLost(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) && (bool) $employee->is_active;
    }

    /** The card panel on the profile page: card managers and the card's owner. */
    public function view(User $user, Employee $employee): bool
    {
        return $this->manage($user, $employee) || $this->isSelf($user, $employee);
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $employee->getKey();
    }
}
