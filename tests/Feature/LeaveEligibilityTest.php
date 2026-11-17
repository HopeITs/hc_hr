<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\MaritalStatus;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Leave-type eligibility (LeaveEligibility) on the server: the option lists
 * leave out what may not be requested, and every save path refuses it.
 *
 * Annual remaining is eligible-to-date − used. With the fixture's contract
 * (started a month ago, ~13 months long) and 120 h deserved, eligible to date
 * is the first third: 40 h.
 */
class LeaveEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ANNUAL_ELIGIBLE = 40;

    private int $cityId;

    private int $positionId;

    private int $contractTypeId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 October 2026; 2026-11-23 below is a working Monday.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->createSharedRows();
    }

    public function test_unpaid_is_refused_while_half_a_day_of_annual_remains(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE - 4);

        $this->assertRefused($employee, $balances[LeaveTypeCases::Unpaid], LeaveEligibility::REASON_ANNUAL_REMAINS);
    }

    public function test_unpaid_is_refused_while_one_hour_of_annual_remains(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE - 1);

        $this->assertRefused($employee, $balances[LeaveTypeCases::Unpaid], LeaveEligibility::REASON_ANNUAL_REMAINS);
    }

    public function test_unpaid_is_allowed_once_annual_is_used_up(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE);

        $this->assertAllowed($employee, $balances[LeaveTypeCases::Unpaid]);
        // An exhausted annual balance is itself no longer requestable.
        $this->assertRefused($employee, $balances[LeaveTypeCases::Annual], LeaveEligibility::REASON_NO_BALANCE);
    }

    public function test_unpaid_is_allowed_when_annual_is_overdrawn(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE + 5);

        $this->assertAllowed($employee, $balances[LeaveTypeCases::Unpaid]);
    }

    public function test_a_pending_annual_request_does_not_make_unpaid_available(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: 0);

        // The whole eligible annual balance is reserved by a pending request.
        $this->request($balances[LeaveTypeCases::Annual], LeaveRequestStatus::Pending, hours: self::ANNUAL_ELIGIBLE);

        $this->assertRefused($employee, $balances[LeaveTypeCases::Unpaid], LeaveEligibility::REASON_ANNUAL_REMAINS);
    }

    public function test_half_sick_is_refused_while_full_sick_remains_and_allowed_once_it_is_used_up(): void
    {
        [$employee, $balances] = $this->staff(fullSickUsed: 80);

        $this->assertRefused($employee, $balances[LeaveTypeCases::HalfSick], LeaveEligibility::REASON_FULL_SICK_REMAINS);

        $balances[LeaveTypeCases::FullSick]->update(['used' => 96]);

        $this->assertAllowed($employee, $balances[LeaveTypeCases::HalfSick]);
    }

    public function test_marriage_is_refused_to_a_married_employee_and_allowed_to_a_single_one(): void
    {
        [$married, $marriedBalances] = $this->staff(marital: MaritalStatus::Married, extra: [LeaveTypeCases::Marriage]);
        [$single, $singleBalances] = $this->staff(marital: MaritalStatus::Single, extra: [LeaveTypeCases::Marriage]);

        $this->assertRefused($married, $marriedBalances[LeaveTypeCases::Marriage], LeaveEligibility::REASON_MARRIED);
        $this->assertAllowed($single, $singleBalances[LeaveTypeCases::Marriage]);
    }

    public function test_paternity_is_only_for_a_married_man(): void
    {
        [$woman, $womanBalances] = $this->staff(gender: Gender::Female, marital: MaritalStatus::Married, extra: [LeaveTypeCases::Paternity]);
        [$single, $singleBalances] = $this->staff(gender: Gender::Male, marital: MaritalStatus::Single, extra: [LeaveTypeCases::Paternity]);
        [$husband, $husbandBalances] = $this->staff(gender: Gender::Male, marital: MaritalStatus::Married, extra: [LeaveTypeCases::Paternity]);

        $this->assertRefused($woman, $womanBalances[LeaveTypeCases::Paternity], LeaveEligibility::REASON_NOT_MARRIED_MALE);
        $this->assertRefused($single, $singleBalances[LeaveTypeCases::Paternity], LeaveEligibility::REASON_NOT_MARRIED_MALE);
        $this->assertAllowed($husband, $husbandBalances[LeaveTypeCases::Paternity]);
    }

    public function test_second_maternity_waits_for_an_accepted_first_one(): void
    {
        [$employee, $balances] = $this->staff(
            gender: Gender::Female,
            marital: MaritalStatus::Married,
            extra: [LeaveTypeCases::MaternityFirst, LeaveTypeCases::MaternitySecond],
        );
        $first = $balances[LeaveTypeCases::MaternityFirst];
        $second = $balances[LeaveTypeCases::MaternitySecond];

        $this->assertAllowed($employee, $first);
        $this->assertRefused($employee, $second, LeaveEligibility::REASON_FIRST_MATERNITY_NOT_TAKEN);

        // Pending is not taken.
        $request = $this->request($first, LeaveRequestStatus::Pending, hours: 8);
        $this->assertRefused($employee, $second, LeaveEligibility::REASON_FIRST_MATERNITY_NOT_TAKEN);
        $this->assertFalse($employee->abilityToTakeLeave(LeaveTypeCases::MaternityFirst));

        $request->update(['status' => LeaveRequestStatus::Accepted]);

        $this->assertAllowed($employee, $second);
        $this->assertRefused($employee, $first, LeaveEligibility::REASON_MATERNITY_TAKEN);
        $this->assertTrue($employee->abilityToTakeLeave(LeaveTypeCases::MaternityFirst));
    }

    public function test_maternity_is_refused_to_a_man_and_to_a_single_woman(): void
    {
        [$man, $manBalances] = $this->staff(gender: Gender::Male, marital: MaritalStatus::Married, extra: [LeaveTypeCases::MaternityFirst]);
        [$single, $singleBalances] = $this->staff(gender: Gender::Female, marital: MaritalStatus::Single, extra: [LeaveTypeCases::MaternityFirst]);

        $this->assertRefused($man, $manBalances[LeaveTypeCases::MaternityFirst], LeaveEligibility::REASON_NOT_MARRIED_FEMALE);
        $this->assertRefused($single, $singleBalances[LeaveTypeCases::MaternityFirst], LeaveEligibility::REASON_NOT_MARRIED_FEMALE);
    }

    public function test_a_balance_of_another_employee_is_refused(): void
    {
        [$employee] = $this->staff();
        [, $otherBalances] = $this->staff();

        $this->assertRefused($employee, $otherBalances[LeaveTypeCases::Annual], LeaveEligibility::REASON_NO_BALANCE);
    }

    public function test_a_forged_ineligible_balance_on_my_leaves_is_refused_and_writes_nothing(): void
    {
        [, $balances, $user] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE - 1);
        $unpaid = $balances[LeaveTypeCases::Unpaid];
        $message = app(LeaveEligibility::class)->message(LeaveEligibility::REASON_ANNUAL_REMAINS);

        $component = Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $unpaid->id)
            ->set('form.type', 'day')
            ->set('form.start_at', '2026-11-23')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'Unpaid while annual remains.')
            ->call('confirmSave')
            ->assertHasErrors(['form.leave_balance_id'])
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame($message, $component->errors()->first('form.leave_balance_id'));

        // The write path refuses it too, without the dialog.
        $component->call('processSave')->assertHasErrors(['form.leave_balance_id']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_an_eligible_balance_still_saves(): void
    {
        [, $balances, $user] = $this->staff(annualUsed: self::ANNUAL_ELIGIBLE);

        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $balances[LeaveTypeCases::Unpaid]->id)
            ->set('form.type', 'day')
            ->set('form.start_at', '2026-11-23')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'Unpaid once annual is used up.')
            ->call('processSave')
            ->assertHasNoErrors();

        $this->assertSame(1, LeaveRequest::query()->where('leave_balance_id', $balances[LeaveTypeCases::Unpaid]->id)->count());
    }

    public function test_an_edit_may_not_move_a_request_to_an_ineligible_balance_but_keeps_its_own_type(): void
    {
        [, $balances, $user] = $this->staff(fullSickUsed: 0);
        $halfSick = $balances[LeaveTypeCases::HalfSick];
        $fullSick = $balances[LeaveTypeCases::FullSick];

        // Filed while it was eligible; full sick has been restored since.
        $pending = $this->request($fullSick, LeaveRequestStatus::Pending, hours: 8, start: '2026-11-23');
        $legacy = $this->request($halfSick, LeaveRequestStatus::Pending, hours: 8, start: '2026-11-25');

        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $pending->id)
            ->set('form.leave_balance_id', (string) $halfSick->id)
            ->call('processSave')
            ->assertHasErrors(['form.leave_balance_id']);

        $this->assertSame((int) $fullSick->id, (int) $pending->fresh()->leave_balance_id);

        // A content edit of the request already on that balance is not refused.
        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $legacy->id)
            ->set('form.reason', 'Corrected reason text.')
            ->call('processSave')
            ->assertHasNoErrors();

        $this->assertSame('Corrected reason text.', $legacy->fresh()->reason);
    }

    public function test_hr_filing_for_an_employee_is_offered_and_held_to_that_employees_eligibility(): void
    {
        [$employee, $balances] = $this->staff(annualUsed: 0);
        $unpaid = $balances[LeaveTypeCases::Unpaid];
        $hr = $this->hrUser();

        $component = Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('create')
            ->set('form.employee_id', $employee->id);

        $offered = collect($component->get('allAvailableLeaves'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($offered->contains((int) $balances[LeaveTypeCases::Annual]->id));
        $this->assertFalse($offered->contains((int) $unpaid->id));

        $component
            ->set('form.leave_balance_id', (string) $unpaid->id)
            ->set('form.type', 'day')
            ->set('form.start_at', '2026-11-23')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'HR files unpaid while annual remains.')
            ->call('processSave')
            ->assertHasErrors(['form.leave_balance_id']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_reopening_hr_new_request_offers_no_leave_types_until_an_employee_is_chosen(): void
    {
        [$employee] = $this->staff(annualUsed: 0);

        $component = Livewire::actingAs($this->hrUser())
            ->test(AllLeaveRequestModule::class)
            ->call('create')
            ->assertDontSeeHtml('data-testid="leave-type-cards"')
            ->set('form.employee_id', $employee->id)
            ->assertSeeHtml('data-testid="leave-type-cards"')
            ->set('formModal', false)
            ->call('create');

        $this->assertCount(0, $component->get('allAvailableLeaves'));
        $this->assertCount(0, $component->get('alternativeEmployees'));
        $component->assertDontSeeHtml('data-testid="leave-type-cards"')
            ->assertSee(__('leaves.request.pick-employee-first'));
    }

    private function assertRefused(Employee $employee, LeaveBalance $balance, string $reason): void
    {
        $eligibility = app(LeaveEligibility::class);

        $this->assertSame($reason, $eligibility->reasonAgainst($employee->fresh(), $balance));
        $this->assertFalse(
            $eligibility->requestableBalances($employee->fresh())->contains(fn (LeaveBalance $b) => $b->id === $balance->id),
            'A refused balance must not be offered.',
        );
    }

    private function assertAllowed(Employee $employee, LeaveBalance $balance): void
    {
        $eligibility = app(LeaveEligibility::class);

        $this->assertNull($eligibility->reasonAgainst($employee->fresh(), $balance));
        $this->assertTrue(
            $eligibility->requestableBalances($employee->fresh())->contains(fn (LeaveBalance $b) => $b->id === $balance->id),
        );
    }

    /**
     * An employee with a user, an active contract and balances for annual
     * (120 h), full sick (96 h), half sick (96 h), unpaid (96 h) and $extra.
     *
     * @param  list<int>  $extra
     * @return array{0: Employee, 1: array<int, LeaveBalance>, 2: User}
     */
    private function staff(
        int $gender = Gender::Male,
        int $marital = MaritalStatus::Single,
        float $annualUsed = 0,
        float $fullSickUsed = 0,
        array $extra = [],
    ): array {
        $n = ++$this->sequence;
        $now = now();

        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $this->cityId,
            'first_name_en' => 'Staff'.$n, 'first_name_ar' => 'موظف'.$n,
            'last_name_en' => 'Member', 'last_name_ar' => 'عضو',
            'username' => 'eligibility-'.$n,
            'gender' => $gender,
            'marital_status' => $marital,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId, 'position_id' => $this->positionId,
            'contract_type_id' => $this->contractTypeId,
            'start_date' => $now->copy()->subMonth(), 'end_date' => $now->copy()->addYear(),
            'currency' => 2, 'weekly_hours' => 40,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $deserved = [
            LeaveTypeCases::Annual => [120, $annualUsed],
            LeaveTypeCases::FullSick => [96, $fullSickUsed],
            LeaveTypeCases::HalfSick => [96, 0],
            LeaveTypeCases::Unpaid => [96, 0],
        ];
        foreach ($extra as $type) {
            $deserved[$type] = [80, 0];
        }

        $balances = [];
        foreach ($deserved as $type => [$hours, $used]) {
            $balances[$type] = LeaveBalance::query()->findOrFail(DB::table('leave_balances')->insertGetId([
                'contract_id' => $contractId, 'type_id' => $type,
                'deserved' => $hours, 'used' => $used,
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'email' => 'eligibility'.$n.'@example.test',
            'password' => 'password',
        ]);
        $user->givePermissionTo(['leaves.create', 'leaves.update']);

        return [Employee::query()->findOrFail($employeeId), $balances, $user];
    }

    private function request(LeaveBalance $balance, int $status, float $hours, string $start = '2026-11-23'): LeaveRequest
    {
        $id = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $balance->id, 'type' => 'day',
            'start_at' => Carbon::parse($start), 'end_at' => Carbon::parse($start),
            'reason' => 'Fixture request.', 'status' => $status,
            'computed_hours' => $hours,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return LeaveRequest::query()->findOrFail($id);
    }

    /** HR for the fixture's city; HR roles in tests hold only what is granted here. */
    private function hrUser(): User
    {
        [$employee] = $this->staff();

        $hr = User::query()->create([
            'employee_id' => $employee->id, 'email' => 'eligibility.hr@example.test', 'password' => 'password',
        ]);
        $hr->assignRole(Role::findOrCreate('HR-ALP', 'web'));
        $hr->givePermissionTo(['leaves.create', 'leaves.update', 'leaves.view-all']);

        return $hr;
    }

    private function createSharedRows(): void
    {
        $now = now();

        $this->cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $programId = DB::table('programs')->insertGetId([
            'name' => 'Program', 'code' => 'PRG', 'slug' => 'program', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center', 'name_ar' => 'مركز', 'weekends' => '0,6', 'city_id' => $this->cityId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Department', 'code' => 'DEP', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $jobLevelId = DB::table('job_levels')->insertGetId([
            'name_en' => 'Officer', 'name_ar' => 'موظف', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->positionId = DB::table('positions')->insertGetId([
            'program_id' => $programId, 'center_id' => $centerId, 'city_id' => $this->cityId,
            'job_level_id' => $jobLevelId, 'department_id' => $departmentId,
            'title_en' => 'Officer', 'title_ar' => 'موظف',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $types = [
            LeaveTypeCases::Annual => 'Annual',
            LeaveTypeCases::FullSick => 'Full sick',
            LeaveTypeCases::HalfSick => 'Half sick',
            LeaveTypeCases::MaternityFirst => 'Maternity (first)',
            LeaveTypeCases::MaternitySecond => 'Maternity (second)',
            LeaveTypeCases::Paternity => 'Paternity',
            LeaveTypeCases::Marriage => 'Marriage',
            LeaveTypeCases::DeathFirst => 'Bereavement (first)',
            LeaveTypeCases::DeathSecond => 'Bereavement (second)',
            LeaveTypeCases::Unpaid => 'Unpaid',
        ];
        foreach ($types as $id => $name) {
            DB::table('leave_types')->insert([
                'id' => $id, 'name_en' => $name, 'name_ar' => $name, 'base_days' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
