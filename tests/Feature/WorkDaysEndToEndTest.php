<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Enums\LeaveTypeCases;
use App\Livewire\Contract\ContractAmend;
use App\Livewire\Contract\ContractAmendments;
use App\Livewire\Contract\ContractWorkDays;
use App\Livewire\Dashboard\EmployeeSectionCard;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveRequestPricing;
use App\Support\Leave\LeaveWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Work days end to end, through the screens each person uses: HR of the city
 * on the contract page (a Study Zone volunteer's picked days, a Micro Project
 * day range, a part-timer's range), an amendment made and approved on its
 * page, the direct and the technical manager from the employee's profile —
 * then who may see, open and change any of it, called directly as well.
 *
 * "Today" is Tuesday 10 Nov 2026. 15 Nov is a Sunday … 21 Nov a Saturday.
 */
class WorkDaysEndToEndTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $aleppo;

    private City $homs;

    private Department $department;

    private JobLevel $level;

    private ContractType $volunteer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
        $this->department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $this->volunteer = ContractType::query()->forceCreate(['name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوع']);
        DB::table('leave_types')->insert(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── HR of the city, on the contract page ─────────────────────────

    public function test_city_hr_moves_a_study_zone_volunteers_day_off_and_leave_follows(): void
    {
        // Picked days, off on Tuesday; the Study Zone centers close on Friday.
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);

        Livewire::actingAs($this->cityHr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->assertSet('mode', 'days')
            ->set('days', [6, 0, 1, 2, 3])               // off on Thursday instead
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors();

        // Before: Tuesday off. From the 15th: Thursday off, Tuesday worked.
        $this->assertSkipped($contract, '2026-11-10', '2026-11-12', ['2026-11-10' => 'day_off']);
        $this->assertSkipped($contract, '2026-11-17', '2026-11-20', ['2026-11-19' => 'day_off', '2026-11-20' => 'weekend']);
    }

    public function test_city_hr_moves_a_micro_project_range_by_picking_days(): void
    {
        // Sunday–Thursday as a range, the center closed on Friday only.
        $contract = $this->atCenter('Micro Project', weekends: '5', range: [0, 4]);

        Livewire::actingAs($this->cityHr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->assertSet('mode', 'range')
            ->set('mode', 'days')
            ->set('days', [6, 0, 1, 2, 3])               // Saturday–Wednesday, off Thursday
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors();

        // Before the 15th the range (Saturday off); after, Thursday off.
        $this->assertSkipped($contract, '2026-11-12', '2026-11-14', ['2026-11-13' => 'weekend', '2026-11-14' => 'day_off']);
        $this->assertSkipped($contract, '2026-11-19', '2026-11-21', ['2026-11-19' => 'day_off', '2026-11-20' => 'weekend']);
    }

    public function test_city_hr_shifts_a_part_timers_day_range(): void
    {
        // Monday–Tuesday, 14 h; the center closed on Sunday.
        $contract = $this->atCenter('Hope Center', weekends: '0', range: [1, 2], weeklyHours: 14);

        Livewire::actingAs($this->cityHr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->assertSet('mode', 'range')
            ->set('days', [2, 3])                        // Tuesday–Wednesday
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors();

        $quote = $this->quote($contract, '2026-11-16', '2026-11-18');
        $this->assertSame(2, $quote->countedDays);       // Tue 17, Wed 18
        $this->assertSame(7.0, $quote->hoursPerDay);
    }

    public function test_city_hr_cannot_change_the_number_of_days_without_an_amendment(): void
    {
        $contract = $this->atCenter('Hope Center', weekends: '0,6', range: [1, 5]);

        Livewire::actingAs($this->cityHr())
            ->test(ContractWorkDays::class, ['contract' => $contract])
            ->call('openChange')
            ->set('days', [1, 2, 3, 4])
            ->set('effectiveFrom', '2026-11-15')
            ->assertSee(__('contracts.work-days.make-amendment'))
            ->call('save')
            ->assertHasErrors(['days']);

        $this->assertSame(0, DB::table('contract_work_patterns')->where('contract_id', $contract->id)->count());
    }

    // ── An amendment, made and approved on its page ──────────────────

    public function test_city_hr_amends_the_day_range_and_it_takes_over_from_its_date(): void
    {
        // Monday–Friday, the center closed on the weekend; from 20 Nov Monday–Thursday.
        $contract = $this->atCenter('Hope Center', weekends: '0,6', range: [1, 5]);
        $hr = $this->cityHr();

        Livewire::actingAs($hr)
            ->test(ContractAmend::class, ['contract' => $contract])
            ->call('submit', $this->amendmentPayload(['workDayTo' => 4, 'weeklyHours' => 32, 'effectiveFrom' => '2026-11-20']))
            ->assertHasNoErrors();

        $amendment = ContractAmendment::query()->sole();

        Livewire::actingAs($hr)
            ->test(ContractAmendments::class, ['contract' => $contract])
            ->call('approve', $amendment->id);

        $this->assertTrue($amendment->fresh()->isApproved());

        // Not in effect yet, but leave after its date already follows it.
        $this->assertSkipped($contract, '2026-11-13', '2026-11-13', []);
        $this->assertSkipped($contract, '2026-11-27', '2026-11-27', ['2026-11-27' => 'day_off']);

        // The card shows it coming, and in its history once in effect.
        Livewire::actingAs($hr)
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->assertSee(__('contracts.work-days.next', ['date' => '20/11/2026', 'days' => 'Friday']), false);

        Carbon::setTestNow('2026-11-20 21:30:00');
        $this->artisan('contracts:apply-amendments')->assertSuccessful();
        $this->assertTrue($amendment->fresh()->isApplied());
        $this->assertSkipped($contract->fresh(), '2026-11-27', '2026-11-27', ['2026-11-27' => 'day_off']);

        Livewire::actingAs($hr)
            ->test(ContractWorkDays::class, ['contract' => $contract->fresh()])
            ->assertSee(__('contracts.work-days.range-badge', ['range' => 'Monday – Thursday']))
            ->assertSee(__('contracts.work-days.from-amendment', ['number' => 1]));
    }

    // ── The managers, from the employee's profile ────────────────────

    public function test_the_direct_manager_changes_the_days_from_the_profile(): void
    {
        $contract = $this->atCenter('Micro Project', weekends: '5', range: [0, 4]);
        [$manager] = $this->managerOf($contract, 'direct_manager_id');

        $this->profile($manager, $contract->employee)
            ->assertSee(__('contracts.work-days.title'))
            ->assertSeeHtml('data-testid="edit-work-days"');

        Livewire::actingAs($manager)
            ->test(ContractWorkDays::class, ['contract' => $contract, 'variant' => 'profile'])
            ->call('openChange')
            ->set('days', [6, 0, 1, 2, 3])
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([0, 1, 2, 3, 6], $contract->fresh()->workWeekdaysOn('2026-11-16'));
        $this->assertSame($manager->id, (int) DB::table('contract_work_patterns')->whereDate('effective_from', '2026-11-15')->value('created_by'));
    }

    public function test_the_technical_manager_moves_a_study_zone_day_off_from_the_profile(): void
    {
        $contract = $this->studyZone(picked: [6, 0, 1, 3, 4]);
        [$manager] = $this->managerOf($contract, 'tech_manager_id');

        Livewire::actingAs($manager)
            ->test(ContractWorkDays::class, ['contract' => $contract, 'variant' => 'profile'])
            ->call('openChange')
            ->set('days', [6, 0, 1, 2, 4])               // off on Wednesday
            ->set('effectiveFrom', '2026-11-15')
            ->call('save')
            ->assertHasNoErrors()
            // A manager makes no amendment: a different count says who to ask.
            ->call('openChange')
            ->set('days', [6, 0, 1, 2])
            ->assertSee(__('contracts.work-days.amend-ask-hr'))
            ->assertDontSee(__('contracts.work-days.make-amendment'));

        $this->assertSkipped($contract, '2026-11-17', '2026-11-18', ['2026-11-18' => 'day_off']);
    }

    // ── Who may see, open and change it ──────────────────────────────

    public function test_who_may_see_and_change_the_work_days(): void
    {
        $contract = $this->atCenter('Micro Project', weekends: '5', range: [0, 4]);
        [$direct] = $this->managerOf($contract, 'direct_manager_id');
        [$technical] = $this->managerOf($contract, 'tech_manager_id');
        [$otherManager] = $this->managerOf($this->atCenter('Micro Project', weekends: '5', range: [0, 4]), 'direct_manager_id');

        $cases = [
            //  who                                                                                 sees   changes
            'HR of the city' => [$this->cityHr(), true, true],
            'HR Syria' => [$this->makeUser($this->homs, 'HR-SYR', ['contracts.view', 'contracts.update']), true, true],
            'HR of another city' => [$this->makeUser($this->homs, 'HR-HMS', ['contracts.view', 'contracts.update']), false, false],
            'direct manager' => [$direct, true, true],
            'technical manager' => [$technical, true, true],
            'another manager' => [$otherManager, false, false],
            'the employee' => [$this->makeUser($this->aleppo, 'employee', [], $contract->employee), true, false],
            'a colleague' => [$this->makeUser($this->aleppo, 'employee'), false, false],
            'the direct manager, acting as employee' => [$this->makeUser($this->aleppo, 'employee', [], $direct->profile), false, false],
        ];

        foreach ($cases as $who => [$user, $sees, $changes]) {
            $this->assertSame($sees, $user->can('viewWorkDays', $contract), "{$who}: sees");
            $this->assertSame($changes, $user->can('changeWorkDays', $contract), "{$who}: changes");

            if (! $sees) {
                // The card itself refuses them, even called directly.
                Livewire::actingAs($user)->test(ContractWorkDays::class, ['contract' => $contract])->assertForbidden();
                $this->assertFalse($user->can('viewWorkDays', $contract->employee), "{$who}: dashboard card");

                continue;
            }

            $card = Livewire::actingAs($user)->test(ContractWorkDays::class, ['contract' => $contract]);

            if (! $changes) {
                // Opening and saving the dialog are refused on the server, not only hidden.
                $card->call('openChange')->assertForbidden();
                // Each step a crafted request could take is refused on its own.
                Livewire::actingAs($user)->test(ContractWorkDays::class, ['contract' => $contract])
                    ->set('days', [6, 0, 1, 2, 3])->assertForbidden();
                Livewire::actingAs($user)->test(ContractWorkDays::class, ['contract' => $contract])
                    ->set('effectiveFrom', '2026-11-15')->assertForbidden();
                Livewire::actingAs($user)->test(ContractWorkDays::class, ['contract' => $contract])
                    ->call('save')->assertForbidden();
            }
        }

        // Nobody without the right got a row in.
        $this->assertSame(0, DB::table('contract_work_patterns')->where('contract_id', $contract->id)->count());
    }

    public function test_a_manager_cannot_open_the_amendment_page(): void
    {
        $contract = $this->atCenter('Hope Center', weekends: '0,6', range: [1, 5]);
        [$manager] = $this->managerOf($contract, 'direct_manager_id');

        $this->actingAs($manager)->get(route('contracts.amend', $contract))->assertForbidden();
        $this->actingAs($this->cityHr())->get(route('contracts.amend', $contract))->assertOk();
    }

    public function test_the_dashboard_card_follows_the_same_rule(): void
    {
        $contract = $this->atCenter('Micro Project', weekends: '5', range: [0, 4]);
        [$manager] = $this->managerOf($contract, 'direct_manager_id');

        Livewire::withoutLazyLoading()->actingAs($manager)
            ->test(EmployeeSectionCard::class, ['employeeId' => $contract->employee_id, 'section' => 'work-days'])
            ->assertSee('Sunday, Monday, Tuesday, Wednesday, Thursday');

        Livewire::withoutLazyLoading()->actingAs($this->makeUser($this->homs, 'HR-HMS', ['contracts.view', 'contracts.update']))
            ->test(EmployeeSectionCard::class, ['employeeId' => $contract->employee_id, 'section' => 'work-days'])
            ->assertSee(__('dashboard.record.gone'));
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function cityHr(): User
    {
        return $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'contracts.view', 'contracts.update', 'contracts.amend']);
    }

    /** A Study Zone volunteer in Aleppo (no center of their own; the centers close on Friday). */
    private function studyZone(array $picked): Contract
    {
        $program = Program::query()->find(Program::STUDY_ZONE_ID)
            ?? Program::query()->forceCreate(['id' => Program::STUDY_ZONE_ID, 'name' => 'Study Zone', 'slug' => 'SZ', 'code' => 'SZ', 'is_active' => true]);

        if (! Center::query()->where('name_en', 'Study Zone Aleppo')->exists()) {
            Center::query()->create([
                'name_en' => 'Study Zone Aleppo', 'name_ar' => 'ستادي زون حلب', 'city_id' => $this->aleppo->id,
                'is_active' => true, 'weekends' => '5', 'open_at' => '08:00:00', 'close_at' => '21:00:00',
            ])->programs()->attach($program);
        }

        return $this->contract($program, null, picked: $picked);
    }

    private function atCenter(string $programName, string $weekends, array $range, int $weeklyHours = 40): Contract
    {
        $program = Program::query()->firstOrCreate(['code' => strtoupper(substr($programName, 0, 3))], ['name' => $programName, 'slug' => str($programName)->slug(), 'is_active' => true]);
        $center = Center::query()->create([
            'name_en' => $programName, 'name_ar' => $programName, 'city_id' => $this->aleppo->id,
            'is_active' => true, 'weekends' => $weekends, 'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);

        return $this->contract($program, $center, range: $range, weeklyHours: $weeklyHours);
    }

    private function contract(Program $program, ?Center $center, ?array $picked = null, ?array $range = null, int $weeklyHours = 40): Contract
    {
        $position = Position::query()->forceCreate([
            'center_id' => $center?->id, 'city_id' => $this->aleppo->id, 'department_id' => $this->department->id,
            'job_level_id' => $this->level->id, 'program_id' => $program->id,
            'title_en' => 'Officer', 'title_ar' => 'مسؤول', 'max_emp' => 50, 'exist_emp' => 0, 'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'employee_id' => $this->makeEmployee($this->aleppo, 'wd-'.uniqid())->id,
            'position_id' => $position->id,
            'contract_type_id' => $this->volunteer->id,
            'type' => ContractTypeEnum::FullTime,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => Currency::USD,
            'salary_per' => 1,
            'salary' => 150,
            'pay_mode' => 1,
            'weekly_hours' => $weeklyHours,
            'schedule_mode' => 1,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'work_day_from' => $range[0] ?? null,
            'work_day_to' => $range[1] ?? null,
            'work_weekdays' => $picked !== null ? implode(',', Contract::parseWeekdays($picked)) : null,
            'is_active' => true,
        ]);

        app(ContractWorkPatterns::class)->syncInitial($contract);
        LeaveBalance::query()->updateOrCreate(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual], ['deserved' => 400, 'used' => 0]);

        return $contract->fresh();
    }

    /**
     * A manager (active role) holding a position, linked to $contract's
     * position as its direct or technical manager.
     *
     * @return array{0: User, 1: Position}
     */
    private function managerOf(Contract $contract, string $link): array
    {
        $employee = $this->makeEmployee($this->aleppo, 'mgr-'.uniqid());
        $held = $this->contract(Program::query()->firstOrCreate(['code' => 'MGT'], ['name' => 'Management', 'slug' => 'management', 'is_active' => true]), null, range: [0, 4]);
        $held->update(['employee_id' => $employee->id]);
        $contract->position->update([$link => $held->position_id]);

        return [$this->makeUser($this->aleppo, 'manager', [], $employee), $held->position];
    }

    private function profile(User $user, Employee $employee)
    {
        return Livewire::withoutLazyLoading()->actingAs($user)->test(EmployeeProfile::class, ['employee' => $employee]);
    }

    private function amendmentPayload(array $overrides): array
    {
        return array_merge([
            'type' => ContractTypeEnum::FullTime,
            'scheduleMode' => 1,
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'workDayFrom' => 1,
            'workDayTo' => 5,
            'weeklyHours' => 40,
            'payMode' => 1,
            'salary' => 150,
            'currency' => Currency::USD,
            'salaryPer' => 1,
            'payTiers' => [],
            'reason' => 'Four days a week',
        ], $overrides);
    }

    private function quote(Contract $contract, string $from, string $to)
    {
        $balance = LeaveBalance::query()->where('contract_id', $contract->id)->where('type_id', LeaveTypeCases::Annual)->firstOrFail();

        return (new LeaveRequestPricing)->quote($contract->fresh(['position.center', 'employee']), $balance, LeaveWindow::days($from, $to));
    }

    /** @param  array<string, string>  $skipped  every skipped date => reason */
    private function assertSkipped(Contract $contract, string $from, string $to, array $skipped): void
    {
        $this->assertSame(
            $skipped,
            collect($this->quote($contract, $from, $to)->excludedDates)->mapWithKeys(fn (array $d) => [$d['date'] => $d['reason']])->all(),
            "{$from}..{$to}",
        );
    }
}
