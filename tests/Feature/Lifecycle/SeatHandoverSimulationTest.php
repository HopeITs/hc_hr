<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Center;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\DefaultDeliverable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEntitlement;
use App\Models\Entitlement;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\PositionEntitlement;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RolesSuspendedForNoContract;
use App\Services\OnboardingDeliveries;
use App\Services\OnboardingJourneys;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * People leave their positions and others take the same positions on new
 * contracts. Through the nightly commands alone, with nobody doing anything
 * by hand, what belongs to each seat reaches the newcomer: the manager role
 * and the team, the position's defaults, the HR role of the last holder, the
 * leaver's open hand-overs and their place in the city's delivery list
 * (OrgRoleSync, Access\SeatSuccession). Synthetic data only.
 */
class SeatHandoverSimulationTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private Center $center;

    private JobLevel $level;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        foreach (['employee', 'manager', 'HR-ALP', 'HR-SYR', 'super-admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->travelTo(now()->setTime(10, 0));

        $this->city = $this->makeCity('ALP', '001');
        $this->center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز', 'city_id' => $this->city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $this->program = Program::query()->forceCreate(['name' => 'Core', 'slug' => 'core', 'code' => '010', 'is_active' => true]);
        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
    }

    public function test_everything_of_a_seat_reaches_whoever_takes_it_over(): void
    {
        $pmPos = $this->position('PRG', 'Program Manager');
        $officerPos = $this->position('PRG', 'Program Officer', $pmPos->id);
        $itPos = $this->position('IT', 'IT Specialist');
        $hrPos = $this->position('HR', 'HR Officer');

        $leavesOn = now()->addDays(10);
        [$oldPm, $oldPmUser] = $this->person('old.pm', $pmPos, now()->subYear(), $leavesOn, ['super-admin']);
        [$officer] = $this->person('officer', $officerPos, now()->subYear(), now()->addYears(2));
        [$oldIt] = $this->person('old.it', $itPos, now()->subYear(), $leavesOn);
        [$oldHr] = $this->person('old.hr', $hrPos, now()->subYear(), $leavesOn, ['HR-ALP']);
        [, $hrSyria] = $this->person('hr.syria', $this->position('HR', 'HR Manager'), now()->subYear(), now()->addYears(2), ['HR-SYR']);

        // Set once by HR, per person: the city's deliverers.
        $laptop = ChecklistItem::query()->create(['name' => 'Laptop X', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_ELECTRONICS]);
        $badge = ChecklistItem::query()->create(['name' => 'Badge X', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_CARD]);
        DefaultDeliverable::query()->create(['employee_id' => $oldIt->id, 'city_id' => $this->city->id, 'item_id' => $laptop->id]);
        DefaultDeliverable::query()->create(['employee_id' => $oldHr->id, 'city_id' => $this->city->id, 'item_id' => $badge->id]);

        // The PM seat comes with a work email (Permissions Library).
        $email = Entitlement::query()->create(['name_en' => 'Work email', 'name_ar' => 'إيميل', 'category' => 'accounts', 'is_active' => true]);
        PositionEntitlement::query()->create(['entitlement_id' => $email->id, 'position_id' => $pmPos->id]);

        // Work the leavers leave open: a laptop to hand over, a phone to take back.
        $openDelivery = Checklist::query()->create(['employee_id' => $officer->id, 'item_id' => $laptop->id, 'delivered_id' => $oldIt->id, 'delivered_status' => 'pending']);
        $openReturn = Checklist::query()->create(['employee_id' => $officer->id, 'item_id' => $badge->id, 'delivered_id' => $oldHr->id, 'delivered_status' => 'delivered', 'receiver_id' => $oldHr->id, 'returned_status' => 'pending']);

        $this->artisan('access:sync-org-roles')->assertSuccessful();
        $this->assertTrue($oldPmUser->fresh()->hasRole('manager'));

        // The leavers' contracts end; the newcomers start on the same seats the next day.
        $start = $leavesOn->copy()->addDay();
        [$newPm, $newPmUser] = $this->person('new.pm', $pmPos, $start, $start->copy()->addYear());
        [$newIt, $newItUser] = $this->person('new.it', $itPos, $start, $start->copy()->addYear());
        [$newHr, $newHrUser] = $this->person('new.hr', $hrPos, $start, $start->copy()->addYear());

        $this->nightly($start);
        $this->travelTo($start->copy()->addDays(config('journeys.exit_grace_days') + 1)->setTime(22, 0));
        $this->nightly(now());

        foreach ([$oldPm, $oldIt, $oldHr] as $leaver) {
            $this->assertFalse($leaver->fresh()->is_active, "{$leaver->username} is deactivated after the grace period.");
        }

        // The manager's seat: the role, the team, the seat's defaults. Never the administrator role.
        $this->assertTrue($newPmUser->fresh()->hasRole('manager'));
        $this->assertTrue(Contract::query()->visibleTo($newPmUser->fresh())->where('employee_id', $officer->id)->exists());
        $this->assertTrue(EmployeeEntitlement::query()->where('employee_id', $newPm->id)->where('entitlement_id', $email->id)->exists());
        $this->assertFalse($newPmUser->fresh()->hasRole('super-admin'), 'An administrator role is granted on purpose, never with a seat.');

        // The HR seat: the city HR role of the last holder, and HR Syria is told.
        $this->assertTrue($newHrUser->fresh()->hasRole('HR-ALP'));
        Notification::assertSentTo($hrSyria, RolesSuspendedForNoContract::class, fn (RolesSuspendedForNoContract $n) => $n->inherited === ['HR-ALP'] && $n->employee->is($newHr));

        // The IT seat: the hand-over page, the leaver's open hand-over.
        $this->assertTrue($newItUser->fresh()->canHandOutItems());
        $this->assertSame($newIt->id, (int) $openDelivery->fresh()->delivered_id);
        $this->assertSame('pending', $openDelivery->fresh()->delivered_status);
        $this->assertSame($newHr->id, (int) $openReturn->fresh()->receiver_id);

        // The delivery list now names the newcomers.
        $this->assertSame($newIt->id, (int) DefaultDeliverable::query()->where('item_id', $laptop->id)->value('employee_id'));
        $this->assertSame($newHr->id, (int) DefaultDeliverable::query()->where('item_id', $badge->id)->value('employee_id'));

        // A volunteer arriving now gets their items from the newcomers.
        $volunteer = $this->volunteer($officerPos);
        $deliveries = app(OnboardingDeliveries::class);
        $this->assertSame($newIt->id, $deliveries->group($volunteer, ChecklistItem::GROUP_ELECTRONICS)['items']->firstWhere('id', $laptop->id)['deliverer']?->id);
        $this->assertSame($newHr->id, $deliveries->group($volunteer, ChecklistItem::GROUP_CARD)['items']->firstWhere('id', $badge->id)['deliverer']?->id);
    }

    public function test_a_seat_role_taken_away_by_hand_is_not_given_back(): void
    {
        $hrPos = $this->position('HR', 'HR Officer');
        $this->person('old.hr', $hrPos, now()->subYear(), now()->subDays(20), ['HR-ALP'], active: false);
        [, $newHrUser] = $this->person('new.hr', $hrPos, now()->subDays(5), now()->addYear());

        $this->artisan('access:sync-org-roles')->assertSuccessful();
        $this->assertTrue($newHrUser->fresh()->hasRole('HR-ALP'));

        $newHrUser->fresh()->removeRole('HR-ALP');
        $this->artisan('access:sync-org-roles')->assertSuccessful();
        $this->assertFalse($newHrUser->fresh()->hasRole('HR-ALP'), 'HR took it away on purpose: the seat gives it once per contract.');
    }

    public function test_before_the_nightly_run_the_reader_already_uses_the_successor(): void
    {
        $itPos = $this->position('IT', 'IT Specialist');
        $badge = ChecklistItem::query()->create(['name' => 'Badge X', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_CARD]);
        [$oldIt] = $this->person('old.it', $itPos, now()->subYear(), now()->subDays(20), active: false);
        [$newIt] = $this->person('new.it', $itPos, now()->subDays(5), now()->addYear());
        DefaultDeliverable::query()->create(['employee_id' => $oldIt->id, 'city_id' => $this->city->id, 'item_id' => $badge->id]);

        // No nightly run yet: the list still names the leaver, the reader does not.
        $volunteer = $this->volunteer($this->position('PRG', 'Program Officer'));
        $this->assertSame($newIt->id, app(OnboardingDeliveries::class)->group($volunteer, ChecklistItem::GROUP_CARD)['items']->first()['deliverer']?->id);
    }

    public function test_a_seat_with_several_holders_hands_nothing_over(): void
    {
        $itPos = $this->position('IT', 'IT Specialist', null, 3);
        $laptop = ChecklistItem::query()->create(['name' => 'Laptop X', 'order' => 1, 'onboarding_group' => ChecklistItem::GROUP_ELECTRONICS]);
        [$oldIt] = $this->person('old.it', $itPos, now()->subYear(), now()->subDays(20), active: false);
        $this->person('it.a', $itPos, now()->subYear(), now()->addYear());
        $this->person('it.b', $itPos, now()->subYear(), now()->addYear());
        [$officer] = $this->person('officer', $this->position('PRG', 'Program Officer'), now()->subYear(), now()->addYear());
        $open = Checklist::query()->create(['employee_id' => $officer->id, 'item_id' => $laptop->id, 'delivered_id' => $oldIt->id, 'delivered_status' => 'pending']);

        $this->artisan('access:sync-org-roles')->assertSuccessful();

        $this->assertSame($oldIt->id, (int) $open->fresh()->delivered_id, 'Two people hold the seat: which one is HR\'s call.');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function nightly(CarbonInterface $day): void
    {
        $this->travelTo($day->copy()->setTime(22, 0));
        $this->artisan('deactivate:contract')->assertSuccessful();
        $this->artisan('employees:deactivate-departed')->assertSuccessful();
        $this->artisan('access:sync-org-roles')->assertSuccessful();
    }

    private function position(string $departmentCode, string $title, ?int $managerPositionId = null, int $seats = 1): Position
    {
        $department = Department::query()->firstOrCreate(['code' => $departmentCode], ['name' => $departmentCode]);

        return Position::query()->forceCreate([
            'center_id' => $this->center->id, 'city_id' => $this->city->id, 'department_id' => $department->id,
            'job_level_id' => $this->level->id, 'program_id' => $this->program->id, 'direct_manager_id' => $managerPositionId,
            'title_en' => $title, 'title_ar' => $title, 'max_emp' => $seats, 'exist_emp' => 0, 'is_active' => true,
        ]);
    }

    /** @return array{0: Employee, 1: User, 2: Contract} */
    private function person(string $username, Position $position, CarbonInterface $start, CarbonInterface $end, array $roles = [], bool $active = true): array
    {
        $employee = $this->makeEmployee($this->city, $username);
        $employee->forceFill(['record_status' => Employee::RECORD_COMPLETE, 'is_active' => $active])->saveQuietly();
        $contract = Contract::query()->forceCreate([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => 1,
            'type' => ContractTypeEnum::FullTime, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'is_active' => $end->isFuture(), 'auto_renew' => false,
        ]);
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole('employee');
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return [$employee->fresh(), $user->fresh(), $contract];
    }

    private function volunteer(Position $position): Employee
    {
        $volunteer = $this->makeEmployee($this->city, 'volunteer.'.uniqid());
        $contract = Contract::query()->forceCreate([
            'employee_id' => $volunteer->id, 'position_id' => $position->id, 'contract_type_id' => 1,
            'type' => ContractTypeEnum::FullTime, 'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(6)->toDateString(), 'is_active' => true,
        ]);
        app(OnboardingJourneys::class)->startForContract($volunteer, $contract);

        return $volunteer->fresh();
    }
}
