<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function view(User $user, Position $position): bool
    {
        return $user->hasActivePermission('positions.view') && $this->isInActiveCity($user, $position);
    }

    public function create(User $user, ?int $cityId): bool
    {
        return $user->hasActivePermission('positions.create')
            && $user->canAccessCity($cityId);
    }

    public function update(User $user, Position $position): bool
    {
        return $user->hasActivePermission('positions.update') && $this->isInActiveCity($user, $position);
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->hasActivePermission('positions.delete') && $this->isInActiveCity($user, $position);
    }

    /** Setting or clearing the critical tag: HR and the administrators only. */
    public function markCritical(User $user, Position $position): bool
    {
        return $user->hasActivePermission('positions.mark-critical') && $this->isInActiveCity($user, $position);
    }

    /**
     * The suggested-replacements panel of a critical position: HR of its city,
     * HR Syria, the general manager and the administrators. Never a direct
     * manager or the candidate, even if the permission were handed out by hand.
     */
    public function viewReplacements(User $user, Position $position): bool
    {
        return $position->is_critical
            && $user->activeRoleIs('HR', 'general-manager', ...config('access-control.administrator_roles', []))
            && $user->hasActivePermission('positions.view-replacements')
            && $this->isInActiveCity($user, $position);
    }

    private function isInActiveCity(User $user, Position $position): bool
    {
        $activeCityIds = $user->activeCityIds();

        return $activeCityIds === null
            || Position::query()->inCity($activeCityIds)->whereKey($position)->exists();
    }
}
