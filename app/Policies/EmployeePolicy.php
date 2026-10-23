<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\DirectoryAccess;
use Illuminate\Auth\Access\Response;

class EmployeePolicy
{
    /**
     * The HR record ability (employees.view within the active city scope).
     * Not the directory: colleagues look each other up through viewDirectory.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee)
            || ($user->hasActivePermission('employees.view') && $this->isInActiveCity($user, $employee));
    }

    /**
     * Directory tier (access-policy.md §4.3): self; every active colleague in
     * every city for any signed-in user; a former employee only for the
     * general manager, HR-SYR, super-admin and the regional HR of their city.
     *
     * Denied as not-found (§4.4): a record the viewer may not see answers
     * exactly like one that does not exist, so a former employee's existence
     * is not disclosed by a 403.
     */
    public function viewDirectory(User $user, Employee $employee): Response
    {
        return DirectoryAccess::canSeeDirectory($user, $employee)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Colleague records tier (access-policy.md §4.3, A2D-Q2): self, a
     * manager's direct reports, the general manager, HR-SYR and super-admin
     * everywhere, regional HR in its own cities. Never pay; that stays
     * User::canViewSalary() on top of the HR city scope.
     */
    public function viewRecords(User $user, Employee $employee): bool
    {
        return DirectoryAccess::canSeeRecords($user, $employee);
    }

    /**
     * The photo follows the Directory tier (access-policy.md §8, A2D-Q4): a
     * face is shown wherever the person is, so a regional HR officer sees an
     * active colleague's photo in another city like everyone else does, while
     * a former employee's photo is withheld exactly where their record is.
     */
    public function viewAvatar(User $user, Employee $employee): Response
    {
        return $this->viewDirectory($user, $employee);
    }

    public function create(User $user, int $cityId): bool
    {
        return $user->hasActivePermission('employees.create')
            && $user->canAccessCity($cityId);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasActivePermission('employees.update')
            && $this->isInActiveCity($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasActivePermission('employees.delete')
            && $this->isInActiveCity($user, $employee);
    }

    public function viewPrivateDocuments(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee)
            || ($user->hasActivePermission('employees.update') && $this->isInActiveCity($user, $employee));
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return (int) $user->employee_id === (int) $employee->id;
    }

    private function isInActiveCity(User $user, Employee $employee): bool
    {
        return $user->canAccessCity($employee->city_id);
    }
}
