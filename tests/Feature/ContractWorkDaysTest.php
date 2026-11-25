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

    public function test_a_new_date_shows_that_dates_days_until_hr_picks_others(): void
    {
        $contract = $this->contract();
        app(ContractWorkPatterns::class)->change($contract, [6, 0, 1, 2, 3], '2026-11-25');

        $component = Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->call('openChange')
            ->assertSet('days', [0, 1, 2, 3, 4])
            ->set('effectiveFrom', '2026-12-01')
            ->assertSet('days', [0, 1, 2, 3, 6]);

        // Once HR has picked, a new date leaves the picked days alone.
        $component->set('days', [0, 1, 3, 4, 6])
            ->set('effectiveFrom', '2026-12-08')
            ->assertSet('days', [0, 1, 3, 4, 6]);
    }

    public function test_changing_the_days_clears_an_error_about_them(): void
    {
        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $this->contract()])
            ->call('openChange')
            ->set('days', [0, 1, 2, 3])
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasErrors(['days'])
            ->set('days', [0, 1, 2, 3, 6])
            ->assertHasNoErrors(['days']);
    }

    public function test_a_change_on_the_date_an_amendment_set_the_days_is_refused(): void
    {
        $contract = $this->contract();
        $amendmentId = DB::table('contract_amendments')->insertGetId([
            'contract_id' => $contract->id, 'number' => 1, 'status' => 2, 'effective_from' => '2026-11-15',
            'terms_before' => '{}', 'terms_after' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contract_work_patterns')->insert([
            'contract_id' => $contract->id, 'weekdays' => '0,1,2,3,6', 'effective_from' => '2026-11-15',
            'contract_amendment_id' => $amendmentId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(ContractWorkPatterns::class)->change($contract->fresh(), [0, 1, 2, 3, 4], '2026-11-15');
            $this->fail('A change on the date of an amendment was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }

        $this->assertSame($amendmentId, DB::table('contract_work_patterns')->whereDate('effective_from', '2026-11-15')->value('contract_amendment_id'));
    }

    public function test_the_dialog_skips_an_amendments_date_and_says_so_when_it_is_picked(): void
    {
        // Contract 415's case: an amendment set the days today (10 Nov).
        $contract = $this->contract();
        $amendmentId = DB::table('contract_amendments')->insertGetId([
            'contract_id' => $contract->id, 'number' => 1, 'status' => 2, 'effective_from' => '2026-11-10',
            'terms_before' => '{}', 'terms_after' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contract_work_patterns')->insert([
            'contract_id' => $contract->id, 'weekdays' => '0,1,2,3,4', 'effective_from' => '2026-11-10',
            'contract_amendment_id' => $amendmentId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $message = __('contracts.work-days.amendment-on-date', ['number' => 1]);

        Livewire::actingAs($this->hr())
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->call('openChange')
            // Opens on the next free day, not on the amendment's.
            ->assertSet('effectiveFrom', '2026-11-11')
            ->assertDontSee($message)
            // Picking the amendment's date says so at once, with the way out.
            ->set('effectiveFrom', '2026-11-10')
            ->assertSee($message)
            ->assertSee(__('contracts.work-days.use-date', ['date' => '11/11/2026']))
            // And the way out saves.
            ->set('effectiveFrom', '2026-11-11')
            ->call('toggleDay', 4)
            ->call('toggleDay', 6)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([0, 1, 2, 3, 6], $contract->fresh()->workWeekdaysOn('2026-11-11'));
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

        // Before the date, its range: Saturday is outside it, Friday the center's.
        $this->assertSame([[5, 6], 'contract'], $pricing->weekendFor($contract, Carbon::parse('2026-11-12')));
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

    public function test_the_direct_or_technical_manager_may_change_the_days_and_no_other_manager(): void
    {
        $contract = $this->contract();
        [$direct, $directPosition] = $this->manager();
        [$technical, $technicalPosition] = $this->manager();
        [$other] = $this->manager();
        $contract->position->update(['direct_manager_id' => $directPosition->id, 'tech_manager_id' => $technicalPosition->id]);
        $contract = $contract->fresh();

        $this->assertTrue($direct->can('changeWorkDays', $contract));
        $this->assertTrue($technical->can('changeWorkDays', $contract));
        $this->assertFalse($other->can('changeWorkDays', $contract));
        $this->assertFalse($other->can('viewWorkDays', $contract));

        // The same person with another role active is not acting as manager.
        $this->assertFalse($this->makeUser($this->city, 'employee', [], $direct->profile)->can('changeWorkDays', $contract));
    }

    public function test_the_manager_moves_the_day_off_from_the_employees_profile(): void
    {
        $contract = $this->contract();
        [$manager, $position] = $this->manager();
        $contract->position->update(['tech_manager_id' => $position->id]);

        Livewire::actingAs($manager)
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh(), 'variant' => 'profile'])
            ->assertSee(__('contracts.work-days.title'))
            ->assertSee(__('contracts.work-days.change'))
            ->call('openChange')
            ->call('toggleDay', 4)
            ->call('toggleDay', 6)
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([0, 1, 2, 3, 6], $contract->fresh()->workWeekdaysOn('2026-11-15'));
    }

    public function test_the_employee_sees_their_own_days_and_hours_but_cannot_change_them(): void
    {
        $contract = $this->contract(['work_hours_2_from' => '17:00:00', 'work_hours_2_to' => '19:00:00']);
        $self = $this->makeUser($this->city, 'employee', [], $contract->employee);

        $this->assertTrue($self->can('viewWorkDays', $contract));
        $this->assertFalse($self->can('changeWorkDays', $contract));

        Livewire::actingAs($self)
            ->test(ContractWorkDays::class, ['contract' => $contract, 'variant' => 'profile'])
            ->assertSee('09:00–17:00')
            ->assertSee('17:00–19:00')
            ->assertSee(trans_choice('contracts.work-days.weekly-hours', 40, ['count' => 40]))
            ->assertDontSee(__('contracts.work-days.change'))
            ->call('openChange')
            ->assertForbidden();
    }

    public function test_the_dialog_shows_each_viewer_the_way_to_an_amendment(): void
    {
        $contract = $this->contract();
        [$manager, $position] = $this->manager();
        $contract->position->update(['direct_manager_id' => $position->id]);

        // HR who may amend: a link to make one.
        Livewire::actingAs($this->makeUser($this->city, 'HR-ALP', [...self::PERMISSIONS, 'contracts.amend']))
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->call('openChange')
            ->assertSee(__('contracts.work-days.make-amendment'))
            ->assertSeeHtml('href="'.route('contracts.amend', $contract).'"');

        // A manager makes none: who to ask instead.
        Livewire::actingAs($manager)
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->call('openChange')
            ->assertSee(__('contracts.work-days.amend-ask-hr'))
            ->assertDontSee(__('contracts.work-days.make-amendment'));
    }

    /**
     * A manager (active role) holding a position today.
     *
     * @return array{0: User, 1: \App\Models\Position}
     */
    private function manager(): array
    {
        $employee = $this->makeEmployee($this->city, 'manager-'.uniqid());
        $contract = $this->makeContractFor($employee);

        return [$this->makeUser($this->city, 'manager', [], $employee), $contract->position];
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
