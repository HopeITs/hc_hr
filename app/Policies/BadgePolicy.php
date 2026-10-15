<?php

namespace App\Policies;

use App\Models\Badge;
use App\Models\Employee;
use App\Models\User;

class BadgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasActivePermission('badges.view');
    }

    public function view(User $user, Badge $badge): bool
    {
        return $this->canAccessEmployee($user, $badge->employee, 'badges.view');
    }

    /** May the user see $employee's badges at all, e.g. the card on their profile? */
    public function viewForEmployee(User $user, Employee $employee): bool
    {
        return $this->canAccessEmployee($user, $employee, 'badges.view');
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->canAccessEmployee($user, $employee, 'badges.create');
    }

    public function delete(User $user, Badge $badge): bool
    {
        return $this->canAccessEmployee($user, $badge->employee, 'badges.delete');
    }

    private function canAccessEmployee(User $user, ?Employee $employee, string $permission): bool
    {
        if (! $employee || ! $user->hasActivePermission($permission)) {
            return false;
        }

        return Badge::coversEmployee($user, $employee);
    }
}
