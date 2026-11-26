<?php

namespace App\Services\Folders;

use App\Models\Contract;
use App\Models\Folder;
use App\Models\FolderGroupShare;
use App\Models\MailListMember;
use App\Models\User;
use App\Services\Policies\PolicyFolder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what with a folder: the one rule the folders page, its files, the
 * dashboard cards, search and the assistant all read (decided 2026-11-24, the
 * Google Drive way).
 *
 *  - owner (folders.user_id, or the owner of a parent it inherits from): everything,
 *    deleting included;
 *  - editor: opens, uploads, makes subfolders, renames, and shares;
 *  - viewer: opens, previews, downloads.
 *
 * A folder is shared with people one by one (folder_user.role) or with a group
 * (folder_group_shares), kept as the group: who is in it now, through a running
 * contract or a mailing list, has access. A subfolder also takes its parent's
 * access unless inherits_access is off (subfolders narrowed before this rule).
 * The best role wins. An active administrator role counts as owner everywhere.
 *
 * The Policies folder (PolicyFolder) is the exception: every employee views it,
 * and HR holding employees.update owns it; its own sharing is not read.
 *
 * The folder role is the most a person may do; the role permissions (archives.*)
 * are a ceiling above it, checked on each Livewire action and on the download route.
 */
final class FolderAccess
{
    public const VIEWER = 'viewer';

    public const EDITOR = 'editor';

    public const OWNER = 'owner';

    /** The roles a share may give. */
    public const SHARE_ROLES = [self::VIEWER, self::EDITOR];

    public const GROUP_TYPES = ['department', 'job_level', 'program', 'center', 'city', 'mail_list'];

    private const RANK = [self::VIEWER => 1, self::EDITOR => 2, self::OWNER => 3];

    /** @var array<int, array<string, list<int>>> groups per user id, for this request */
    private array $groups = [];

    public function role(User $user, Folder $folder): ?string
    {
        if ($user->isActiveAdministrator()) {
            return self::OWNER;
        }

        if (app(PolicyFolder::class)->contains($folder)) {
            return PolicyFolder::canManage($user) ? self::OWNER : self::VIEWER;
        }

        $groups = $this->groupsOf($user);
        $best = null;
        $seen = [];

        // inherits_access is null on a model made in this request before it is re-read: the column's default, on.
        for ($current = $folder; $current !== null && ! isset($seen[$current->id]); $current = ($current->inherits_access ?? true) ? $current->parent : null) {
            $seen[$current->id] = true;

            if ((int) $current->user_id === (int) $user->id) {
                return self::OWNER;
            }

            $direct = $current->users()->whereKey($user->id)->first()?->pivot?->role;
            $best = $this->better($best, $direct);

            foreach ($current->groupShares as $share) {
                if (in_array((int) $share->group_id, $groups[$share->group_type] ?? [], true)) {
                    $best = $this->better($best, $share->role);
                }
            }
        }

        return $best;
    }

    public function canView(User $user, Folder $folder): bool
    {
        return $this->role($user, $folder) !== null;
    }

    /** Upload, make a subfolder, rename, share. */
    public function canEdit(User $user, Folder $folder): bool
    {
        return $this->atLeast($this->role($user, $folder), self::EDITOR);
    }

    public function canDelete(User $user, Folder $folder): bool
    {
        return $this->role($user, $folder) === self::OWNER;
    }

    public function atLeast(?string $role, string $needed): bool
    {
        return $role !== null && self::RANK[$role] >= self::RANK[$needed];
    }

    /**
     * Folders shared with the user by name or through a group they are in now, not
     * their own: the top of "Shared with me". What is inside comes by inheritance.
     */
    public function sharedWith(User $user): Builder
    {
        $groups = $this->groupsOf($user);

        return Folder::query()
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->where(function (Builder $q) use ($user, $groups): void {
                $q->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));

                foreach ($groups as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhereHas('groupShares', fn (Builder $s) => $s->where('group_type', $type)->whereIn('group_id', $ids));
                    }
                }
            });
    }

    /**
     * The people in a group now: a running contract on a position of that department,
     * job level, program, center or city (Position::scopeInCity for a city), or a
     * mailing list they are on and not leaving. People who left are never in a group.
     */
    public function members(string $type, int $id): Builder
    {
        $users = User::query()->whereHas('profile', fn (Builder $q) => $q->where('is_active', true));

        if ($type === 'mail_list') {
            return $users->whereIn('email', MailListMember::query()
                ->where('mail_list_id', $id)
                ->where('status', '!=', MailListMember::LEAVING)
                ->select('email'));
        }

        return $users->whereHas('profile.contracts.position', fn (Builder $position) => match ($type) {
            'department' => $position->where('department_id', $id),
            'job_level' => $position->where('job_level_id', $id),
            'program' => $position->where('program_id', $id),
            'center' => $position->where('center_id', $id),
            'city' => $position->inCity($id),
            default => $position->whereRaw('1 = 0'),
        });
    }

    /** @return array<string, list<int>> the groups the user is in now, by type */
    public function groupsOf(User $user): array
    {
        if (isset($this->groups[$user->id])) {
            return $this->groups[$user->id];
        }

        $groups = array_fill_keys(self::GROUP_TYPES, []);

        if ($user->profile?->is_active) {
            Contract::query()->activeNow()
                ->where('employee_id', $user->employee_id)
                ->with('position.center')
                ->get()
                ->each(function (Contract $contract) use (&$groups): void {
                    $position = $contract->position;

                    if ($position === null) {
                        return;
                    }

                    foreach (['department' => 'department_id', 'job_level' => 'job_level_id', 'program' => 'program_id', 'center' => 'center_id'] as $type => $column) {
                        if ($position->{$column}) {
                            $groups[$type][] = (int) $position->{$column};
                        }
                    }

                    if ($city = $position->city_id ?: $position->center?->city_id) {
                        $groups['city'][] = (int) $city;
                    }
                });

            if ($user->email) {
                $groups['mail_list'] = MailListMember::query()
                    ->where('email', $user->email)
                    ->where('status', '!=', MailListMember::LEAVING)
                    ->pluck('mail_list_id')->map(fn ($id) => (int) $id)->all();
            }
        }

        return $this->groups[$user->id] = array_map(fn (array $ids) => array_values(array_unique($ids)), $groups);
    }

    /** Whether $share's group currently includes $user. */
    public function inGroup(User $user, FolderGroupShare $share): bool
    {
        return in_array((int) $share->group_id, $this->groupsOf($user)[$share->group_type] ?? [], true);
    }

    private function better(?string $a, ?string $b): ?string
    {
        if (! isset(self::RANK[$b ?? ''])) {
            return $a;
        }

        return $a === null || self::RANK[$b] > self::RANK[$a] ? $b : $a;
    }
}
