<?php

namespace Tests\Feature;

use App\Enums\BadgeSubtype;
use App\Enums\BadgeType;
use App\Livewire\Badges\BadgesModule;
use App\Livewire\Badges\BadgesTable;
use App\Livewire\Employee\EmployeeEdit;
use App\Models\Badge;
use App\Models\City;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class BadgeAuthorizationTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_badges_route_rejects_every_non_hr_active_role(): void
    {
        $city = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('employee', $city);

        $this->actingAs($user)
            ->get(route('badges.index'))
            ->assertForbidden();
    }

    public function test_direct_badges_view_permission_is_honored_for_a_non_hr_user(): void
    {
        $city = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('employee', $city);
        $user->givePermissionTo('badges.view');

        $this->actingAs($user)
            ->get(route('badges.index'))
            ->assertOk();

        Livewire::actingAs($user)
            ->test(BadgesTable::class)
            ->assertOk();
    }

    public function test_inactive_hr_assignment_does_not_grant_badges_access(): void
    {
        $city = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('employee', $city);
        $inactiveHr = Role::findOrCreate('HR-ALP', 'web');

        $user->assignRole($inactiveHr);
        $user->roles()->updateExistingPivot($inactiveHr->id, ['is_active' => false]);
        $user->unsetRelation('roles');

        $this->assertFalse($user->activeRoleIs('HR'));

        $this->actingAs($user)
            ->get(route('badges.index'))
            ->assertForbidden();
    }

    public function test_city_hr_can_open_the_badges_page_and_only_query_its_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $aleppoBadge = $this->createBadge($this->createEmployee($aleppo, 'aleppo.employee'));
        $homsBadge = $this->createBadge($this->createEmployee($homs, 'homs.employee'));

        $this->actingAs($user)
            ->get(route('badges.index'))
            ->assertOk();

        $visibleIds = Badge::query()
            ->visibleTo($user)
            ->pluck('id');

        $this->assertTrue($visibleIds->contains($aleppoBadge->id));
        $this->assertFalse($visibleIds->contains($homsBadge->id));
    }

    public function test_city_hr_cannot_open_details_for_a_badge_in_another_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $foreignBadge = $this->createBadge(
            $this->createEmployee($homs, 'foreign.details'),
            'foreign salary',
        );

        Livewire::actingAs($user)
            ->test(BadgesModule::class)
            ->call('openBadgeModal', $foreignBadge->id)
            ->assertNotFound();
    }

    public function test_city_hr_cannot_directly_delete_a_badge_in_another_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $foreignBadge = $this->createBadge(
            $this->createEmployee($homs, 'foreign.delete'),
        );

        Livewire::actingAs($user)
            ->test(BadgesTable::class)
            ->call('delete', ['id' => $foreignBadge->id])
            ->assertNotFound();

        $this->assertDatabaseHas('badges', ['id' => $foreignBadge->id]);
    }

    public function test_city_hr_can_delete_a_badge_in_its_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $badge = $this->createBadge($this->createEmployee($aleppo, 'local.delete'));

        Livewire::actingAs($user)
            ->test(BadgesTable::class)
            ->call('delete', ['id' => $badge->id])
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('badges', ['id' => $badge->id]);
    }

    public function test_general_manager_sees_badges_in_every_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('general-manager', $aleppo);
        $aleppoBadge = $this->createBadge($this->createEmployee($aleppo, 'gm.aleppo'));
        $homsBadge = $this->createBadge($this->createEmployee($homs, 'gm.homs'));

        $this->assertTrue($user->hasActivePermission('badges.view'));

        $visibleIds = Badge::query()
            ->visibleTo($user)
            ->pluck('id');

        $this->assertTrue($visibleIds->contains($aleppoBadge->id));
        $this->assertTrue($visibleIds->contains($homsBadge->id));

        Livewire::actingAs($user)
            ->test(BadgesTable::class)
            ->assertOk();
    }

    public function test_general_manager_opens_badge_details_without_pay_figures(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('general-manager', $aleppo);
        $badge = $this->createBadge($this->createEmployee($homs, 'gm.details'));

        Livewire::actingAs($user)
            ->test(BadgesModule::class)
            ->call('openBadgeModal', $badge->id)
            ->assertSet('badge.id', $badge->id)
            ->assertSet('badge.current_salary', null)
            ->assertSet('badge.new_salary', null);
    }

    public function test_general_manager_cannot_delete_badges(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('general-manager', $aleppo);
        $badge = $this->createBadge($this->createEmployee($aleppo, 'gm.delete'));

        Livewire::actingAs($user)
            ->test(BadgesTable::class)
            ->call('delete', ['id' => $badge->id])
            ->assertForbidden();

        $this->assertDatabaseHas('badges', ['id' => $badge->id]);
    }

    public function test_manager_cannot_view_badges(): void
    {
        $city = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('manager', $city);
        $this->createBadge($this->createEmployee($city, 'manager.badge'));

        $this->assertFalse($user->hasActivePermission('badges.view'));
        $this->assertSame(0, Badge::query()->visibleTo($user)->count());
    }

    public function test_manager_granted_badges_view_reaches_only_their_reports(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $this->grantToRole('manager', 'badges.view');
        $user = $this->createUserWithActiveRole('manager', $aleppo);

        // The manager's own position, held through a contract running today.
        $managerPosition = $this->makePosition($aleppo);
        $this->makeContractFor(Employee::query()->findOrFail($user->employee_id), [
            'position_id' => $managerPosition->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $directReport = $this->createEmployee($aleppo, 'manager.direct');
        $this->makeContractFor($directReport, [
            'position_id' => $this->makePosition($aleppo, ['direct_manager_id' => $managerPosition->id])->id,
        ]);
        $techReport = $this->createEmployee($homs, 'manager.tech');
        $this->makeContractFor($techReport, [
            'position_id' => $this->makePosition($homs, ['tech_manager_id' => $managerPosition->id])->id,
        ]);

        $directBadge = $this->createBadge($directReport);
        $techBadge = $this->createBadge($techReport);
        $sameCityStranger = $this->createBadge($this->createEmployee($aleppo, 'manager.stranger'));
        $otherCityStranger = $this->createBadge($this->createEmployee($homs, 'manager.foreign'));

        $this->assertEqualsCanonicalizing(
            [$directBadge->id, $techBadge->id],
            Badge::query()->visibleTo($user)->pluck('id')->all(),
        );

        Livewire::actingAs($user)
            ->test(BadgesModule::class)
            ->call('openBadgeModal', $sameCityStranger->id)
            ->assertNotFound();

        Livewire::actingAs($user)
            ->test(BadgesModule::class)
            ->call('openBadgeModal', $otherCityStranger->id)
            ->assertNotFound();
    }

    public function test_employee_granted_badges_view_reaches_no_badges(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $this->grantToRole('employee', 'badges.view');
        $user = $this->createUserWithActiveRole('employee', $aleppo);
        $badge = $this->createBadge($this->createEmployee($aleppo, 'employee.colleague'));

        $this->assertSame(0, Badge::query()->visibleTo($user)->count());

        Livewire::actingAs($user)
            ->test(BadgesModule::class)
            ->call('openBadgeModal', $badge->id)
            ->assertNotFound();
    }

    public function test_employee_form_cannot_delete_a_badge_belonging_to_another_employee(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $localEmployee = $this->createEmployee($aleppo, 'local.form');
        $localBadge = $this->createBadge($localEmployee);
        $otherBadge = $this->createBadge(
            $this->createEmployee($aleppo, 'other.form'),
        );

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $localEmployee])
            ->set('badgeForm.badges.0.id', $otherBadge->id)
            ->call('removeBadge', 0)
            ->assertNotFound();

        $this->assertDatabaseHas('badges', ['id' => $localBadge->id]);
        $this->assertDatabaseHas('badges', ['id' => $otherBadge->id]);
    }

    public function test_employee_form_rejects_badge_actions_for_another_city(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $homs = $this->createCity('HMS', '002');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $foreignEmployee = $this->createEmployee($homs, 'foreign.form');

        $this->actingAs($user)
            ->get(route('employees.edit', $foreignEmployee))
            ->assertForbidden();
    }

    public function test_employee_form_validates_badge_type_before_creating_it(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $employee = $this->createEmployee($aleppo, 'validation.form');

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('addBadge')
            ->set('badgeForm.badges.0.type', 'malicious-type')
            ->call('saveBadges')
            ->assertHasErrors(['badgeForm.badges.0.type']);

        $this->assertDatabaseCount('badges', 0);
    }

    public function test_employee_form_requires_subtype_for_warning_and_reward_badges(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $employee = $this->createEmployee($aleppo, 'subtype.required');

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('addBadge')
            ->set('badgeForm.badges.0.type', BadgeType::Warning)
            ->call('saveBadges')
            ->assertHasErrors(['badgeForm.badges.0.subtype']);

        $this->assertDatabaseCount('badges', 0);
    }

    public function test_employee_form_saves_financial_reward_badge_details(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $employee = $this->createEmployee($aleppo, 'reward.amount');

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->call('addBadge')
            ->set('badgeForm.badges.0.type', BadgeType::Reward)
            ->set('badgeForm.badges.0.subtype', BadgeSubtype::RewardFinancial)
            ->set('badgeForm.badges.0.amount', 250)
            ->set('badgeForm.badges.0.currency', 2)
            ->call('saveBadges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('badges', [
            'employee_id' => $employee->id,
            'type' => BadgeType::Reward,
            'subtype' => BadgeSubtype::RewardFinancial,
            'amount' => 250,
            'currency' => 2,
        ]);
    }

    public function test_employee_form_updates_existing_badge_details(): void
    {
        $aleppo = $this->createCity('ALP', '001');
        $user = $this->createUserWithActiveRole('HR-ALP', $aleppo);
        $employee = $this->createEmployee($aleppo, 'reward.edit');
        $badge = $this->createBadge($employee);

        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('badgeForm.badges.0.type', BadgeType::Reward)
            ->set('badgeForm.badges.0.subtype', BadgeSubtype::RewardPromotion)
            ->set('badgeForm.badges.0.new_position', 'Program Coordinator')
            ->call('saveBadges')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('badges', 1);
        $this->assertDatabaseHas('badges', [
            'id' => $badge->id,
            'employee_id' => $employee->id,
            'type' => BadgeType::Reward,
            'subtype' => BadgeSubtype::RewardPromotion,
            'new_position' => 'Program Coordinator',
            'amount' => null,
            'currency' => null,
            'current_salary' => null,
            'new_salary' => null,
        ]);
    }

    public function test_other_users_cannot_see_badge_reasons_on_employee_profiles(): void
    {
        $city = $this->createCity('ALP', '001');
        $viewer = $this->createUserWithActiveRole('employee', $city);
        $target = $this->createEmployee($city, 'private.badges');
        $this->createBadge($target, 'CONFIDENTIAL-BADGE-REASON');

        $this->actingAs($viewer)
            ->get(route('employees.show', $target))
            ->assertOk()
            ->assertDontSee('CONFIDENTIAL-BADGE-REASON');
    }

    private function createCity(string $slug, string $code): City
    {
        return City::query()->forceCreate([
            'name_en' => $slug,
            'name_ar' => $slug,
            'slug' => $slug,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function createEmployee(City $city, string $username): Employee
    {
        return Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Test',
            'first_name_ar' => 'اختبار',
            'last_name_en' => 'Employee',
            'last_name_ar' => 'موظف',
            'username' => $username,
            'is_active' => true,
        ]);
    }

    private function createUserWithActiveRole(string $roleName, City $city): User
    {
        $employee = $this->createEmployee(
            $city,
            str_replace(['-', '_'], '.', strtolower($roleName)).'.'.uniqid(),
        );
        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => uniqid('badge-', true).'@example.test',
            'password' => 'password',
        ]);
        $role = Role::findOrCreate($roleName, 'web');

        $user->assignRole($role);
        $user->roles()->updateExistingPivot($role->id, ['is_active' => true]);
        $user->unsetRelation('roles');

        return $user->fresh();
    }

    /** What ticking the permission for a role in Access Management does. */
    private function grantToRole(string $roleName, string $permission): void
    {
        Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createBadge(
        Employee $employee,
        string $reason = 'Private badge reason',
    ): Badge {
        return $employee->badges()->create([
            'type' => BadgeType::Salary_Increase,
            'currency' => 2,
            'date' => now(),
            'reason' => $reason,
            'current_salary' => 1000,
            'new_salary' => 1200,
            'notes' => 'Private HR notes',
        ]);
    }
}
