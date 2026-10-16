<?php

namespace Tests\Feature;

use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Http\Middleware\ActivePermissionMiddleware;
use App\Livewire\AccessManagement\Index;
use App\Livewire\Basics\DepartmentModule;
use App\Livewire\Layout\Sidebar;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_hr_syr_and_super_admin_can_open_access_management(): void
    {
        foreach (['HR-SYR', 'super-admin'] as $roleName) {
            $user = $this->createUserWithActiveRole($roleName);

            $this->actingAs($user)
                ->get(route('access-management.index'))
                ->assertOk()
                ->assertSee(__('access.title'));
        }
    }

    public function test_other_active_roles_cannot_open_access_management(): void
    {
        foreach (['HR-ALP', 'general-manager', 'manager', 'employee'] as $roleName) {
            $user = $this->createUserWithActiveRole($roleName);

            $this->actingAs($user)
                ->get(route('access-management.index'))
                ->assertForbidden();
        }
    }

    public function test_direct_view_permission_shows_navigation_and_opens_access_management_read_only(): void
    {
        $viewer = $this->createUserWithActiveRole('manager');
        $viewer->givePermissionTo('access-management.view');
        $target = $this->createUserWithActiveRole('employee');

        $this->actingAs($viewer)
            ->get(route('access-management.index'))
            ->assertOk()
            ->assertSee(__('access.title'));

        Livewire::actingAs($viewer)
            ->test(Sidebar::class)
            ->assertSeeHtml('href="'.route('access-management.index').'"');

        Livewire::actingAs($viewer)
            ->test(Index::class)
            ->assertSeeHtml('data-access-mode="read-only"')
            ->call('selectUser', $target->id)
            ->assertSet('selectedUserId', $target->id)
            ->assertDontSeeHtml('data-testid="save-employee-access"')
            ->call('showTab', 'roles')
            ->assertDontSeeHtml('data-testid="create-role-form"')
            ->assertDontSeeHtml('data-testid="save-role-permissions"');
    }

    public function test_view_only_permission_cannot_invoke_any_access_management_mutation(): void
    {
        $viewer = $this->createUserWithActiveRole('manager');
        $viewer->givePermissionTo('access-management.view');
        $target = $this->createUserWithActiveRole('employee');
        $customRole = Role::findOrCreate('view-only-target', 'web');
        $permission = Permission::findOrCreate('statistics.view', 'web');

        Livewire::actingAs($viewer)
            ->test(Index::class)
            ->call('selectUser', $target->id)
            ->set('employeePermissionIds', [$permission->id])
            ->call('saveEmployeeAccess')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(Index::class)
            ->set('newRoleName', 'forbidden-role')
            ->call('createRole')
            ->assertForbidden();

        foreach (['renameRole', 'deleteRole'] as $method) {
            Livewire::actingAs($viewer)
                ->test(Index::class)
                ->call('selectRole', $customRole->id)
                ->set('selectedRoleName', 'forbidden-change')
                ->call($method)
                ->assertForbidden();
        }

        foreach (['saveRolePermissions', 'selectAllRolePermissions', 'clearRolePermissions'] as $method) {
            Livewire::actingAs($viewer)
                ->test(Index::class)
                ->call('selectRole', $customRole->id)
                ->set('rolePermissionIds', [$permission->id])
                ->call($method)
                ->assertForbidden();
        }

        $this->assertFalse($target->fresh()->hasDirectPermission($permission));
        $this->assertDatabaseMissing('roles', ['name' => 'forbidden-role']);
        $this->assertDatabaseHas('roles', ['id' => $customRole->id, 'name' => 'view-only-target']);
        $this->assertFalse($customRole->fresh()->hasPermissionTo($permission));
    }

    public function test_each_access_management_capability_only_allows_its_own_mutations(): void
    {
        $target = $this->createUserWithActiveRole('employee');
        $directAccessPermission = Permission::findOrCreate('access-management.view', 'web');
        $rolePermission = Permission::findOrCreate('statistics.view', 'web');

        $employeeManager = $this->createUserWithActiveRole('employee-access-manager');
        $employeeManager->givePermissionTo([
            'access-management.view',
            'access-management.manage-employees',
        ]);

        Livewire::actingAs($employeeManager)
            ->test(Index::class)
            ->call('selectUser', $target->id)
            ->set('employeePermissionIds', [$directAccessPermission->id])
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors()
            ->set('newRoleName', 'not-allowed-role')
            ->call('createRole')
            ->assertForbidden();

        $this->assertTrue($target->fresh()->hasDirectPermission($directAccessPermission));
        $this->actingAs($target->fresh())
            ->get(route('access-management.index'))
            ->assertOk();

        $roleManager = $this->createUserWithActiveRole('role-manager');
        $roleManager->givePermissionTo([
            'access-management.view',
            'access-management.manage-roles',
        ]);

        Livewire::actingAs($roleManager)
            ->test(Index::class)
            ->set('newRoleName', 'allowed-custom-role')
            ->call('createRole')
            ->assertHasNoErrors()
            ->set('rolePermissionIds', [$rolePermission->id])
            ->call('saveRolePermissions')
            ->assertForbidden();

        $managedRole = Role::findByName('allowed-custom-role', 'web');
        $this->assertFalse($managedRole->hasPermissionTo($rolePermission));

        $permissionManager = $this->createUserWithActiveRole('permission-manager');
        $permissionManager->givePermissionTo([
            'access-management.view',
            'access-management.manage-permissions',
            // statistics.view is granted to the manager as well, because a
            // delegated administrator may now only hand out permissions they
            // hold themselves. Without that rule, manage-permissions was a
            // route to assembling a role with every permission in the
            // catalogue and then assigning it — see PrivilegeEscalationTest.
            'statistics.view',
        ]);

        Livewire::actingAs($permissionManager)
            ->test(Index::class)
            ->call('selectRole', $managedRole->id)
            ->set('rolePermissionIds', [$rolePermission->id])
            ->call('saveRolePermissions')
            ->assertHasNoErrors()
            ->set('selectedRoleName', 'not-allowed-rename')
            ->call('renameRole')
            ->assertForbidden();

        $this->assertTrue($managedRole->fresh()->hasPermissionTo($rolePermission));

        $roleBeneficiary = $this->createUserWithActiveRole($managedRole->name);
        $this->assertTrue($roleBeneficiary->hasActivePermission($rolePermission->name));
    }

    public function test_an_inactive_administrator_assignment_does_not_grant_access(): void
    {
        $user = $this->createUserWithActiveRole('employee');
        $administrator = Role::findOrCreate('HR-SYR', 'web');
        $user->assignRole($administrator);
        $user->roles()->updateExistingPivot($administrator->id, ['is_active' => false]);
        $user->unsetRelation('roles');

        $this->actingAs($user)
            ->get(route('access-management.index'))
            ->assertForbidden();
    }

    public function test_administrator_can_update_employee_roles_active_role_and_direct_permissions(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $managerRole = Role::findOrCreate('manager', 'web');
        $directPermission = Permission::findOrCreate('statistics.view', 'web');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->set('employeeRoleIds', [$managerRole->id, Role::findByName('employee')->id])
            ->set('employeeActiveRoleId', $managerRole->id)
            ->set('employeePermissionIds', [$directPermission->id])
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors();

        $employee->refresh()->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertSame('manager', $employee->myRole());
        $this->assertTrue($employee->hasDirectPermission('statistics.view'));
        $this->assertCount(2, $employee->roles);
    }

    public function test_employee_access_save_is_enabled_only_when_settings_change_and_resets_after_saving(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $permission = Permission::findOrCreate('statistics.view', 'web');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->assertSeeHtml('data-access-dirty="false"')
            ->assertSeeHtml('data-testid="save-employee-access"')
            ->set('employeePermissionIds', [$permission->id])
            ->assertSeeHtml('data-access-dirty="true"')
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-access-dirty="false"')
            ->assertDispatched('toast', type: 'success', message: __('access.access-saved'));
    }

    public function test_replacing_an_employees_only_role_synchronizes_the_active_role_before_saving(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('manager');
        $customRole = Role::findOrCreate('test-role', 'web');
        $permission = Permission::findOrCreate('statistics.view', 'web');
        $customRole->givePermissionTo($permission);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->set('employeeRoleIds', [])
            ->assertSet('employeeActiveRoleId', null)
            // Checkbox values arrive from the browser as strings.
            ->set('employeeRoleIds', [(string) $customRole->id])
            ->assertSet('employeeActiveRoleId', $customRole->id)
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors();

        $employee->refresh()->unsetRelation('roles')->unsetRelation('permissions');
        $activePivot = $employee->roles()->whereKey($customRole->id)->firstOrFail()->pivot;

        $this->assertSame('test-role', $employee->myRole());
        $this->assertTrue((bool) $activePivot->is_active);
        $this->assertTrue($employee->hasActivePermission('statistics.view'));
        $this->assertSame([$customRole->id], $employee->roles->pluck('id')->all());
    }

    public function test_removing_every_assigned_role_clears_the_active_role_and_fails_validation(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->set('employeeRoleIds', [])
            ->assertSet('employeeActiveRoleId', null)
            ->call('saveEmployeeAccess')
            ->assertHasErrors(['employeeRoleIds', 'employeeActiveRoleId']);

        $employee->refresh()->unsetRelation('roles');
        $this->assertSame('employee', $employee->myRole());
    }

    public function test_adding_a_secondary_role_keeps_the_existing_active_role(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $employeeRole = Role::findByName('employee', 'web');
        $customRole = Role::findOrCreate('secondary-reviewer', 'web');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->set('employeeRoleIds', [(string) $employeeRole->id, (string) $customRole->id])
            ->assertSet('employeeActiveRoleId', $employeeRole->id)
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors();

        $employee->refresh()->unsetRelation('roles');
        $this->assertSame('employee', $employee->myRole());
        $this->assertEqualsCanonicalizing(
            [$employeeRole->id, $customRole->id],
            $employee->roles->pluck('id')->all(),
        );
    }

    public function test_selecting_an_employee_expands_the_directory_and_keeps_that_employee_visible(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $administrator->update(['email' => 'admin-directory@example.test']);
        $employees = collect(range(1, 30))->map(function (int $number): User {
            $user = $this->createUserWithActiveRole('employee');
            $user->update(['email' => sprintf('employee-%02d@example.test', $number)]);

            return $user->fresh();
        });
        $selectedEmployee = $employees->get(25);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->assertViewHas('employees', fn ($paginator): bool => $paginator->perPage() === 12)
            ->call('selectUser', $selectedEmployee->id)
            ->assertSet('selectedUserId', $selectedEmployee->id)
            ->assertViewHas('employees', fn ($paginator): bool => $paginator->perPage() === 24
                && $paginator->currentPage() === 2
                && $paginator->getCollection()->contains('id', $selectedEmployee->id))
            ->assertSeeHtml('xl:auto-rows-fr')
            ->assertSeeHtml('href="'.route('employees.show', [
                'employee' => $selectedEmployee->profile->username,
            ]).'"')
            ->assertSeeHtml('href="mailto:'.$selectedEmployee->email.'"');
    }

    public function test_search_is_preserved_and_filtered_results_remain_top_aligned_after_selecting_an_employee(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $matchingEmployee = $this->createUserWithActiveRole('employee');
        $matchingEmployee->update(['email' => 'focused.search@example.test']);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->set('search', 'focused.search')
            ->assertSee('focused.search@example.test')
            ->call('selectUser', $matchingEmployee->id)
            ->assertSet('search', 'focused.search')
            ->assertSet('selectedUserId', $matchingEmployee->id)
            ->assertDontSeeHtml('xl:auto-rows-fr')
            ->assertSee(__('access.clear-search'));
    }

    public function test_empty_employee_search_has_guidance_and_can_be_cleared(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $employee->update(['email' => 'visible.employee@example.test']);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->set('search', 'no-such-employee')
            ->assertSee(__('access.pagination.no-results'))
            ->assertSee(__('access.no-results-help'))
            ->assertSee(__('access.clear-search'))
            ->call('clearSearch')
            ->assertSet('search', '')
            ->assertSee('visible.employee@example.test');
    }

    public function test_direct_permission_replacement_preserves_permissions_outside_the_managed_catalog(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $managedOld = Permission::findOrCreate('employees.view', 'web');
        $managedNew = Permission::findOrCreate('statistics.view', 'web');
        $external = Permission::findOrCreate('legacy.external-access', 'web');
        $employee->givePermissionTo([$managedOld, $external]);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $employee->id)
            ->set('employeePermissionIds', [$managedNew->id])
            ->call('saveEmployeeAccess')
            ->assertHasNoErrors();

        $employee->refresh()->unsetRelation('permissions');
        $this->assertTrue($employee->hasDirectPermission($managedNew));
        $this->assertTrue($employee->hasDirectPermission($external));
        $this->assertFalse($employee->hasDirectPermission($managedOld));
    }

    public function test_administrator_cannot_remove_its_own_active_administrator_role(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employeeRole = Role::findOrCreate('employee', 'web');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectUser', $administrator->id)
            ->set('employeeRoleIds', [$employeeRole->id])
            ->set('employeeActiveRoleId', $employeeRole->id)
            ->call('saveEmployeeAccess')
            ->assertHasErrors(['employeeActiveRoleId']);

        $administrator->refresh()->unsetRelation('roles');
        $this->assertSame('HR-SYR', $administrator->myRole());
    }

    public function test_protected_administrator_roles_cannot_have_permissions_removed(): void
    {
        $administrator = $this->createUserWithActiveRole('super-admin');

        foreach (['HR-SYR', 'super-admin'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');

            Livewire::actingAs($administrator)
                ->test(Index::class)
                ->call('selectRole', $role->id)
                ->set('rolePermissionIds', [])
                ->call('saveRolePermissions')
                ->assertHasNoErrors();

            // Everything except what no role may hold, which is granted per user.
            $this->assertEqualsCanonicalizing(
                Permission::query()->whereNotIn('name', AccessPermissionCatalog::personLevelNames())->pluck('name')->all(),
                $role->fresh()->permissions()->pluck('name')->all(),
            );
        }
    }

    public function test_no_role_save_attaches_a_permission_reserved_for_individual_users(): void
    {
        $administrator = $this->createUserWithActiveRole('super-admin');
        $reserved = Permission::findOrCreate('recruitment-applicants.export', 'web');
        $custom = Role::findOrCreate('data-officer', 'web');

        foreach ([Role::findOrCreate('HR-SYR', 'web'), $custom] as $role) {
            Livewire::actingAs($administrator)
                ->test(Index::class)
                ->call('selectRole', $role->id)
                ->call('selectAllRolePermissions')
                ->assertNotSet('rolePermissionIds', fn (array $ids): bool => in_array($reserved->id, $ids, true))
                ->set('rolePermissionIds', [$reserved->id])
                ->call('saveRolePermissions')
                ->assertHasNoErrors();

            $this->assertFalse($role->fresh()->hasPermissionTo($reserved), "{$role->name} picked up a per-user permission.");
        }

        // Granting it to a person is what the per-user panel is for.
        $officer = $this->createUserWithActiveRole('employee');
        $officer->givePermissionTo($reserved);
        $this->assertTrue($officer->fresh()->hasExplicitPermission('recruitment-applicants.export'));
    }

    public function test_custom_role_permissions_can_be_replaced_without_removing_external_permissions(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $role = Role::findOrCreate('auditor', 'web');
        $oldManaged = Permission::findOrCreate('employees.view', 'web');
        $newManaged = Permission::findOrCreate('statistics.view', 'web');
        $external = Permission::findOrCreate('legacy.audit', 'web');
        $role->givePermissionTo([$oldManaged, $external]);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissionIds', [$newManaged->id])
            ->call('saveRolePermissions')
            ->assertHasNoErrors();

        $role->refresh();
        $this->assertTrue($role->hasPermissionTo($newManaged));
        $this->assertTrue($role->hasPermissionTo($external));
        $this->assertFalse($role->hasPermissionTo($oldManaged));
    }

    public function test_roles_directory_has_no_internal_scroll_and_assigned_users_open_in_a_detailed_modal(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $role = Role::findOrCreate('modal-reviewer', 'web');
        $firstUser = $this->createUserWithActiveRole('employee');
        $secondUser = $this->createUserWithActiveRole('manager');
        $firstUser->assignRole($role);
        $secondUser->assignRole($role);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('showTab', 'roles')
            ->call('selectRole', $role->id)
            ->assertSeeHtml('data-testid="access-role-directory"')
            ->assertDontSeeHtml('max-h-[min(62vh,44rem)] divide-y divide-gray-100 overflow-y-auto')
            ->assertSee(__('access.role-name'))
            ->call('showAssignedUsers')
            ->assertSet('showAssignedUsersModal', true)
            ->assertSeeHtml('class="fixed inset-0 z-50 overflow-y-auto"')
            ->assertSeeHtml('class="fixed inset-0 z-0 transform bg-neutral-500/75 transition-all"')
            ->assertSeeHtml('class="pointer-events-none relative z-10 flex min-h-full items-center justify-center p-4 sm:p-6"')
            ->assertViewHas('selectedRoleUsers', fn ($users): bool => $users->count() === 2
                && $users->contains('id', $firstUser->id)
                && $users->contains('id', $secondUser->id))
            ->assertSee($firstUser->profile->nameForLocale(app()->getLocale()))
            ->assertSee($secondUser->profile->nameForLocale(app()->getLocale()))
            ->assertSeeHtml('href="mailto:'.$firstUser->email.'"')
            ->assertSeeHtml('href="'.route('employees.show', [
                'employee' => $firstUser->profile->username,
            ]).'"');
    }

    public function test_assigned_users_modal_has_a_clear_empty_state(): void
    {
        $administrator = $this->createUserWithActiveRole('super-admin');
        $role = Role::findOrCreate('unassigned-reviewer', 'web');

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('showTab', 'roles')
            ->call('selectRole', $role->id)
            ->call('showAssignedUsers')
            ->assertSet('showAssignedUsersModal', true)
            ->assertViewHas('selectedRoleUsers', fn ($users): bool => $users->isEmpty())
            ->assertSee(__('access.no-assigned-users'))
            ->assertSee(__('access.no-assigned-users-help'));
    }

    public function test_custom_role_create_rename_and_delete_lifecycle(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');

        $component = Livewire::actingAs($administrator)
            ->test(Index::class)
            ->set('newRoleName', 'Regional Reviewer')
            ->call('createRole')
            ->assertHasNoErrors();

        $role = Role::query()->where('name', 'regional-reviewer')->firstOrFail();

        $component
            ->assertSet('selectedRoleId', $role->id)
            ->set('selectedRoleName', 'Senior Reviewer')
            ->call('renameRole')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'senior-reviewer',
            'guard_name' => 'web',
        ]);

        $component->call('deleteRole')->assertHasNoErrors();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_duplicate_role_names_system_role_mutations_and_assigned_role_deletion_are_rejected(): void
    {
        $administrator = $this->createUserWithActiveRole('HR-SYR');
        $employee = $this->createUserWithActiveRole('employee');
        $assignedRole = Role::findOrCreate('assigned-custom-role', 'web');
        $employee->assignRole($assignedRole);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->set('newRoleName', 'employee')
            ->call('createRole')
            ->assertHasErrors(['newRoleName']);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectRole', Role::findByName('employee', 'web')->id)
            ->set('selectedRoleName', 'renamed-system-role')
            ->call('renameRole')
            ->assertHasErrors(['selectedRoleName']);

        Livewire::actingAs($administrator)
            ->test(Index::class)
            ->call('selectRole', $assignedRole->id)
            ->call('deleteRole')
            ->assertHasErrors(['selectedRoleName']);

        $this->assertDatabaseHas('roles', ['id' => $assignedRole->id]);
    }

    public function test_inactive_role_permissions_do_not_leak_into_effective_access(): void
    {
        $user = $this->createUserWithActiveRole('employee');
        $manager = Role::findOrCreate('manager', 'web');
        $permission = Permission::findOrCreate('statistics.view', 'web');
        $manager->givePermissionTo($permission);
        $user->assignRole($manager);
        $user->roles()->updateExistingPivot($manager->id, ['is_active' => false]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertFalse($user->hasActivePermission('statistics.view'));

        $user->givePermissionTo($permission);
        $this->assertTrue($user->hasActivePermission('statistics.view'));
    }

    public function test_eager_loaded_roles_preserve_the_active_pivot_and_effective_access(): void
    {
        $user = $this->createUserWithActiveRole('employee');
        $employeeRole = Role::findByName('employee', 'web');
        $managerRole = Role::findOrCreate('manager', 'web');
        $permission = Permission::findOrCreate('statistics.view', 'web');
        $managerRole->givePermissionTo($permission);
        $user->assignRole($managerRole);
        $user->roles()->updateExistingPivot($managerRole->id, ['is_active' => false]);

        $user = User::query()->with('roles')->findOrFail($user->id);

        $this->assertSame($employeeRole->id, $user->activeRole()?->id);
        $this->assertFalse($user->hasActivePermission($permission->name));

        $user->setActiveRole($managerRole->id);
        $user = User::query()->with('roles')->findOrFail($user->id);

        $this->assertSame($managerRole->id, $user->activeRole()?->id);
        $this->assertTrue($user->hasActivePermission($permission->name));
        $this->assertSame(
            1,
            $user->roles->filter(fn (Role $role): bool => (bool) $role->pivot->is_active)->count(),
        );
    }

    public function test_direct_permissions_control_page_routes(): void
    {
        $user = $this->createUserWithActiveRole('employee');

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertForbidden();

        $user->givePermissionTo('employees.view');

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertOk();
    }

    public function test_livewire_mutation_middleware_enforces_active_permissions(): void
    {
        $user = $this->createUserWithActiveRole('employee');
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => 'employee.employee-table']]),
                'calls' => [['method' => 'delete']],
            ]],
        ]);
        $request->setUserResolver(fn (): User => $user);
        $middleware = app(ActiveLivewirePermissionMiddleware::class);

        try {
            $middleware->handle($request, fn (): Response => new Response('allowed'));
            $this->fail('The mutation should have been rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $user->givePermissionTo('employees.delete');

        $response = $middleware->handle($request, fn (): Response => new Response('allowed'));
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_every_configured_livewire_mutation_mapping_is_denied_then_allowed_by_its_permission(): void
    {
        $user = $this->createUserWithActiveRole('empty-access-role');
        $middleware = app(ActiveLivewirePermissionMiddleware::class);

        foreach (config('access-control.livewire') as $componentName => $methods) {
            foreach ($methods as $method => $permission) {
                $user->syncPermissions([]);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $request = Request::create('/livewire/update', 'POST', [
                    'components' => [[
                        'snapshot' => json_encode(['memo' => ['name' => $componentName]]),
                        'calls' => [['method' => $method]],
                    ]],
                ]);
                $request->setUserResolver(fn (): User => $user);

                try {
                    $middleware->handle($request, fn (): Response => new Response('allowed'));
                    $this->fail("{$componentName}::{$method} should require {$permission}.");
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }

                $user->givePermissionTo($permission);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $response = $middleware->handle($request, fn (): Response => new Response('allowed'));
                $this->assertSame('allowed', $response->getContent());
            }
        }
    }

    public function test_active_permission_middleware_denies_guests_and_users_without_the_permission(): void
    {
        $middleware = app(ActivePermissionMiddleware::class);
        $guestRequest = Request::create('/protected', 'GET');

        try {
            $middleware->handle($guestRequest, fn (): Response => new Response('allowed'), 'statistics.view');
            $this->fail('A guest should not pass active permission middleware.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $user = $this->createUserWithActiveRole('empty-route-role');
        $request = Request::create('/protected', 'GET');
        $request->setUserResolver(fn (): User => $user);

        try {
            $middleware->handle($request, fn (): Response => new Response('allowed'), 'statistics.view');
            $this->fail('A user without the permission should be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $user->givePermissionTo('statistics.view');
        $response = $middleware->handle($request, fn (): Response => new Response('allowed'), 'statistics.view');

        $this->assertSame('allowed', $response->getContent());
    }

    public function test_permission_catalog_is_unique_and_fully_seeded_for_the_web_guard(): void
    {
        $names = AccessPermissionCatalog::names();

        $this->assertNotEmpty($names);
        $this->assertSameSize(array_unique($names), $names);
        $this->assertSame(
            count($names),
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $names)
                ->count(),
        );
    }

    public function test_every_catalog_permission_applies_directly_and_only_from_the_active_role(): void
    {
        $names = AccessPermissionCatalog::names();
        $user = $this->createUserWithActiveRole('permission-matrix-role');
        $role = Role::findByName('permission-matrix-role', 'web');

        $role->syncPermissions($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        foreach ($names as $permission) {
            $this->assertTrue(
                $user->hasActivePermission($permission),
                "Active-role grant was not applied for {$permission}.",
            );
        }

        $user->roles()->updateExistingPivot($role->id, ['is_active' => false]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        foreach ($names as $permission) {
            $this->assertFalse(
                $user->hasActivePermission($permission),
                "Inactive-role grant leaked for {$permission}.",
            );
        }

        $role->syncPermissions([]);
        $user->syncPermissions($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        foreach ($names as $permission) {
            $this->assertTrue(
                $user->hasActivePermission($permission),
                "Direct user grant was not applied for {$permission}.",
            );
        }
    }

    public function test_every_configured_route_and_livewire_permission_exists_in_the_catalog(): void
    {
        $catalog = AccessPermissionCatalog::names();
        $routePermissions = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'active_permission:')
                || str_starts_with($middleware, ActivePermissionMiddleware::class.':'))
            ->map(fn (string $middleware): string => str($middleware)->afterLast(':')->toString())
            ->unique()
            ->values();
        $livewirePermissions = collect(config('access-control.livewire'))
            ->flatMap(fn (array $methods): array => array_values($methods))
            ->unique()
            ->values();

        foreach ($routePermissions->merge($livewirePermissions)->unique() as $permission) {
            $this->assertContains(
                $permission,
                $catalog,
                "Configured authorization references unknown permission {$permission}.",
            );
        }

        $accessRouteMiddleware = RouteFacade::getRoutes()
            ->getByName('access-management.index')
            ->gatherMiddleware();

        $this->assertContains(
            'active_permission:access-management.view',
            $accessRouteMiddleware,
        );
        $this->assertNotContains(
            'role:HR-SYR|super-admin',
            $accessRouteMiddleware,
        );
    }

    public function test_regional_hr_baseline_excludes_access_management_and_activity_log(): void
    {
        $role = Role::findOrCreate('HR-ALP', 'web');

        $this->assertFalse($role->hasPermissionTo('access-management.view'));
        $this->assertFalse($role->hasPermissionTo('access-management.manage-employees'));
        $this->assertFalse($role->hasPermissionTo('activity-log.view'));
        $this->assertTrue($role->hasPermissionTo('employees.view'));
        $this->assertTrue($role->hasPermissionTo('leaves.decide'));
    }

    public function test_livewire_middleware_allows_guest_authentication_components(): void
    {
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => 'auth.login']]),
                'calls' => [['method' => 'login']],
            ]],
        ]);

        $response = app(ActiveLivewirePermissionMiddleware::class)
            ->handle($request, fn (): Response => new Response('guest-allowed'));

        $this->assertSame('guest-allowed', $response->getContent());
    }

    public function test_shared_crud_modules_authorize_create_and_update_independently(): void
    {
        $user = $this->createUserWithActiveRole('employee');

        Livewire::actingAs($user)
            ->test(DepartmentModule::class)
            ->call('create')
            ->assertForbidden();

        $user->givePermissionTo('project-basics.create');

        Livewire::actingAs($user)
            ->test(DepartmentModule::class)
            ->call('create')
            ->set('item.name', 'Permission Test')
            ->set('item.code', 'PT')
            ->call('save')
            ->assertHasNoErrors();

        $department = Department::query()->where('code', 'PT')->firstOrFail();

        Livewire::actingAs($user)
            ->test(DepartmentModule::class)
            ->set('item', [
                'id' => $department->id,
                'name' => 'Unauthorized Update',
                'code' => 'PT',
                'description_ar' => null,
                'description_en' => null,
                'email' => null,
            ])
            ->call('save')
            ->assertForbidden();
    }

    private function createUserWithActiveRole(string $roleName): User
    {
        $city = City::query()->where('slug', 'TST')->first()
            ?? City::query()->forceCreate([
                'slug' => 'TST',
                'name_en' => 'Test',
                'name_ar' => 'اختبار',
                'code' => '999',
                'is_active' => true,
            ]);
        $employee = Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Access',
            'first_name_ar' => 'وصول',
            'last_name_en' => 'Tester',
            'last_name_ar' => 'اختبار',
            'username' => uniqid('access.', true),
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => uniqid('access-', true).'@example.test',
            'password' => 'password',
        ]);
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
        $user->roles()->updateExistingPivot($role->id, ['is_active' => true]);
        $user->unsetRelation('roles');

        return $user->fresh();
    }
}
