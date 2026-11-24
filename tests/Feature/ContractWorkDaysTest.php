<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Contract\ContractWorkDays;
use App\Models\AttendanceMonth;
use App\Models\City;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractAutoRenewalService;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Change days off" on a contract's page (ContractWorkPatterns::change): the
 * day off moved from a date on, without an amendment — who may, what it keeps
 * and what it refuses. "Today" is Tuesday 10 Nov 2026; 15 Nov is a Sunday.
 */
class ContractWorkDaysTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const PERMISSIONS = ['contracts.view', 'contracts.update'];

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_city_hr_moves_the_day_off_from_a_date_and_the_days_before_stay(): void
    {
        $contract = $this->contract();

        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->assertSee(__('contracts.work-days.change'))
            ->call('openChange')
            ->assertSet('days', [0, 1, 2, 3, 4])
            ->call('toggleDay', 4)
            ->call('toggleDay', 6)
            ->set('effectiveFrom', '2026-11-15')
            ->set('note', 'Moved to Thursday')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showChange', false);

        $contract = $contract->fresh();
        $this->assertSame([0, 1, 2, 3, 4], $contract->workWeekdaysOn('2026-11-14'));
        $this->assertSame([0, 1, 2, 3, 6], $contract->workWeekdaysOn('2026-11-15'));
        // The contract's own terms (what it prints, how many days) are untouched.
        $this->assertSame([0, 1, 2, 3, 4], $contract->workWeekdays());
    }

    public function test_only_hr_of_the_contracts_city_or_hr_syria_may_change_the_days(): void
    {
        $contract = $this->contract();
        $other = $this->makeCity('HMS', '002');

        $this->assertTrue($this->hr()->can('changeWorkDays', $contract));
        $this->assertTrue($this->makeUser($other, 'HR-SYR', self::PERMISSIONS)->can('changeWorkDays', $contract));
        $this->assertFalse($this->makeUser($other, 'HR-HMS', self::PERMISSIONS)->can('changeWorkDays', $contract));
        $this->assertFalse($this->makeUser($this->city, 'manager', self::PERMISSIONS)->can('changeWorkDays', $contract));
    }

    public function test_hr_of_another_city_cannot_open_the_dialog(): void
    {
        $contract = $this->contract();
        $other = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', self::PERMISSIONS);

        Livewire::actingAs($other)
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->assertForbidden();
    }

    public function test_a_different_number_of_days_is_an_amendment_not_a_change(): void
    {
        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $this->contract()])
            ->call('openChange')
            ->call('toggleDay', 4)
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasErrors(['days']);

        $this->assertSame(1, DB::table('contract_work_patterns')->count());
    }

    public function test_the_centers_day_off_cannot_be_picked(): void
    {
        $contract = $this->contract();

        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->call('toggleDay', 5)
            ->assertSet('days', [0, 1, 2, 3, 4]);

        $this->expectException(ValidationException::class);
        app(ContractWorkPatterns::class)->change($contract, [0, 1, 2, 3, 5], '2026-11-15');
    }

    public function test_a_date_in_a_locked_attendance_month_is_refused(): void
    {
        $contract = $this->contract();
        AttendanceMonth::query()->create(['employee_id' => $contract->employee_id, 'month' => '2026-11-01', 'locked_at' => now()]);

        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->call('toggleDay', 4)
            ->call('toggleDay', 6)
            ->set('effectiveFrom', '2026-11-02')
            ->call('save')
            ->assertHasErrors(['effectiveFrom']);
    }

    public function test_a_past_date_is_allowed(): void
    {
        $contract = $this->contract();

        app(ContractWorkPatterns::class)->change($contract, [6, 0, 1, 2, 3], '2026-10-04');

        $this->assertSame([0, 1, 2, 3, 6], $contract->fresh()->workWeekdaysOn('2026-10-05'));
    }

    public function test_a_range_contract_switches_to_its_own_days_from_the_chosen_date(): void
    {
        // An HR role without contracts.update (its own role name, so no permission carries over).
        $this->assertFalse($this->makeUser($this->city, 'HR-TAR', ['contracts.view'])->can('changeWorkDays', $this->contract()));

        // Hope Center style: Sunday to Thursday, the center closed on Friday.
        $contract = $this->contract(['work_weekdays' => null, 'work_day_from' => 0, 'work_day_to' => 4]);
        $pricing = new LeaveRequestPricing;

        app(ContractWorkPatterns::class)->change($contract, [6, 0, 1, 2, 3], '2026-11-15');
        $contract = $contract->fresh();

        $this->assertSame([[5], 'center'], $pricing->weekendFor($contract, Carbon::parse('2026-11-12')));
        $this->assertSame([[4, 5], 'contract'], $pricing->weekendFor($contract, Carbon::parse('2026-11-16')));
    }

    public function test_the_dialog_lists_accepted_leave_from_the_date(): void
    {
        $contract = $this->contract();
        DB::table('leave_types')->insert(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $balanceId = DB::table('leave_balances')->insertGetId(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 100, 'used' => 8, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_requests')->insert(['leave_balance_id' => $balanceId, 'type' => 'day', 'start_at' => '2026-11-19', 'end_at' => '2026-11-19', 'reason' => 'Accepted.', 'status' => LeaveRequestStatus::Accepted, 'created_at' => now(), 'updated_at' => now()]);

        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->set('effectiveFrom', '2026-11-15')
            ->assertSee(trans_choice('contracts.work-days.affected', 1, ['count' => 1]))
            ->set('effectiveFrom', '2026-11-20')
            ->assertDontSee(trans_choice('contracts.work-days.affected', 1, ['count' => 1]));
    }

    public function test_an_automatic_renewal_starts_with_the_days_worked_at_the_end(): void
    {
        $this->mock(LeaveBalanceService::class)->shouldIgnoreMissing();
        $contract = $this->contract(['end_date' => '2026-11-30', 'auto_renew' => true]);
        app(ContractWorkPatterns::class)->change($contract, [6, 0, 1, 2, 3], '2026-11-15');
        $contract->update(['is_active' => false]);

        $renewal = app(ContractAutoRenewalService::class)->renewIfNeeded($contract->fresh(), Carbon::parse('2027-01-02'));

        $this->assertNotNull($renewal);
        $this->assertSame([0, 1, 2, 3, 6], $renewal->workWeekdays());
        $this->assertSame([0, 1, 2, 3, 6], $renewal->fresh()->workWeekdaysOn($renewal->start_date));
    }

    private function hr(): User
    {
        return $this->makeUser($this->city, 'HR-ALP', self::PERMISSIONS);
    }

    /** Sunday to Thursday picked one by one, at a center closed on Friday. */
    private function contract(array $overrides = []): Contract
    {
        $contract = $this->makeContractFor($this->makeEmployee($this->city, 'work-days-'.uniqid()), array_merge([
            'schedule_mode' => 1,
            'work_weekdays' => '0,1,2,3,4',
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
        ], $overrides));
        $contract->position->center->update(['weekends' => '5']);
        app(ContractWorkPatterns::class)->syncInitial($contract);

        return $contract->fresh();
    }
}
