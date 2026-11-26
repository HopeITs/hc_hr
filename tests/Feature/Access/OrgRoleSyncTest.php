<?php

namespace Tests\Feature\Access;

use App\Livewire\Auth\Login;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleSuspension;
use App\Notifications\RolesSuspendedForNoContract;
use App\Services\Access\OrgRoleSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Roles that follow the org chart and the contracts (decided 2026-11-24):
 * every employee holds `employee`, `manager` is held by whoever leads a
 * staffed position, raised roles are suspended without a running contract,
 * and a sign-in starts on the narrowest working role.
 */
class OrgRoleSyncTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('SYR', '000');

        foreach (config('access-control.system_roles') as $name) {
            Role::findOrCreate($name, 'web');
        }
    }

    public function test_a_team_lead_gets_the_manager_role_and_the_employee_role(): void
    {
        [$lead, $leadPosition] = $this->personOn(null, 'lead');
        $this->personOn($leadPosition, 'report');
        $user = $this->userFor($lead);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertEqualsCanonicalizing(['employee', 'manager'], $user->fresh()->getRoleNames()->all());
    }

    public function test_a_vacant_report_does_not_make_a_manager(): void
    {
        [$lead, $leadPosition] = $this->personOn(null, 'lead');
        $this->makePosition($this->city, ['direct_manager_id' => $leadPosition->id]);
        $user = $this->userFor($lead, ['manager']);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertSame(['employee'], $user->fresh()->getRoleNames()->all());
    }

    public function test_a_general_manager_is_not_also_given_the_manager_role(): void
    {
        [$ceo, $ceoPosition] = $this->personOn(null, 'ceo');
        $this->personOn($ceoPosition, 'deputy');
        $user = $this->userFor($ceo, ['general-manager']);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertEqualsCanonicalizing(['employee', 'general-manager'], $user->fresh()->getRoleNames()->all());
    }

    public function test_raised_roles_are_suspended_without_a_running_contract_and_hr_syria_is_told(): void
    {
        Notification::fake();
        $hrSyria = $this->userFor($this->makeEmployee($this->city, 'hr-syr'), ['HR-SYR']);
        [, $hrPosition] = $this->personOn(null, 'hr-syr-seat');
        $this->seatContract($hrSyria->profile, $hrPosition);

        $leaver = $this->makeEmployee($this->city, 'leaver');
        $this->seatContract($leaver, $this->makePosition($this->city), ended: true);
        $user = $this->userFor($leaver, ['HR-ALP', 'employee']);

        app(OrgRoleSync::class)->syncUser($user);

        $user = $user->fresh();
        $this->assertSame(['employee'], $user->getRoleNames()->all());
        $this->assertSame('employee', $user->myRole());
        $this->assertDatabaseHas('user_role_suspensions', ['user_id' => $user->id, 'role_id' => Role::findByName('HR-ALP')->id]);
        Notification::assertSentTo($hrSyria, RolesSuspendedForNoContract::class, fn ($n) => $n->suspended === ['HR-ALP']);
    }

    public function test_a_suspended_role_comes_back_when_a_contract_runs_again(): void
    {
        $person = $this->makeEmployee($this->city, 'returning');
        $user = $this->userFor($person, ['super-admin', 'employee']);

        app(OrgRoleSync::class)->syncUser($user);
        $this->assertFalse($user->fresh()->hasRole('super-admin'));

        $this->seatContract($person, $this->makePosition($this->city));
        app(OrgRoleSync::class)->syncUser($user->fresh());

        $this->assertTrue($user->fresh()->hasRole('super-admin'));
        $this->assertSame(0, UserRoleSuspension::query()->count());
    }

    public function test_an_inactive_employee_is_left_alone(): void
    {
        $person = $this->makeEmployee($this->city, 'gone', ['is_active' => false]);
        $user = $this->userFor($person, ['HR-ALP']);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertSame(['HR-ALP'], $user->fresh()->getRoleNames()->all());
    }

    public function test_sign_in_starts_on_the_city_hr_role_rather_than_hr_syria(): void
    {
        $person = $this->makeEmployee($this->city, 'lana');
        $this->seatContract($person, $this->makePosition($this->city));
        $user = $this->userFor($person, ['HR-SYR', 'HR-ALP', 'employee']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertSame('HR-ALP', $user->fresh()->myRole());
    }

    public function test_sign_in_starts_an_it_administrator_as_an_employee(): void
    {
        $person = $this->makeEmployee($this->city, 'it');
        $this->seatContract($person, $this->makePosition($this->city));
        $user = $this->userFor($person, ['super-admin', 'employee']);

        $this->assertSame(Role::findByName('employee')->id, $user->defaultLoginRoleId());
    }

    public function test_the_nightly_command_runs_over_everyone(): void
    {
        $person = $this->makeEmployee($this->city, 'no-role');
        $this->seatContract($person, $this->makePosition($this->city));
        $user = $this->userFor($person);

        $this->artisan('access:sync-org-roles')->assertSuccessful();

        $this->assertSame(['employee'], $user->fresh()->getRoleNames()->all());
    }

    public function test_a_user_given_the_employee_role_by_the_sync_has_it_active(): void
    {
        $person = $this->makeEmployee($this->city, 't9.no-role');
        $this->seatContract($person, $this->makePosition($this->city));
        $user = $this->userFor($person);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertSame('employee', $user->fresh()->myRole());
        $this->assertTrue($user->roles()->wherePivot('is_active', true)->exists());
    }

    public function test_a_user_whose_roles_are_all_inactive_gets_one_active_without_any_change(): void
    {
        $person = $this->makeEmployee($this->city, 't9.inactive-roles');
        $this->seatContract($person, $this->makePosition($this->city));
        $user = User::factory()->create(['employee_id' => $person->id]);
        $user->assignRole('employee');

        $changes = app(OrgRoleSync::class)->syncUser($user);

        $this->assertSame([], array_filter($changes));
        $this->assertTrue($user->roles()->wherePivot('is_active', true)->where('name', 'employee')->exists());
    }

    public function test_an_active_role_is_left_as_it_is(): void
    {
        [$hr] = $this->personOn(null, 't9.hr');
        $user = $this->userFor($hr, ['HR-SYR', 'employee']);

        app(OrgRoleSync::class)->syncUser($user);

        $this->assertSame(['HR-SYR'], $user->roles()->wherePivot('is_active', true)->pluck('name')->all());
    }

    /** @return array{0: Employee, 1: Position} */
    private function personOn(?Position $manager, string $username): array
    {
        $position = $this->makePosition($this->city, ['direct_manager_id' => $manager?->id]);
        $employee = $this->makeEmployee($this->city, $username);
        $this->seatContract($employee, $position);

        return [$employee, $position];
    }

    private function seatContract(Employee $employee, Position $position, bool $ended = false): Contract
    {
        return Contract::query()->create([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(['name_en' => 'Volunteer Contract'], ['name_ar' => 'عقد تطوّع'])->id,
            'start_date' => now()->subMonths($ended ? 6 : 1)->toDateString(),
            'end_date' => $ended ? now()->subMonth()->toDateString() : now()->addMonth()->toDateString(),
            'currency' => 2,
            'salary_per' => 1,
            'salary' => 1500,
            'weekly_hours' => 40,
            'is_active' => ! $ended,
        ]);
    }

    /** @param list<string> $roles */
    private function userFor(Employee $employee, array $roles = []): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id]);

        if ($roles !== []) {
            $user->assignRole($roles);
            $user->roles()->updateExistingPivot(Role::findByName($roles[0])->id, ['is_active' => true]);
        }

        return $user->fresh();
    }
}
