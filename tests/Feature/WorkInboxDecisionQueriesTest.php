<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Dashboard\ForYouNow;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\LeaveDecisionAuthority;
use App\Services\WorkInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The work inbox decides every pending request through one
 * LeaveDecisionAuthority::forOnePass(): who holds a manager position, who of
 * them is on leave and the viewer's scope are read once per pass, so the
 * queries no longer grow with the pending requests, and every decision is the
 * one the plain authority and the decide policy give. Synthetic data only.
 */
class WorkInboxDecisionQueriesTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    /** @var array<string, City> */
    private array $cities = [];

    /** @var array<string, Center> */
    private array $centers = [];

    /** @var array<string, Position> */
    private array $positions = [];

    private User $manager;

    private User $coManager;

    private User $hrHms;

    private User $hrAlp;

    private int $seq = 0;

    /** @var array{department: int, level: int, program: int}|null */
    private ?array $positionDefaults = null;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['HMS' => '002', 'ALP' => '001'] as $slug => $code) {
            $this->cities[$slug] = $this->makeCity($slug, $code);
            $this->centers[$slug] = Center::query()->create([
                'name_en' => "Center {$slug}", 'name_ar' => "مركز {$slug}", 'city_id' => $this->cities[$slug]->id,
                'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
            ]);
        }

        LeaveType::query()->forceCreate(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 14]);
        ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي']);

        // mgr: one holder (the manager). shared: two holders. team/shared-team/alp report to them; solo has no manager.
        $this->positions['mgr'] = $this->position('HMS', 'Manager');
        $this->positions['shared'] = $this->position('HMS', 'Shared manager');
        $this->positions['team'] = $this->position('HMS', 'Team', $this->positions['mgr']);
        $this->positions['shared-team'] = $this->position('HMS', 'Shared team', $this->positions['shared']);
        // Another city's employee whose direct manager is in Homs.
        $this->positions['alp'] = $this->position('ALP', 'Aleppo team', $this->positions['mgr']);
        $this->positions['solo'] = $this->position('HMS', 'Solo');
        $this->positions['hr'] = $this->position('HMS', 'HR');
        $this->positions['hr-alp'] = $this->position('ALP', 'HR Aleppo');

        $this->manager = $this->person('HMS', 'mgr', 'manager');
        $this->coManager = $this->person('HMS', 'shared', 'manager');
        $this->person('HMS', 'shared', 'employee'); // the second holder of "shared", always at work
        $this->hrHms = $this->person('HMS', 'hr', 'HR-HMS', ['leaves.decide', 'leaves.review', 'leaves.view-all']);
        $this->hrAlp = $this->person('ALP', 'hr-alp', 'HR-ALP', ['leaves.decide', 'leaves.review', 'leaves.view-all']);
    }

    public function test_the_inbox_queries_do_not_grow_with_pending_requests(): void
    {
        $this->seedRequests(2);

        $before = $this->measure();

        $this->seedRequests(10);

        $after = $this->measure();

        foreach (['manager', 'hr-hms'] as $who) {
            // The pass really saw the new requests...
            $this->assertGreaterThan($before[$who]['decide'], $after[$who]['decide'], "{$who} decide count");
            // ...and asked the same number of queries for them.
            $this->assertSame($before[$who]['snapshot'], $after[$who]['snapshot'], "{$who} snapshot queries");
            $this->assertSame($before[$who]['for-you'], $after[$who]['for-you'], "{$who} for-you queries");
        }

        $this->assertGreaterThan($before['hr-hms']['review'], $after['hr-hms']['review'], 'hr-hms review count');
    }

    public function test_every_decision_is_the_plain_authoritys_while_the_managers_are_at_work(): void
    {
        $this->seedRequests(2);
        $this->addEdgeRequests();

        $this->assertDecisionsUnchanged();

        // Spot checks of the matrix.
        $this->assertSame(LeaveDecisionAuthority::MANAGER, $this->basis($this->manager, 'alp'), 'the manager decides another city\'s report');
        $this->assertNull($this->basis($this->hrHms, 'team'), 'no escalation while the manager is at work');
        $this->assertNull($this->basis($this->hrAlp, 'alp'));
        $this->assertSame(LeaveDecisionAuthority::NO_MANAGER, $this->basis($this->hrHms, 'solo'));
        $this->assertNull($this->basis($this->hrAlp, 'solo'), 'out of HR-ALP\'s scope');
    }

    public function test_every_decision_is_the_plain_authoritys_while_a_manager_is_on_leave(): void
    {
        $this->seedRequests(2);
        $this->addEdgeRequests();
        $this->putOnLeaveToday($this->manager);
        $this->putOnLeaveToday($this->coManager);

        $this->assertDecisionsUnchanged();

        // The manager still decides; HR in scope stands in for them.
        $this->assertSame(LeaveDecisionAuthority::MANAGER, $this->basis($this->manager, 'team'));
        $this->assertSame(LeaveDecisionAuthority::ON_BEHALF, $this->basis($this->hrHms, 'team'));
        // Another city's report: its own city's HR stands in, never Homs HR.
        $this->assertSame(LeaveDecisionAuthority::ON_BEHALF, $this->basis($this->hrAlp, 'alp'));
        $this->assertNull($this->basis($this->hrHms, 'alp'));
        // One of two holders is on leave: the other is at work, so HR may not.
        $this->assertNull($this->basis($this->hrHms, 'shared-team'));
        $this->assertSame(LeaveDecisionAuthority::MANAGER, $this->basis($this->coManager, 'shared-team'));
    }

    /**
     * For every viewer and every pending request: the decide policy, the
     * plain authority, a one-pass authority shared by every viewer, and the
     * snapshot's decide rows all agree.
     */
    private function assertDecisionsUnchanged(): void
    {
        $viewers = ['manager' => $this->manager, 'co-manager' => $this->coManager, 'hr-hms' => $this->hrHms, 'hr-alp' => $this->hrAlp];
        $shared = LeaveDecisionAuthority::forOnePass();
        $pending = LeaveRequest::query()->where('status', LeaveRequestStatus::Pending)->get();
        $decided = 0;

        foreach ($viewers as $who => $viewer) {
            $viewer = $viewer->fresh();
            $expected = [];

            foreach ($pending as $leave) {
                $plain = app(LeaveDecisionAuthority::class)->basisFor($viewer, $leave->fresh());
                $this->assertSame($plain, $shared->basisFor($viewer, $leave->fresh()), "{$who} on request {$leave->id}");
                $this->assertSame($plain !== null, Gate::forUser($viewer)->allows('decide', $leave->fresh()));
                $this->assertSame($plain !== null, Gate::forUser($viewer)->allows('decide', [$leave->fresh(), $shared]));

                if ($plain !== null) {
                    $expected[] = "leave:{$leave->id}:decide";
                }
            }

            $snapshot = app(WorkInbox::class)->snapshot($viewer->fresh());
            $listed = $snapshot['rows']->where('action', 'decide')->pluck('key')->all();

            $this->assertEqualsCanonicalizing($expected, $listed, "{$who} decide rows");
            $this->assertSame(count($expected), $snapshot['counts']['decide']);
            $decided += count($expected);
        }

        $this->assertGreaterThan(0, $decided);
    }

    private function basis(User $viewer, string $position): ?string
    {
        $leave = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->whereHas('leaveBalance.contract', fn ($contract) => $contract->where('position_id', $this->positions[$position]->id))
            ->firstOrFail();

        return app(LeaveDecisionAuthority::class)->basisFor($viewer->fresh(), $leave);
    }

    /** @return array<string, array{snapshot: int, for-you: int, decide: int, review: int}> */
    private function measure(): array
    {
        $result = [];

        foreach (['manager' => $this->manager, 'hr-hms' => $this->hrHms] as $who => $viewer) {
            $viewer = $viewer->fresh();
            app(WorkInbox::class)->snapshot($viewer); // warm the user's role and permission memo

            DB::flushQueryLog();
            DB::enableQueryLog();
            $snapshot = app(WorkInbox::class)->snapshot($viewer);
            $snapshotQueries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->actingAs($viewer->fresh());
            Livewire::test(ForYouNow::class); // warm
            $this->actingAs($viewer->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ForYouNow::class);
            $forYouQueries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $result[$who] = [
                'snapshot' => $snapshotQueries,
                'for-you' => $forYouQueries,
                'decide' => $snapshot['counts']['decide'],
                'review' => $snapshot['counts']['review'],
            ];
        }

        return $result;
    }

    /** $n pending requests (and one decided, not yet seen by HR) for each kind of requester. */
    private function seedRequests(int $n): void
    {
        foreach (range(1, $n) as $_) {
            foreach (['team' => 'HMS', 'shared-team' => 'HMS', 'alp' => 'ALP', 'solo' => 'HMS'] as $position => $city) {
                $balance = $this->balanceOf($this->employeeOn($city, $position));
                $this->request($balance, LeaveRequestStatus::Pending, today()->addWeek());
                $this->request($balance, LeaveRequestStatus::Accepted, today()->addWeeks(2));
            }
        }
    }

    /** HR's own request, and a former employee's: nobody decides either. */
    private function addEdgeRequests(): void
    {
        $this->request($this->balanceOf(Employee::query()->findOrFail($this->hrHms->employee_id)), LeaveRequestStatus::Pending, today()->addWeek());

        $former = $this->employeeOn('HMS', 'solo');
        $this->request($this->balanceOf($former), LeaveRequestStatus::Pending, today()->addWeek());
        $former->update(['is_active' => false]);
    }

    private function putOnLeaveToday(User $user): void
    {
        $this->request($this->balanceOf(Employee::query()->findOrFail($user->employee_id)), LeaveRequestStatus::Accepted, today()->subDay(), today()->addDay());
    }

    private function position(string $city, string $title, ?Position $manager = null): Position
    {
        $shared = $this->positionDefaults ??= [
            'department' => Department::query()->firstOrCreate(['code' => 'OPS'], ['name' => 'OPS'])->id,
            'level' => JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'فريق', 'grade' => 6])->id,
            'program' => Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true])->id,
        ];

        $position = Position::query()->forceCreate([
            'center_id' => $this->centers[$city]->id,
            'city_id' => $this->cities[$city]->id,
            'department_id' => $shared['department'],
            'job_level_id' => $shared['level'],
            'program_id' => $shared['program'],
            'direct_manager_id' => $manager?->id,
            'title_en' => $title, 'title_ar' => $title,
            'max_emp' => 500, 'exist_emp' => 0, 'is_active' => true,
        ]);

        return $position;
    }

    private function employeeOn(string $city, string $position): Employee
    {
        $employee = $this->makeEmployee($this->cities[$city], 'person-'.(++$this->seq).'-'.uniqid());

        Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $this->positions[$position]->id, 'contract_type_id' => 1,
            'start_date' => today()->subMonths(6)->toDateString(), 'end_date' => today()->addMonths(6)->toDateString(),
            'currency' => 2, 'salary_per' => 1, 'salary' => 1000, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);

        return $employee;
    }

    private function person(string $city, string $position, string $role, array $permissions = []): User
    {
        return $this->makeUser($this->cities[$city], $role, $permissions, $this->employeeOn($city, $position));
    }

    private function balanceOf(Employee $employee): LeaveBalance
    {
        $contract = Contract::query()->where('employee_id', $employee->id)->firstOrFail();

        return LeaveBalance::query()->firstOrCreate(
            ['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual],
            ['deserved' => 112, 'used' => 0],
        );
    }

    private function request(LeaveBalance $balance, int $status, $start, $end = null): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $balance->id, 'type' => 'day', 'reason' => 'private', 'status' => $status,
            'start_at' => $start->copy()->startOfDay(), 'end_at' => ($end ?? $start)->copy()->startOfDay(),
        ]);
    }
}
