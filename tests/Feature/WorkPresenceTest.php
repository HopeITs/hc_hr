<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\ScheduleMode;
use App\Livewire\Employee\EmployeeProfile;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Services\WorkPresence;
use App\Support\Dashboard\WorkdayClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The presence dot (WorkPresence, DESIGN.md "Presence dot"). Lina reports to
 * Sami (center A, Homs) and works Sunday to Thursday, 09:00–13:00 and
 * 14:00–17:00, her days written only as text, as on every imported contract.
 * Rana (finance, center B, Aleppo) shares nothing with her, so the team
 * calendar shows her none of Lina's absences. Tue 27 Oct 2026.
 */
class WorkPresenceTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $p = [];

    private array $cities = [];

    private array $centers = [];

    private Program $program;

    private JobLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-10-27 10:00:00');
        app()->setLocale('en');

        $this->cities = ['HMS' => $this->makeCity('HMS', '002'), 'ALP' => $this->makeCity('ALP', '001')];
        $this->centers = [
            'A' => Center::query()->create(['name_en' => 'Center A', 'name_ar' => 'أ', 'city_id' => $this->cities['HMS']->id, 'is_active' => true, 'weekends' => '5,6']),
            'B' => Center::query()->create(['name_en' => 'Center B', 'name_ar' => 'ب', 'city_id' => $this->cities['ALP']->id, 'is_active' => true, 'weekends' => '5,6']),
        ];
        $this->program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true]);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'فريق', 'grade' => 6]);
        LeaveType::query()->forceCreate(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 14]);

        $this->person('sami', 'HMS', 'A', 'MGMT', null);
        $this->person('lina', 'HMS', 'A', 'Tchr', $this->p['sami']['position']->id, [
            'work_days_en' => 'From Sunday Till Thursday',
            'work_hours_from' => '09:00:00', 'work_hours_to' => '13:00:00',
            'work_hours_2_from' => '14:00:00', 'work_hours_2_to' => '17:00:00',
        ]);
        $this->person('rana', 'ALP', 'B', 'Fin', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_in_working_hours_without_leave_is_online_for_everyone(): void
    {
        $this->assertSame(['tone' => 'online', 'label' => 'Online'], $this->dot('sami', 'lina'));
        $this->assertSame(['tone' => 'online', 'label' => 'Online'], $this->dot('rana', 'lina'));
    }

    public function test_the_lunch_break_stays_online(): void
    {
        $this->at('2026-10-27 13:30:00');

        $this->assertSame('online', $this->dot('rana', 'lina')['tone']);
    }

    public function test_outside_hours_is_told_only_to_who_may_see_the_absence(): void
    {
        $this->at('2026-10-27 17:00:00');

        $this->assertSame(['tone' => 'away', 'label' => 'Outside working hours'], $this->dot('sami', 'lina'));
        $this->assertSame(['tone' => 'away', 'label' => 'Offline'], $this->dot('rana', 'lina'));

        $this->at('2026-10-27 08:59:00');
        $this->assertSame('Outside working hours', $this->dot('sami', 'lina')['label']);
    }

    public function test_a_day_outside_the_day_range_and_an_official_holiday_are_outside_hours(): void
    {
        $this->at('2026-10-30 10:00:00'); // Friday
        $this->assertSame(['tone' => 'away', 'label' => 'Outside working hours'], $this->dot('sami', 'lina'));

        Holiday::query()->forceCreate([
            'name_en' => 'National day', 'name_ar' => 'عيد', 'start_date' => '2026-10-28', 'end_date' => '2026-10-28',
            'is_recurring' => false, 'scope' => Holiday::SCOPE_NATIONAL,
        ]);
        $this->at('2026-10-28 10:00:00');
        $this->assertSame(['tone' => 'away', 'label' => 'Outside working hours'], $this->dot('sami', 'lina'));
        $this->assertSame('Offline', $this->dot('rana', 'lina')['label']);
    }

    public function test_an_accepted_leave_in_working_hours_reads_on_leave_or_offline(): void
    {
        $this->leave('lina', '2026-10-26 00:00:00', '2026-10-28 00:00:00');

        $this->assertSame(['tone' => 'away', 'label' => 'On leave'], $this->dot('sami', 'lina'));
        $this->assertSame(['tone' => 'away', 'label' => 'Offline'], $this->dot('rana', 'lina'));
        $this->assertSame('On leave', $this->dot('lina', 'lina')['label'], 'One always sees one\'s own reason.');

        // After hours the hours win: the same leave, read in the evening.
        $this->at('2026-10-27 18:00:00');
        $this->assertSame('Outside working hours', $this->dot('sami', 'lina')['label']);
    }

    public function test_only_an_accepted_leave_counts(): void
    {
        $this->leave('lina', '2026-10-27 00:00:00', '2026-10-27 00:00:00', LeaveRequestStatus::Pending);
        $this->leave('lina', '2026-10-27 00:00:00', '2026-10-27 00:00:00', LeaveRequestStatus::Rejected);

        $this->assertSame('online', $this->dot('sami', 'lina')['tone']);
    }

    public function test_an_hourly_leave_makes_them_offline_during_its_hours_only(): void
    {
        $this->leave('lina', '2026-10-27 11:00:00', '2026-10-27 12:00:00', type: 'hour');

        $this->assertSame('online', $this->dot('sami', 'lina')['tone']);

        $this->at('2026-10-27 11:30:00');
        $this->assertSame(['tone' => 'away', 'label' => 'On leave'], $this->dot('sami', 'lina'));

        $this->at('2026-10-27 12:00:00');
        $this->assertSame('online', $this->dot('sami', 'lina')['tone']);
    }

    public function test_a_flexible_contract_is_silver_for_everyone_and_hollow_on_leave(): void
    {
        $this->p['lina']['contract']->update(['schedule_mode' => ScheduleMode::Flexible]);

        $this->at('2026-10-27 22:00:00');
        $this->assertSame(['tone' => 'flexible', 'label' => 'Variable working hours'], $this->dot('sami', 'lina'));
        $this->assertSame(['tone' => 'flexible', 'label' => 'Variable working hours'], $this->dot('rana', 'lina'));

        $this->leave('lina', '2026-10-27 00:00:00', '2026-10-27 00:00:00');
        $this->assertSame(['tone' => 'away', 'label' => 'On leave'], $this->dot('sami', 'lina'));
        $this->assertSame(['tone' => 'away', 'label' => 'Offline'], $this->dot('rana', 'lina'));
    }

    public function test_no_running_contract_an_inactive_person_or_unreadable_hours_get_no_dot(): void
    {
        $this->assertNull($this->dot('sami', 'sami'), 'Sami\'s contract has no hours at all: no guess.');

        $this->p['lina']['contract']->update(['end_date' => '2026-10-26']);
        $this->assertNull($this->dot('sami', 'lina'));

        $this->p['lina']['contract']->update(['end_date' => '2026-12-31']);
        $this->p['lina']['employee']->update(['is_active' => false]);
        $this->assertNull($this->dot('sami', 'lina'));
    }

    public function test_the_numeric_day_range_wins_over_the_text(): void
    {
        // Monday (1) to Friday (5): Friday is a working day now.
        $this->p['lina']['contract']->update(['work_day_from' => 1, 'work_day_to' => 5]);
        $this->at('2026-10-30 10:00:00');

        $this->assertSame('online', $this->dot('sami', 'lina')['tone']);
    }

    public function test_the_day_text_is_read_for_both_phrasings_and_nothing_else(): void
    {
        $this->assertSame([1, 5], WorkdayClock::dayRangeFromText('From Monday Till Friday'));
        $this->assertSame([6, 4], WorkdayClock::dayRangeFromText('From Saturday Till Thursday'));
        $this->assertNull(WorkdayClock::dayRangeFromText('Monday, Wednesday'));
        $this->assertNull(WorkdayClock::dayRangeFromText(null));

        // The clock tile still says nothing without the numeric range.
        $this->assertNull(app(WorkdayClock::class)->today($this->p['lina']['contract']->fresh()));
    }

    public function test_a_viewer_without_the_team_calendar_permission_reads_offline(): void
    {
        $this->at('2026-10-27 18:00:00');
        $manager = $this->makeUser($this->cities['HMS'], 'manager-no-calendar', [], $this->makeEmployee($this->cities['HMS'], 'deputy'));
        // Holds Sami's position too, so only the missing permission stands in the way.
        Contract::query()->create([
            'employee_id' => $manager->employee_id, 'position_id' => $this->p['sami']['position']->id, 'contract_type_id' => $this->contractType(),
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);

        $this->assertSame('Offline', app(WorkPresence::class)->forOne($manager, $this->p['lina']['employee'])['label']);
    }

    public function test_a_list_costs_the_same_queries_for_one_person_as_for_many(): void
    {
        $service = app(WorkPresence::class);
        $viewer = $this->p['sami']['user'];
        $service->for($viewer, [$this->p['lina']['employee']->id]); // warm the viewer's own context

        DB::enableQueryLog();
        app(WorkPresence::class)->for($viewer, [$this->p['lina']['employee']->id]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(1, 4) as $i) {
            $this->person("extra{$i}", 'HMS', 'A', 'Tchr', $this->p['sami']['position']->id, [
                'work_days_en' => 'From Sunday Till Thursday', 'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00',
            ]);
        }
        DB::flushQueryLog();

        app(WorkPresence::class)->for($viewer, collect($this->p)->pluck('employee.id'));
        $many = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($one + 1, $many, "One person: {$one} queries; six: {$many}.");
    }

    public function test_the_dot_renders_its_words_as_title_and_name_and_nothing_for_null(): void
    {
        $html = Blade::render('<x-presence :status="$s" on-avatar />', ['s' => ['tone' => 'away', 'label' => 'Offline']]);

        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Offline"', $html);
        $this->assertStringContainsString('title="Offline"', $html);
        $this->assertStringContainsString('presence-dot--away', $html);
        $this->assertStringContainsString('presence-dot--on-avatar', $html);
        $this->assertSame('', trim(Blade::render('<x-presence :status="null" />')));
    }

    public function test_arabic_words(): void
    {
        app()->setLocale('ar');
        $this->at('2026-10-27 18:00:00');

        $this->assertSame('خارج أوقات الدوام', $this->dot('sami', 'lina')['label']);
        $this->assertSame('أوفلاين', $this->dot('rana', 'lina')['label']);

        $this->at('2026-10-27 10:00:00');
        $this->assertSame('أونلاين', $this->dot('rana', 'lina')['label']);
    }

    public function test_the_profile_shows_the_dot(): void
    {
        $this->actingAs($this->p['sami']['user']);

        Livewire::test(EmployeeProfile::class, ['employee' => $this->p['lina']['employee']])
            ->assertSeeHtml('data-presence="online"')
            ->assertSeeHtml('aria-label="Online"');
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function at(string $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    /** @return array{tone: string, label: string}|null what $viewer sees beside $who */
    private function dot(string $viewer, string $who): ?array
    {
        return app(WorkPresence::class)->forOne($this->p[$viewer]['user'], $this->p[$who]['employee']);
    }

    private function person(string $key, string $city, string $center, string $department, ?int $managerPositionId, array $schedule = []): void
    {
        $department = Department::query()->firstOrCreate(['code' => $department], ['name' => $department]);
        $employee = $this->makeEmployee($this->cities[$city], $key, ['first_name_en' => ucfirst($key), 'last_name_en' => 'Test']);
        $position = Position::query()->forceCreate([
            'center_id' => $this->centers[$center]->id, 'city_id' => $this->cities[$city]->id,
            'department_id' => $department->id, 'job_level_id' => $this->level->id, 'program_id' => $this->program->id,
            'direct_manager_id' => $managerPositionId, 'title_en' => "{$key} position", 'title_ar' => "منصب {$key}",
            'max_emp' => 10, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => $this->contractType(),
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1,
            'weekly_hours' => 40, 'schedule_mode' => ScheduleMode::Fixed, 'is_active' => true, 'auto_renew' => false,
            ...$schedule,
        ]);
        $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 112, 'used' => 0]);
        $user = $this->makeUser($this->cities[$city], 'employee', ['leaves.view-own', 'leaves.create'], $employee);

        $this->p[$key] = compact('employee', 'position', 'contract', 'balance', 'user');
    }

    private function leave(string $who, string $start, string $end, int $status = LeaveRequestStatus::Accepted, string $type = 'day'): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $this->p[$who]['balance']->id, 'type' => $type,
            'start_at' => $start, 'end_at' => $end, 'reason' => 'x', 'status' => $status,
        ]);
    }

    private function contractType(): int
    {
        return ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id;
    }
}
