<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractEdit;
use App\Livewire\Contract\ContractRenewModal;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NewEmployeeWelcome;
use App\Services\ContractAutoRenewalService;
use App\Services\LeaveBalanceService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * T13: every path that starts a real contract runs EmployeeActivation, as
 * ContractCreate always has: a reservation upgraded on the edit screen, a
 * renewal saved from the renew dialog, and the nightly auto-renewal. Any other
 * edit leaves accounts alone (B5D-Q8). A hire through recruitment is left out
 * on purpose: HR makes that account by hand.
 */
class ContractActivationPathsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Role::findOrCreate('employee', 'web');
        $this->mock(LeaveBalanceService::class)->shouldReceive('create')->andReturnNull();
    }

    public function test_upgrading_a_reservation_on_the_edit_screen_activates_the_volunteer_and_creates_the_account(): void
    {
        $fixtures = $this->fixtures();
        $awaiting = $this->makeEmployee($fixtures['city'], 't13.reserved', ['is_active' => false]);
        $reservation = $this->reserve($awaiting, $fixtures);

        Livewire::actingAs($fixtures['hr'])->test(ContractEdit::class, ['contract' => $reservation->fresh()])
            ->call('submit', $this->payload($fixtures, $awaiting))
            ->assertHasNoErrors();

        $this->assertSame(ContractTypeEnum::FullTime, (int) $reservation->fresh()->type);
        $this->assertTrue(collect(session('toasts'))->contains(fn ($t) => $t['type'] === 'info' && $t['message'] === __('employees.onboarding.account-created')));

        $awaiting->refresh();
        $this->assertTrue($awaiting->is_active);
        $account = $this->accountOf($awaiting);
        $this->assertSame('t13.reserved@hcsyria.org', $account->email);
        $this->assertSame('employee', $account->myRole());
        Notification::assertSentTo($account, ResetPassword::class);
        // T13d: colleagues are welcomed as for a first contract made on the create screen.
        Notification::assertSentTo($fixtures['hr'], NewEmployeeWelcome::class);
    }

    public function test_editing_a_contract_that_was_already_real_creates_no_account(): void
    {
        $fixtures = $this->fixtures();
        $staff = $this->makeEmployee($fixtures['city'], 't13.staff');
        $contract = $this->realContract($staff, $fixtures);

        Livewire::actingAs($fixtures['hr'])->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->payload($fixtures, $staff, [
                'startDate' => $contract->start_date->toDateString(),
                'endDate' => $contract->end_date->toDateString(),
            ]))
            ->assertHasNoErrors();

        $this->assertFalse(User::query()->where('employee_id', $staff->id)->exists(), 'B5D-Q8: never for an edit.');
        Notification::assertNothingSent();
    }

    public function test_a_renewal_from_the_renew_dialog_gives_staff_without_a_login_their_account(): void
    {
        $fixtures = $this->fixtures();
        $staff = $this->makeEmployee($fixtures['city'], 't13.renewed');
        $source = $this->realContract($staff, $fixtures);

        Livewire::actingAs($fixtures['hr'])->test(ContractRenewModal::class)
            ->call('open', $source->id)
            ->call('renewAsIs')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, Contract::query()->where('employee_id', $staff->id)->count());
        $this->assertTrue(collect(session('toasts'))->contains(fn ($t) => $t['type'] === 'info' && $t['message'] === __('employees.onboarding.account-created')));
        Notification::assertSentTo($this->accountOf($staff), ResetPassword::class);
    }

    public function test_the_nightly_auto_renewal_gives_staff_without_a_login_their_account_once(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
        $fixtures = $this->fixtures();
        $staff = $this->makeEmployee($fixtures['city'], 't13.auto');
        $this->realContract($staff, $fixtures, [
            'end_date' => '2026-09-15',
            'is_active' => false,
            'auto_renew' => true,
        ]);

        $service = app(ContractAutoRenewalService::class);
        $this->assertCount(1, $service->process());
        // A second run renews nothing and makes nothing.
        $this->assertCount(0, $service->process());

        $account = $this->accountOf($staff);
        $this->assertSame('t13.auto@hcsyria.org', $account->email);
        Notification::assertSentToTimes($account, ResetPassword::class, 1);
    }

    public function test_an_auto_renewal_leaves_an_existing_account_alone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
        $fixtures = $this->fixtures();
        $staff = $this->makeEmployee($fixtures['city'], 't13.known');
        $existing = User::factory()->create(['employee_id' => $staff->id, 'email' => 'someone@example.test']);
        $this->realContract($staff, $fixtures, ['end_date' => '2026-09-15', 'is_active' => false, 'auto_renew' => true]);

        $this->assertCount(1, app(ContractAutoRenewalService::class)->process());

        $this->assertSame([$existing->id], User::query()->where('employee_id', $staff->id)->pluck('id')->all());
        Notification::assertNotSentTo($existing, ResetPassword::class);
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function accountOf(Employee $employee): User
    {
        return User::query()->where('employee_id', $employee->id)->sole();
    }

    private function fixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center', 'name_ar' => 'مركز الرجاء', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $position = Position::query()->forceCreate([
            'center_id' => $center->id, 'city_id' => $city->id, 'department_id' => $department->id,
            'job_level_id' => $jobLevel->id, 'program_id' => $program->id,
            'title_en' => 'Program Officer', 'title_ar' => 'مسؤول برنامج',
            'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create', 'contracts.update', 'contracts.view', 'employees.update']);

        return compact('city', 'center', 'program', 'position', 'annualType', 'hr');
    }

    private function reserve(Employee $employee, array $fixtures): Contract
    {
        return Contract::query()->forceCreate([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::Draft,
            'is_active' => true,
        ]);
    }

    private function realContract(Employee $employee, array $fixtures, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $fixtures['position']->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 1,
            'salary' => 100,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides));
    }

    private function payload(array $fixtures, Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'cityId' => $fixtures['city']->id,
            'employeeId' => $employee->id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $fixtures['position']->id,
            'contractTypeId' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'startDate' => now()->addMonth()->startOfMonth()->toDateString(),
            'endDate' => now()->endOfYear()->toDateString(),
            'scheduleMode' => 1,
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'weeklyHours' => 40,
            'payMode' => 1,
            'currency' => 1,
            'salary' => 100,
            'salaryPer' => 1,
            'payTiers' => [],
            'terminatedAt' => '',
            'terminationReasonId' => '',
            'reasonOfTermination' => '',
        ], $overrides);
    }
}
