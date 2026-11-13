<?php

namespace Tests\Feature;

use App\Enums\LeaveTypeCases;
use App\Enums\ScheduleMode;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveQuote;
use App\Support\Leave\LeaveWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A position without a center (every Study Zone position) takes the weekend
 * and working hours from the centers HR linked to its program in its city
 * (center_program): the most common weekend, the widest hours. A position
 * with a center keeps its own center's, whatever else the center serves.
 *
 * "Today" is Wednesday 2026-10-21. 3 Nov 2026 is a Tuesday, 6 Nov a Friday,
 * 7 Nov a Saturday, 8 Nov a Sunday.
 */
class LeaveProgramCentersTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestPricing $pricing;

    private int $homs;

    private int $aleppo;

    private int $studyZone;

    private int $micro;

    private int $departmentId;

    private int $jobLevelId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->pricing = new LeaveRequestPricing;

        $this->homs = $this->insert('cities', ['name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002']);
        $this->aleppo = $this->insert('cities', ['name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001']);
        $this->studyZone = $this->insert('programs', ['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'study-zone']);
        $this->micro = $this->insert('programs', ['name' => 'Micro Project', 'code' => 'MIC', 'slug' => 'micro']);
        $this->departmentId = $this->insert('departments', ['name' => 'Department', 'code' => 'DEP']);
        $this->jobLevelId = $this->insert('job_levels', ['name_en' => 'Volunteer', 'name_ar' => 'متطوع']);

        DB::table('contract_types')->insert(['id' => 1, 'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_types')->insert(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_volunteer_without_a_center_takes_the_linked_centers_hours_and_weekend(): void
    {
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->studyZone]);
        [$contract, $balance] = $this->volunteer($this->studyZone, $this->homs);

        $evening = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '17:00', '21:00'));
        $saturday = $this->quote($contract, $balance, LeaveWindow::days('2026-11-07', '2026-11-07'));
        $friday = $this->quote($contract, $balance, LeaveWindow::days('2026-11-06', '2026-11-06'));

        $this->assertSame([[5], 'program'], $this->pricing->weekendFor($contract));
        $this->assertSame([], $evening->errors);
        $this->assertSame([], $evening->warnings);
        $this->assertSame(4.0, $evening->requestedHours);
        $this->assertSame(1, $saturday->countedDays);
        $this->assertSame([LeaveQuote::NO_COUNTABLE_DAYS], $friday->errors);
        // Six working days: 40 h a week is 6.67 h a day, not 8.
        $this->assertEqualsWithDelta(40 / 6, $evening->hoursPerDay, 0.0001);
    }

    public function test_the_linked_centers_hours_bound_the_request_exactly(): void
    {
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->studyZone]);
        [$contract, $balance] = $this->volunteer($this->studyZone, $this->homs);

        foreach ([['08:00', '09:00'], ['20:00', '21:00'], ['08:00', '21:00']] as [$from, $to]) {
            $this->assertNotContains(LeaveQuote::OUTSIDE_WORKING_HOURS, $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', $from, $to))->errors, "{$from}-{$to}");
        }

        foreach ([['07:59', '09:00'], ['20:00', '21:01'], ['21:00', '22:00']] as [$from, $to]) {
            $this->assertContains(LeaveQuote::OUTSIDE_WORKING_HOURS, $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', $from, $to))->errors, "{$from}-{$to}");
        }
    }

    public function test_several_centers_give_the_widest_hours_and_the_most_common_weekend(): void
    {
        $this->center($this->homs, '5', '09:00', '17:00', programs: [$this->studyZone]);
        $this->center($this->homs, '5', '13:00', '21:00', programs: [$this->studyZone]);
        // A six-day weekend is a data error and is not counted.
        $this->center($this->homs, '6,4,3,2,1,0', '09:00', '21:00', programs: [$this->studyZone]);
        [$contract, $balance] = $this->volunteer($this->studyZone, $this->homs);

        $this->assertSame([[5], 'program'], $this->pricing->weekendFor($contract));
        $this->assertSame([], $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '09:00', '21:00'))->errors);
    }

    public function test_a_tie_keeps_only_the_days_every_weekend_shares(): void
    {
        $this->center($this->homs, '5,6', programs: [$this->studyZone]);
        $this->center($this->homs, '5', programs: [$this->studyZone]);
        [$contract] = $this->volunteer($this->studyZone, $this->homs);

        $this->assertSame([[5], 'program'], $this->pricing->weekendFor($contract));
    }

    public function test_without_a_linked_center_in_the_city_nothing_changes(): void
    {
        // Linked, but in another city; and in this city, a center of another program.
        $this->center($this->aleppo, '5', '08:00', '21:00', programs: [$this->studyZone]);
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->micro]);
        [$contract, $balance] = $this->volunteer($this->studyZone, $this->homs);

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '17:00', '21:00'));

        $this->assertSame([[0, 6], 'default'], $this->pricing->weekendFor($contract));
        $this->assertSame([LeaveQuote::OUTSIDE_WORKING_HOURS], $quote->errors);
        $this->assertTrue($quote->hasWarning(LeaveQuote::BALANCE_UNKNOWN_WEEKEND));
    }

    public function test_an_inactive_linked_center_is_ignored(): void
    {
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->studyZone], active: false);
        [$contract] = $this->volunteer($this->studyZone, $this->homs);

        $this->assertSame([[0, 6], 'default'], $this->pricing->weekendFor($contract));
    }

    public function test_a_volunteer_with_a_center_keeps_their_own_centers_schedule(): void
    {
        // A Hope Center serving two programs; the volunteer's position is at it.
        $hope = $this->center($this->homs, '0,6', '09:00', '17:00', programs: [$this->micro, $this->studyZone]);
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->micro]);
        [$contract, $balance] = $this->volunteer($this->micro, $this->homs, center: $hope);

        $quote = $this->quote($contract, $balance, LeaveWindow::hours('2026-11-03', '17:00', '21:00'));

        $this->assertSame([[0, 6], 'center'], $this->pricing->weekendFor($contract));
        $this->assertSame([LeaveQuote::OUTSIDE_WORKING_HOURS], $quote->errors);
    }

    public function test_the_study_zone_volunteer_can_send_17_to_21_on_saturday_from_the_form(): void
    {
        $this->center($this->homs, '5', '08:00', '21:00', programs: [$this->studyZone]);
        [$contract, $balance] = $this->volunteer($this->studyZone, $this->homs, weeklyHours: 20);
        $user = User::query()->create(['employee_id' => $contract->employee_id, 'email' => 'sz@example.test', 'password' => 'password']);
        $user->givePermissionTo(['leaves.create', 'leaves.update']);

        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $balance->id)
            ->set('form.type', 'hour')
            ->set('form.leave_date', '2026-11-07')
            ->set('form.start_hour', '17:00')
            ->set('form.end_hour', '21:00')
            ->set('form.reason', 'Evening shift, Saturday.')
            ->call('processSave')
            ->assertHasNoErrors();

        $this->assertSame(4.0, (float) LeaveRequest::query()->sole()->computed_hours);
    }

    // --- Helpers ----------------------------------------------------------------------

    private function quote(Contract $contract, LeaveBalance $balance, LeaveWindow $window): LeaveQuote
    {
        return $this->pricing->quote($contract, $balance, $window);
    }

    /** @param  list<int>  $programs */
    private function center(int $cityId, string $weekends, string $open = '09:00', string $close = '17:00', array $programs = [], bool $active = true): int
    {
        $id = $this->insert('centers', [
            'name_en' => 'Center', 'name_ar' => 'مركز', 'weekends' => $weekends,
            'open_at' => $open.':00', 'close_at' => $close.':00', 'city_id' => $cityId, 'is_active' => $active,
        ]);

        foreach ($programs as $programId) {
            DB::table('center_program')->insert(['center_id' => $id, 'program_id' => $programId]);
        }

        return $id;
    }

    /**
     * An active volunteer on an annual contract with a 160 h annual balance.
     *
     * @return array{0: Contract, 1: LeaveBalance}
     */
    private function volunteer(int $programId, int $cityId, ?int $center = null, int $weeklyHours = 40): array
    {
        $this->sequence++;

        $employeeId = $this->insert('employees', [
            'city_id' => $cityId, 'is_active' => true,
            'first_name_en' => 'Study', 'first_name_ar' => 'دراسة',
            'last_name_en' => 'Volunteer', 'last_name_ar' => 'متطوع',
            'username' => 'volunteer-'.$this->sequence,
        ]);

        $positionId = $this->insert('positions', [
            'program_id' => $programId, 'center_id' => $center, 'city_id' => $cityId,
            'job_level_id' => $this->jobLevelId, 'department_id' => $this->departmentId,
            'title_en' => 'Tutor', 'title_ar' => 'مدرس',
        ]);

        $contractId = $this->insert('contracts', [
            'employee_id' => $employeeId,
            'position_id' => $positionId,
            'contract_type_id' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'weekly_hours' => $weeklyHours,
            'schedule_mode' => ScheduleMode::Fixed,
            'is_active' => true,
        ]);

        $balanceId = $this->insert('leave_balances', [
            'contract_id' => $contractId, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 160, 'used' => 0,
        ]);

        return [Contract::query()->findOrFail($contractId), LeaveBalance::query()->findOrFail($balanceId)];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
