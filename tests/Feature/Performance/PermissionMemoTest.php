<?php

namespace Tests\Feature\Performance;

use App\Livewire\Employee\EmployeeTable;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * X1-I track A: the per-request permission memo and the single table render.
 * The memo must cut queries without ever serving a stale answer.
 */
class PermissionMemoTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_repeated_permission_checks_hit_the_database_once(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.view']);

        $count = $this->queries(function () use ($user) {
            for ($i = 0; $i < 100; $i++) {
                $this->assertTrue($user->hasActivePermission('employees.view'));
                $this->assertFalse($user->hasActivePermission('employees.delete'));
                $user->activeRoleIs('HR');
                $user->myRole();
                $user->canViewSalary();
            }
        });

        $this->assertLessThanOrEqual(10, $count, "100 rounds cost {$count} queries");
    }

    public function test_switching_the_active_role_never_serves_a_stale_answer(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $other = Role::findOrCreate('staff', 'web');
        $other->givePermissionTo(Permission::findOrCreate('leaves.create', 'web'));
        $user->assignRole($other);
        $user->roles()->updateExistingPivot($other->id, ['is_active' => false]);

        // Warm the memo on this very instance.
        $this->assertTrue($user->hasActivePermission('employees.update'));
        $this->assertFalse($user->hasActivePermission('leaves.create'));
        $this->assertSame('HR-ALP', $user->myRole());
        $this->assertTrue($user->canViewSalary());

        // Exactly what RoleSwitcher::switchTo does.
        $ids = $user->roles()->pluck('roles.id')->all();
        $user->roles()->updateExistingPivot($ids, ['is_active' => false]);
        $user->roles()->updateExistingPivot($other->id, ['is_active' => true]);

        $this->assertSame('staff', $user->myRole());
        $this->assertFalse($user->hasActivePermission('employees.update'));
        $this->assertTrue($user->hasActivePermission('leaves.create'));
        $this->assertFalse($user->canViewSalary());
        $this->assertFalse($user->activeRoleIs('HR'));
    }

    public function test_city_scope_is_looked_up_once_and_follows_a_role_switch(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $user = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $other = Role::findOrCreate('HR-HMS', 'web');
        $user->assignRole($other);
        $user->roles()->updateExistingPivot($other->id, ['is_active' => false]);

        $count = $this->queries(function () use ($user, $aleppo, $homs) {
            for ($i = 0; $i < 50; $i++) {
                $this->assertTrue($user->canAccessCity($aleppo->id));
                $this->assertFalse($user->canAccessCity($homs->id));
            }
        });
        $this->assertLessThanOrEqual(5, $count, "100 city checks cost {$count} queries");

        // RoleSwitcher::switchTo: the new role's cities, never the memoized ones.
        $user->roles()->updateExistingPivot($user->roles()->pluck('roles.id')->all(), ['is_active' => false]);
        $user->roles()->updateExistingPivot($other->id, ['is_active' => true]);

        $this->assertFalse($user->canAccessCity($aleppo->id));
        $this->assertTrue($user->canAccessCity($homs->id));
    }

    public function test_deactivating_every_role_revokes_at_once(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $this->assertTrue($user->hasActivePermission('employees.update'));

        // AccessRevoker::deactivateRoles
        $user->roles()->updateExistingPivot($user->roles()->pluck('roles.id')->all(), ['is_active' => false]);

        $this->assertFalse($user->hasActivePermission('employees.update'));
    }

    public function test_changing_a_roles_permissions_or_direct_grants_is_seen_immediately(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $this->assertFalse($user->hasActivePermission('employees.delete'));

        Role::findByName('HR-ALP', 'web')->givePermissionTo(Permission::findOrCreate('employees.delete', 'web'));
        $this->assertTrue($user->hasActivePermission('employees.delete'));

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('employees.delete');
        $this->assertFalse($user->hasActivePermission('employees.delete'));

        $user->givePermissionTo(Permission::findOrCreate('contracts.create', 'web'));
        $this->assertTrue($user->hasActivePermission('contracts.create'));
        $user->revokePermissionTo('contracts.create');
        $this->assertFalse($user->hasActivePermission('contracts.create'));
    }

    public function test_salary_blind_rules_are_unchanged(): void
    {
        $city = $this->makeCity('SYR', '000');
        $hr = $this->makeUser($city, 'HR-SYR');
        $admin = $this->makeUser($city, 'super-admin');
        $staff = $this->makeUser($city, 'staff');

        $this->assertTrue($hr->canViewSalary());
        $this->assertFalse($admin->canViewSalary());
        $this->assertFalse($staff->canViewSalary());
        // Memoised second ask gives the same answer.
        $this->assertTrue($hr->canViewSalary());
        $this->assertFalse($admin->canViewSalary());
    }

    public function test_employee_table_query_ceiling_and_city_scope(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = City::query()->forceCreate(['name_en' => 'HMS', 'name_ar' => 'HMS', 'slug' => 'HMS', 'code' => '002', 'is_active' => true]);
        $user = $this->makeUser($aleppo, 'HR-ALP', ['employees.view', 'employees.update', 'employees.delete']);

        foreach (range(1, 15) as $i) {
            $this->makeEmployee($aleppo, "alp{$i}", ['first_name_en' => "Alep{$i}"]);
            $this->makeEmployee($homs, "hms{$i}", ['first_name_en' => "Homs{$i}"]);
        }

        $component = null;
        $count = $this->queries(function () use ($user, &$component) {
            $component = Livewire::actingAs($user)->test(EmployeeTable::class);
        });

        $component->assertSee('Alep1')->assertDontSee('Homs1');
        $this->assertLessThanOrEqual(45, $count, "employees table cost {$count} queries for 15 rows");
    }

    public function test_non_deferred_tables_render_once_without_wire_init(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.view']);
        $this->makeEmployee($city, 'alp1', ['first_name_en' => 'Alep1']);

        Livewire::actingAs($user)->test(EmployeeTable::class)
            ->assertDontSeeHtml('wire:init="init"')
            ->assertSee('Alep1');
    }

    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
