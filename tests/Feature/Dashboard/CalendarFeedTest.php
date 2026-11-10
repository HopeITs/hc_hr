<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\News;
use App\Models\Position;
use App\Models\User;
use App\Services\Dashboard\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * CalendarFeed (prompt 6): every event type behind its page's permission and
 * city scope, and one read per type whatever the number of events.
 */
class CalendarFeedTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $dms;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles are recreated per test under the same ids; Spatie's cache must not carry grants over.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        $this->dms = $this->makeCity('DMS', '003');
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_the_grid_is_six_weeks_from_the_monday_before_the_first(): void
    {
        [$from, $to] = CalendarFeed::gridOf(CarbonImmutable::parse('2026-11-15'));

        $this->assertSame(['2026-10-26', '2026-12-06'], [$from->toDateString(), $to->toDateString()]);
    }

    public function test_an_employee_without_a_team_never_sees_others_leave(): void
    {
        $employee = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $position = $this->makePosition($this->dms);
        $this->makeContractFor($employee->profile, ['position_id' => $position->id]);

        // A colleague on the same position's department and center, and someone in another city.
        $colleague = $this->makeEmployee($this->dms, 'colleague', ['first_name_en' => 'Colleague']);
        $this->leave($this->makeContractFor($colleague, ['position_id' => $this->makePosition($this->dms, ['center_id' => $position->center_id])->id]), '2026-11-04', '2026-11-05');
        $other = $this->makeEmployee($this->alp, 'faraway', ['first_name_en' => 'Faraway']);
        $this->leave($this->makeContractFor($other), '2026-11-04', '2026-11-05');

        $days = $this->feed()->forMonth($employee->fresh(), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame([], $this->ofType($days, 'team-leave'));
        $this->assertStringNotContainsString('Colleague', json_encode($days));
        $this->assertStringNotContainsString('Faraway', json_encode($days));
    }

    public function test_a_manager_sees_direct_reports_leave_including_pending(): void
    {
        $manager = $this->makeUser($this->dms, 'manager', ['leaves.view-own']);
        $managerPosition = $this->makePosition($this->dms);
        $this->makeContractFor($manager->profile, ['position_id' => $managerPosition->id]);

        $report = $this->makeEmployee($this->dms, 'report', ['first_name_en' => 'Lina']);
        $contract = $this->makeContractFor($report, ['position_id' => $this->makePosition($this->dms, ['direct_manager_id' => $managerPosition->id])->id]);
        $this->leave($contract, '2026-11-04', '2026-11-04');
        $this->leave($contract, '2026-11-10', '2026-11-10', LeaveRequestStatus::Pending);
        $this->leave($contract, '2026-11-12', '2026-11-12', LeaveRequestStatus::Rejected);

        $days = $this->feed()->forMonth($manager->fresh(), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(['2026-11-04', '2026-11-10'], array_keys($this->ofType($days, 'team-leave')));
        $this->assertSame('Lina Person', $days['2026-11-04'][0]['title']);
        $this->assertStringContainsString(__('dashboard_calendar.status.pending'), $days['2026-11-10'][0]['detail']);
    }

    public function test_my_leaves_are_accepted_and_pending_only(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $contract = $this->makeContractFor($user->profile);
        $this->leave($contract, '2026-11-03', '2026-11-04');
        $this->leave($contract, '2026-11-09', '2026-11-09', LeaveRequestStatus::Pending);
        $this->leave($contract, '2026-11-16', '2026-11-16', LeaveRequestStatus::Rejected);

        $days = $this->feed()->forMonth($user->fresh(), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(['2026-11-03', '2026-11-04', '2026-11-09'], array_keys($this->ofType($days, 'my-leave')));
    }

    public function test_my_leaves_need_the_my_leaves_permission(): void
    {
        $user = $this->makeUser($this->dms, 'employee', ['leaves.view-own']);
        $this->leave($this->makeContractFor($user->profile), '2026-11-03', '2026-11-04');
        $user->roles->first()->revokePermissionTo('leaves.view-own');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $feed = $this->feed();

        $this->assertNotContains('my-leave', $feed->typesFor($user->fresh()));
        $this->assertSame([], $this->ofType($feed->forMonth($user->fresh(), CarbonImmutable::parse('2026-11-01')), 'my-leave'));
    }

    public function test_city_hr_never_sees_another_citys_contract_ends(): void
    {
        $hr = $this->makeUser($this->dms, 'HR-DMS', ['contracts.view']);
        $this->makeContractFor($this->makeEmployee($this->dms, 'ends-dms', ['first_name_en' => 'Damascene']), ['end_date' => '2026-11-20']);
        $this->makeContractFor($this->makeEmployee($this->alp, 'ends-alp', ['first_name_en' => 'Aleppine']), ['end_date' => '2026-11-20']);

        $ends = $this->ofType($this->feed()->forMonth($hr->fresh(), CarbonImmutable::parse('2026-11-01')), 'contract-end');

        $this->assertSame(['2026-11-20'], array_keys($ends));
        $this->assertSame(['Damascene Person'], array_column($ends['2026-11-20'], 'title'));
    }

    public function test_contract_ends_need_an_hr_role_with_contracts_view(): void
    {
        $this->makeContractFor($this->makeEmployee($this->dms, 'ends-dms'), ['end_date' => '2026-11-20']);
        $hr = $this->makeUser($this->dms, 'HR-DMS', ['contracts.view']);
        $manager = $this->makeUser($this->dms, 'manager', ['contracts.view']);

        $role = $hr->roles->first();
        $role->revokePermissionTo(Permission::findByName('contracts.view', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $feed = $this->feed();

        $this->assertNotContains('contract-end', $feed->typesFor($hr->fresh()));
        $this->assertNotContains('contract-end', $feed->typesFor($manager->fresh()));
    }

    public function test_birthdays_show_day_and_month_only_and_follow_the_private_tier(): void
    {
        $this->makeEmployee($this->dms, 'bday-dms', ['first_name_en' => 'Damascene', 'actual_dob' => '1990-11-10']);
        $this->makeEmployee($this->alp, 'bday-alp', ['first_name_en' => 'Aleppine', 'actual_dob' => '1991-11-11']);
        $hr = $this->makeUser($this->dms, 'HR-DMS');
        $employee = $this->makeUser($this->dms, 'employee', [], $this->makeEmployee($this->dms, 'me', ['actual_dob' => '1995-11-12']));
        // UserFactory also builds a throwaway employee with a random birthday; only ours count here.
        Employee::query()->whereNotIn('username', ['bday-dms', 'bday-alp', 'me'])->update(['actual_dob' => null]);

        $forHr = $this->ofType($this->feed()->forMonth($hr->fresh(), CarbonImmutable::parse('2026-11-01')), 'birthday');
        $forEmployee = $this->ofType($this->feed()->forMonth($employee->fresh(), CarbonImmutable::parse('2026-11-01')), 'birthday');

        // HR: its own city's people (the employee "me" included); never Aleppo.
        $this->assertSame(['2026-11-10', '2026-11-12'], array_keys($forHr));
        $this->assertSame('Damascene Person', $forHr['2026-11-10'][0]['title']);
        // An employee: only their own.
        $this->assertSame(['2026-11-12'], array_keys($forEmployee));
        $this->assertSame(__('dashboard_calendar.your-birthday'), $forEmployee['2026-11-12'][0]['title']);
        // No year of birth and no age anywhere in what reaches the tile.
        $this->assertStringNotContainsString('1990', json_encode($forHr));
        $this->assertStringNotContainsString('1995', json_encode($forEmployee));
    }

    public function test_holidays_follow_the_viewers_contract(): void
    {
        Holiday::query()->create(['name_en' => 'National Day', 'name_ar' => 'العيد الوطني', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL]);
        $withContract = $this->makeUser($this->dms, 'employee');
        $this->makeContractFor($withContract->profile);
        $withoutContract = $this->makeUser($this->dms, 'employee');

        $holidays = $this->ofType($this->feed()->forMonth($withContract->fresh(), CarbonImmutable::parse('2026-11-01')), 'holiday');

        $this->assertSame('National Day', $holidays['2026-11-18'][0]['title']);
        $this->assertSame([], $this->ofType($this->feed()->forMonth($withoutContract->fresh(), CarbonImmutable::parse('2026-11-01')), 'holiday'));
    }

    public function test_news_events_reach_their_audience_only(): void
    {
        $user = $this->makeUser($this->dms, 'employee');
        $this->makeContractFor($user->profile);
        $this->news('For everyone', '2026-11-05');
        $this->news('For Damascus', '2026-11-06', City::class, $this->dms->id);
        $this->news('For Aleppo', '2026-11-07', City::class, $this->alp->id);

        $news = $this->ofType($this->feed()->forMonth($user->fresh(), CarbonImmutable::parse('2026-11-01')), 'news');

        $this->assertSame(['2026-11-05', '2026-11-06'], array_keys($news));
    }

    public function test_there_are_no_interviews_without_a_recruitment_module(): void
    {
        // Recruitment does not exist on this branch, so no role is ever given interviews.
        $this->assertNotContains('interview', CalendarFeed::TYPES);
    }

    public function test_the_query_count_for_a_month_does_not_grow_with_the_events(): void
    {
        $viewer = $this->makeUser($this->dms, 'HR-DMS', ['leaves.view-own', 'contracts.view']);
        $managerPosition = $this->makePosition($this->dms);
        $own = $this->makeContractFor($viewer->profile, ['position_id' => $managerPosition->id]);
        $report = $this->makeContractFor($this->makeEmployee($this->dms, 'report'), ['position_id' => $this->makePosition($this->dms, ['direct_manager_id' => $managerPosition->id])->id]);

        $this->seedEvents(1, $own, $report);
        $this->countQueries($viewer); // warm caches (permissions, roles)
        $few = $this->countQueries($viewer);
        $fewEvents = $this->eventCount($viewer);

        $this->seedEvents(6, $own, $report);
        $many = $this->countQueries($viewer);

        $this->assertGreaterThan($fewEvents, $this->eventCount($viewer));
        $this->assertSame($few, $many);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function feed(): CalendarFeed
    {
        return app(CalendarFeed::class);
    }

    private function countQueries(User $viewer): int
    {
        $fresh = User::query()->find($viewer->id);
        $feed = $this->feed();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $feed->forMonth($fresh, CarbonImmutable::parse('2026-11-01'));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function eventCount(User $viewer): int
    {
        return array_sum(array_map('count', $this->feed()->forMonth(User::query()->find($viewer->id), CarbonImmutable::parse('2026-11-01'))));
    }

    private function seedEvents(int $n, Contract $own, Contract $report): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $day = sprintf('2026-11-%02d', 3 + $seq);
            $this->leave($own, $day, $day);
            $this->leave($report, $day, $day);
            $this->makeEmployee($this->dms, 'bday-'.$seq, ['actual_dob' => '1990-'.substr($day, 5)]);
            $this->makeContractFor($this->makeEmployee($this->dms, 'end-'.$seq), ['end_date' => $day]);
            $this->news('Event '.$seq, $day);
            Holiday::query()->create(['name_en' => 'H'.$seq, 'name_ar' => 'ع'.$seq, 'start_date' => $day, 'end_date' => $day, 'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_OFFICIAL]);
        }
    }

    private function leave(Contract $contract, string $from, string $to, int $status = LeaveRequestStatus::Accepted): LeaveRequest
    {
        $type = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'سنوية', 'base_days' => 20]);
        $balance = LeaveBalance::query()->firstOrCreate(['contract_id' => $contract->id, 'type_id' => $type->id], ['deserved' => 160, 'used' => 0]);

        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $from,
            'end_at' => $to,
            'status' => $status,
        ]);
    }

    private function news(string $title, string $date, ?string $type = null, ?int $id = null): News
    {
        return News::query()->forceCreate([
            'title_en' => $title,
            'title_ar' => $title,
            'description_en' => '',
            'description_ar' => '',
            'event_date' => $date,
            'published_at' => now(),
            'newsable_type' => $type,
            'newsable_id' => $id,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> only $type's events, by date */
    private function ofType(array $days, string $type): array
    {
        $out = [];

        foreach ($days as $date => $events) {
            $matching = array_values(array_filter($events, fn (array $e) => $e['type'] === $type));

            if ($matching !== []) {
                $out[$date] = $matching;
            }
        }

        return $out;
    }
}
