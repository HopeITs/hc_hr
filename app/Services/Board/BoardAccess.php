<?php

namespace App\Services\Board;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Who is on the board and who may do what with its meetings.
 *
 * A member needs no permission: being a current board member on the employee record is enough to
 * read the meetings and the published minutes. The chair (board-meetings.manage) schedules and decides
 * absence requests; the secretary (board-meetings.write-minutes) keeps attendance and writes the minutes.
 * Both are granted per user (config access-control.never_granted_to_roles).
 */
class BoardAccess
{
    public const MANAGE = 'board-meetings.manage';

    public const WRITE = 'board-meetings.write-minutes';

    /** @return Builder<Employee> */
    public function currentMembers(): Builder
    {
        return Employee::query()
            ->where('is_board_member', true)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('board_member_started_at')->orWhere('board_member_started_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('board_member_end_at')->orWhere('board_member_end_at', '>', now()));
    }

    public function isMember(?User $user): bool
    {
        return $user?->employee_id !== null
            && $this->currentMembers()->whereKey($user->employee_id)->exists();
    }

    public function canManage(?User $user): bool
    {
        return (bool) $user?->hasActivePermission(self::MANAGE);
    }

    public function canWrite(?User $user): bool
    {
        return (bool) $user?->hasActivePermission(self::WRITE);
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($this->isMember($user) || $this->canManage($user) || $this->canWrite($user));
    }

    /**
     * Whether the menu shows the board's pages: the members and whoever was given the chair's or the
     * secretary's permission on purpose. An administrator can still open them (canView), but is not shown a
     * menu entry for a board they are not on.
     */
    public function inMenu(?User $user): bool
    {
        return $user !== null && ($this->isMember($user)
            || $user->hasExplicitPermission(self::MANAGE)
            || $user->hasExplicitPermission(self::WRITE));
    }

    /** The draft of minutes is for those who write or run the meetings; members read what is published. */
    public function canSeeDraft(?User $user): bool
    {
        return $this->canManage($user) || $this->canWrite($user);
    }

    /**
     * Everyone a meeting concerns: the current members and the two who run it.
     *
     * @return Collection<int, User>
     */
    public function audience(): Collection
    {
        $memberAccounts = User::query()
            ->whereIn('employee_id', $this->currentMembers()->select('employees.id'))
            ->get();

        return $memberAccounts->merge($this->holders(self::MANAGE))->merge($this->holders(self::WRITE))->unique('id')->values();
    }

    /** @return Collection<int, User> */
    public function holders(string $permission): Collection
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return collect();
        }

        return User::permission($permission)->get();
    }
}
