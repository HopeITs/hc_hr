<?php

namespace App\Livewire\AccessManagement;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\AccessPermissionCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\PermissionRegistrar;

class Index extends Component
{
    use WithPagination;

    private const COMPACT_EMPLOYEES_PER_PAGE = 12;

    private const EXPANDED_EMPLOYEES_PER_PAGE = 24;

    #[Url(as: 'tab')]
    public string $activeTab = 'employees';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $roleFilter = 'all';

    #[Url]
    public string $statusFilter = 'all';

    public ?int $selectedUserId = null;

    /** @var array<int, int|string> */
    public array $employeeRoleIds = [];

    public ?int $employeeActiveRoleId = null;

    /** @var array<int, int|string> */
    public array $employeePermissionIds = [];

    /** @var array<int, int> */
    #[Locked]
    public array $originalEmployeeRoleIds = [];

    #[Locked]
    public ?int $originalEmployeeActiveRoleId = null;

    /** @var array<int, int> */
    #[Locked]
    public array $originalEmployeePermissionIds = [];

    public ?int $selectedRoleId = null;

    /** @var array<int, int|string> */
    public array $rolePermissionIds = [];

    public string $newRoleName = '';

    public string $selectedRoleName = '';

    public bool $showAssignedUsersModal = false;

    public function mount(): void
    {
        $this->authorizeView();

        $this->selectedRoleId = Role::query()->orderBy('name')->value('id');
        $this->loadSelectedRole();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEmployeeRoleIds(): void
    {
        $this->employeeRoleIds = array_values(array_unique(array_map(
            'intval',
            $this->employeeRoleIds,
        )));

        if (! in_array($this->employeeActiveRoleId, $this->employeeRoleIds, true)) {
            // A select element visually falls back to its first option when the
            // previous role disappears, but Livewire retains the old value.
            // Keep the server-side active role synchronized with that UI state.
            $this->employeeActiveRoleId = $this->employeeRoleIds[0] ?? null;
        }

        $this->resetValidation([
            'employeeRoleIds',
            'employeeRoleIds.*',
            'employeeActiveRoleId',
        ]);
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, ['employees', 'roles'], true), 404);
        $this->activeTab = $tab;
        $this->showAssignedUsersModal = false;
    }

    public function selectUser(int $userId): void
    {
        $this->authorizeView();

        $user = $this->findManagedUser($userId);
        $directoryPosition = (clone $this->employeeQuery())
            ->where('users.email', '<', $user->email)
            ->count();

        $this->selectedUserId = $user->id;
        $this->setPage(intdiv($directoryPosition, self::EXPANDED_EMPLOYEES_PER_PAGE) + 1);
        $this->employeeRoleIds = $user->roles->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->employeeActiveRoleId = $user->activeRole()?->id
            ?? ($this->employeeRoleIds[0] ?? null);
        $this->employeePermissionIds = $user->permissions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->originalEmployeeRoleIds = $this->normalizeIds($this->employeeRoleIds);
        $this->originalEmployeeActiveRoleId = $this->employeeActiveRoleId;
        $this->originalEmployeePermissionIds = $this->normalizeIds($this->employeePermissionIds);
        unset($this->employeeAccessIsDirty);
        $this->resetValidation();
    }

    #[Computed]
    public function employeeAccessIsDirty(): bool
    {
        if (! $this->selectedUserId) {
            return false;
        }

        return $this->normalizeIds($this->employeeRoleIds) !== $this->originalEmployeeRoleIds
            || $this->employeeActiveRoleId !== $this->originalEmployeeActiveRoleId
            || $this->normalizeIds($this->employeePermissionIds) !== $this->originalEmployeePermissionIds;
    }

    public function saveEmployeeAccess(ActivityLogger $activityLogger): void
    {
        $this->authorizePermission('access-management.manage-employees');

        if (! $this->employeeAccessIsDirty) {
            return;
        }

        $validated = $this->validate([
            'selectedUserId' => ['required', 'integer', Rule::exists('users', 'id')],
            'employeeRoleIds' => ['required', 'array', 'min:1'],
            'employeeRoleIds.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'employeeActiveRoleId' => ['required', 'integer', Rule::in(array_map('intval', $this->employeeRoleIds))],
            'employeePermissionIds' => ['array'],
            'employeePermissionIds.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
        ]);

        // Both checks run before anything is written, and before the
        // self-edit guard below — a request that tries to escalate is refused
        // outright rather than partially applied.
        $this->assertMayAssignRoles($validated['employeeRoleIds']);
        $this->assertMayGrantPermissions($validated['employeePermissionIds'] ?? [], 'employeePermissionIds');

        $target = $this->findManagedUser((int) $validated['selectedUserId']);
        $before = [
            'roles' => $target->roles->pluck('name')->values()->all(),
            'active_role' => $target->myRole(),
            'direct_permissions' => $target->permissions->pluck('name')->values()->all(),
        ];
        $externalPermissionIds = $target->permissions()
            ->whereNotIn('name', AccessPermissionCatalog::names())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($target->is(auth()->user())) {
            $currentActiveRoleId = auth()->user()->activeRole()?->id;
            $keepsAdministratorAccess = in_array($currentActiveRoleId, array_map('intval', $validated['employeeRoleIds']), true)
                && (int) $validated['employeeActiveRoleId'] === $currentActiveRoleId;

            if (! $keepsAdministratorAccess) {
                throw ValidationException::withMessages([
                    'employeeActiveRoleId' => __('access.cannot-edit-self'),
                ]);
            }
        }

        DB::transaction(function () use ($target, $validated, $externalPermissionIds): void {
            $roleIds = array_map('intval', $validated['employeeRoleIds']);

            // Spatie's role sync owns membership; the application's
            // authoritative helper then guarantees exactly one active pivot.
            $target->roles()->sync($roleIds);
            $target->setActiveRole((int) $validated['employeeActiveRoleId']);
            $target->syncPermissions(array_values(array_unique([
                ...$externalPermissionIds,
                ...array_map('intval', $validated['employeePermissionIds'] ?? []),
            ])));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target->unsetRelation('roles')->unsetRelation('permissions');

        $activityLogger->log(
            'Employee access updated',
            $target,
            properties: [
                'before' => $before,
                'after' => [
                    'roles' => $target->roles()->pluck('name')->values()->all(),
                    'active_role' => $target->myRole(),
                    'direct_permissions' => $target->permissions()->pluck('name')->values()->all(),
                ],
            ],
            logName: 'access-management',
        );

        session()->flash('access-success', __('access.access-saved'));
        $this->selectUser($target->id);
    }

    public function selectRole(int $roleId): void
    {
        $this->authorizeView();
        $this->selectedRoleId = Role::query()->whereKey($roleId)->value('id');
        abort_unless($this->selectedRoleId, 404);
        $this->showAssignedUsersModal = false;
        $this->loadSelectedRole();
    }

    public function showAssignedUsers(): void
    {
        $this->authorizeView();
        abort_unless(
            $this->selectedRoleId && Role::query()->whereKey($this->selectedRoleId)->exists(),
            404,
        );

        $this->showAssignedUsersModal = true;
    }

    public function saveRolePermissions(ActivityLogger $activityLogger): void
    {
        $this->authorizePermission('access-management.manage-permissions');
        $role = Role::query()->with('permissions')->findOrFail($this->selectedRoleId);

        $this->validate([
            'rolePermissionIds' => ['array'],
            'rolePermissionIds.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
        ]);

        $before = $role->permissions->pluck('name')->values()->all();
        $externalPermissionIds = $role->permissions()
            ->whereNotIn('name', AccessPermissionCatalog::names())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $permissionIds = $this->isAdministratorRole($role)
            ? Permission::query()->whereNotIn('name', AccessPermissionCatalog::personLevelNames())->pluck('id')->all()
            : array_values(array_unique([
                ...$externalPermissionIds,
                // Anything already on the role is preserved even if the actor
                // does not hold it; only newly *added* permissions are gated,
                // so a delegated administrator cannot silently strip authority
                // they cannot see either.
                ...$this->assertMayGrantPermissions(
                    array_diff(
                        array_map('intval', $this->rolePermissionIds),
                        $role->permissions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    ),
                    'rolePermissionIds',
                ),
                ...$role->permissions
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->intersect(array_map('intval', $this->rolePermissionIds))
                    ->all(),
            ]));

        // never_granted_to_roles is enforced here, whatever was ticked or
        // held before: no save leaves a role carrying one of them.
        $permissionIds = array_values(array_diff(
            array_map('intval', $permissionIds),
            Permission::query()->whereIn('name', AccessPermissionCatalog::personLevelNames())->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ));

        $role->syncPermissions($permissionIds);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $activityLogger->log(
            'Role permissions updated',
            $role,
            properties: [
                'before' => $before,
                'after' => $role->permissions()->pluck('name')->values()->all(),
            ],
            logName: 'access-management',
        );

        session()->flash('access-success', __('access.role-permissions-saved'));
        $this->loadSelectedRole();
    }

    public function selectAllRolePermissions(): void
    {
        $this->authorizePermission('access-management.manage-permissions');
        $this->rolePermissionIds = Permission::query()
            ->whereIn('name', AccessPermissionCatalog::roleGrantableNames())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function clearRolePermissions(): void
    {
        $this->authorizePermission('access-management.manage-permissions');
        $role = Role::query()->findOrFail($this->selectedRoleId);

        if (! $this->isAdministratorRole($role)) {
            $this->rolePermissionIds = [];
        }
    }

    public function createRole(ActivityLogger $activityLogger): void
    {
        $this->authorizePermission('access-management.manage-roles');
        $this->newRoleName = Str::slug($this->newRoleName);

        $validated = $this->validate([
            'newRoleName' => [
                'required', 'string', 'min:2', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
        ]);

        $role = Role::query()->create(['name' => $validated['newRoleName'], 'guard_name' => 'web']);
        $activityLogger->log('Role created', $role, logName: 'access-management');

        $this->newRoleName = '';
        $this->selectedRoleId = $role->id;
        $this->loadSelectedRole();
        session()->flash('access-success', __('access.role-created'));
    }

    public function renameRole(ActivityLogger $activityLogger): void
    {
        $this->authorizePermission('access-management.manage-roles');
        $role = Role::query()->findOrFail($this->selectedRoleId);
        $this->ensureCustomRole($role);
        $this->selectedRoleName = Str::slug($this->selectedRoleName);

        $validated = $this->validate([
            'selectedRoleName' => [
                'required', 'string', 'min:2', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id),
            ],
        ]);

        $before = $role->name;
        $role->update(['name' => $validated['selectedRoleName']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $activityLogger->log(
            'Role renamed',
            $role,
            properties: ['before' => $before, 'after' => $role->name],
            logName: 'access-management',
        );

        session()->flash('access-success', __('access.role-renamed'));
        $this->loadSelectedRole();
    }

    public function deleteRole(ActivityLogger $activityLogger): void
    {
        $this->authorizePermission('access-management.manage-roles');
        $role = Role::query()->withCount('users')->findOrFail($this->selectedRoleId);
        $this->ensureCustomRole($role);

        if ($role->users_count > 0) {
            throw ValidationException::withMessages(['selectedRoleName' => __('access.role-in-use')]);
        }

        $activityLogger->log(
            'Role deleted',
            $role,
            properties: ['name' => $role->name],
            logName: 'access-management',
        );
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->selectedRoleId = Role::query()->orderBy('name')->value('id');
        $this->loadSelectedRole();
        session()->flash('access-success', __('access.role-deleted'));
    }

    public function render(): View
    {
        $this->authorizeView();

        $roles = Role::query()->withCount('users')->orderBy('name')->get();
        $permissions = Permission::query()
            ->whereIn('name', AccessPermissionCatalog::names())
            ->orderBy('name')
            ->get();

        $selectedUser = $this->selectedUserId
            ? User::query()->with(['profile.city', 'roles', 'permissions'])->find($this->selectedUserId)
            : null;
        $selectedRole = $this->selectedRoleId
            ? $roles->firstWhere('id', $this->selectedRoleId)
            : null;
        $selectedRoleUsers = $this->showAssignedUsersModal && $selectedRole
            ? $selectedRole->users()
                ->with(['profile.city'])
                ->orderBy('email')
                ->get()
            : collect();

        return view('livewire.access-management.index', [
            'employees' => $this->employeeQuery()->paginate(
                $this->selectedUserId
                    ? self::EXPANDED_EMPLOYEES_PER_PAGE
                    : self::COMPACT_EMPLOYEES_PER_PAGE,
            ),
            'roles' => $roles,
            'permissionGroups' => AccessPermissionCatalog::forDisplay($permissions),
            // Person-level permissions are granted to users only, so the role
            // panel never offers them.
            'rolePermissionGroups' => AccessPermissionCatalog::forDisplay(
                $permissions->reject(fn (Permission $permission): bool => in_array($permission->name, AccessPermissionCatalog::personLevelNames(), true)),
            ),
            'selectedUser' => $selectedUser,
            'selectedRole' => $selectedRole,
            'selectedRoleUsers' => $selectedRoleUsers,
            'selectedRoleIsSystem' => $selectedRole ? $this->isSystemRole($selectedRole) : false,
            'selectedRoleIsAdministrator' => $selectedRole ? $this->isAdministratorRole($selectedRole) : false,
            'canManageEmployees' => auth()->user()->hasActivePermission('access-management.manage-employees'),
            'canManageRoles' => auth()->user()->hasActivePermission('access-management.manage-roles'),
            'canManagePermissions' => auth()->user()->hasActivePermission('access-management.manage-permissions'),
        ]);
    }

    private function employeeQuery(): Builder
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return User::query()
            ->with(['profile.city', 'roles'])
            ->whereNotNull('employee_id')
            ->whereHas('profile')
            ->tap(fn (Builder $query) => $this->scopeToManagedCity($query))
            ->when($this->search !== '', function (Builder $query) use ($locale): void {
                $search = '%'.trim($this->search).'%';
                $query->where(function (Builder $query) use ($search, $locale): void {
                    $query->where('email', 'like', $search)
                        ->orWhereHas('profile', fn (Builder $profile): Builder => $profile
                            ->where("first_name_{$locale}", 'like', $search)
                            ->orWhere("last_name_{$locale}", 'like', $search)
                            ->orWhere('username', 'like', $search));
                });
            })
            ->when($this->roleFilter !== 'all', fn (Builder $query): Builder => $query
                ->whereHas('roles', fn (Builder $roles): Builder => $roles->whereKey((int) $this->roleFilter)))
            ->when($this->statusFilter !== 'all', fn (Builder $query): Builder => $query
                ->whereHas('profile', fn (Builder $profile): Builder => $profile
                    ->where('is_active', $this->statusFilter === 'active')))
            ->orderBy('email');
    }

    private function findManagedUser(int $userId): User
    {
        return User::query()
            ->with(['profile.city', 'roles', 'permissions'])
            ->whereNotNull('employee_id')
            ->whereHas('profile')
            ->tap(fn (Builder $query) => $this->scopeToManagedCity($query))
            ->findOrFail($userId);
    }

    /**
     * Restrict account administration to the actor's own city.
     *
     * Latent rather than live today, because only administrator roles hold
     * access-management.manage-employees and those roles are nationwide by
     * design. It stops being latent the moment that permission is granted to a
     * regional role through this very screen — at which point the role would
     * otherwise administer accounts across the whole country.
     */
    private function scopeToManagedCity(Builder $query): Builder
    {
        $cityIds = auth()->user()?->activeCityIds();

        return $cityIds === null
            ? $query
            : $query->whereHas('profile', fn (Builder $profile): Builder => $profile->whereIn('city_id', $cityIds));
    }

    private function loadSelectedRole(): void
    {
        if (! $this->selectedRoleId) {
            $this->selectedRoleName = '';
            $this->rolePermissionIds = [];

            return;
        }

        $role = Role::query()->with('permissions')->findOrFail($this->selectedRoleId);
        $this->selectedRoleName = $role->name;
        $this->rolePermissionIds = $role->permissions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->resetValidation();
    }

    private function authorizeView(): void
    {
        $this->authorizePermission('access-management.view');
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(auth()->user()?->hasActivePermission($permission), 403);
    }

    private function isAdministratorRole(Role $role): bool
    {
        return in_array($role->name, config('access-control.administrator_roles', []), true);
    }

    /**
     * Only an active super-admin may hand out administrator roles.
     *
     * Without this, holding access-management.manage-employees was enough to
     * create a super-admin. That permission is granted automatically to every
     * role in access-control.administrator_roles — which includes HR-SYR — so
     * headquarters HR could promote any account in the system to full
     * administrator. Self-editing was already blocked, but promoting a second
     * account they control is the same outcome one step removed.
     *
     * @param  array<int, int|string>  $roleIds
     */
    private function assertMayAssignRoles(array $roleIds): void
    {
        if (auth()->user()?->isActiveAdministrator()) {
            return;
        }

        $privileged = Role::query()
            ->whereKey(array_map('intval', $roleIds))
            ->get()
            ->filter(fn (Role $role): bool => $this->isAdministratorRole($role));

        if ($privileged->isNotEmpty()) {
            throw ValidationException::withMessages([
                'employeeRoleIds' => __('access.cannot-assign-administrator-role', [
                    'roles' => $privileged->pluck('name')->implode(', '),
                ]),
            ]);
        }
    }

    /**
     * Nobody may grant a permission they do not themselves hold.
     *
     * Closes the second half of the same escalation. Blocking assignment of the
     * super-admin *role* alone would still let a delegated administrator create
     * an ordinary custom role, grant it every permission in the catalogue, and
     * assign that instead — different name, same authority. The rule here is
     * the standard one: delegation can narrow authority, never widen it.
     *
     * @param  array<int, int|string>  $permissionIds
     * @return array<int, int> the permission ids, unchanged, when all are allowed
     */
    private function assertMayGrantPermissions(array $permissionIds, string $field): array
    {
        $permissionIds = array_map('intval', $permissionIds);
        $user = auth()->user();

        if ($user?->isActiveAdministrator()) {
            return $permissionIds;
        }

        $granting = Permission::query()->whereKey($permissionIds)->pluck('name', 'id');

        $beyond = $granting->reject(fn (string $name): bool => $user?->hasActivePermission($name) ?? false);

        if ($beyond->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field => __('access.cannot-grant-unheld-permission', [
                    'permissions' => $beyond->values()->implode(', '),
                ]),
            ]);
        }

        return $permissionIds;
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, config('access-control.system_roles', []), true);
    }

    private function ensureCustomRole(Role $role): void
    {
        if ($this->isSystemRole($role)) {
            throw ValidationException::withMessages([
                'selectedRoleName' => __('access.system-role-locked'),
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));
        sort($normalized);

        return $normalized;
    }
}
