<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Dashboard\ForYouNow;
use App\Livewire\Employee\EmployeeTable;
use App\Livewire\Inbox\Index as Inbox;
use App\Models\Center;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * C4 "For you now" (interaction-contract.md §5). Aleppo: manager Sami with
 * reports Lina and Omar; HR-ALP Huda. Damascus: one more contract and one
 * awaiting volunteer that Aleppo HR must never count.
 */
class ForYouNowTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $p = [];

    private array $cities = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 10:00:00');
        app()->setLocale('en');
        $this->build();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** AC-C4-01: each role sees the C0 parts, in the C0 order, and its next action. */
    public function test_each_role_sees_its_parts_in_the_c0_order(): void
    {
        $this->leave('lina', '2026-10-27', '2026-10-27');
        $this->leave('omar', '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $this->leave('lina', '2026-11-10', '2026-11-10', LeaveRequestStatus::Pending);

        $this->assertSame(['decisions', 'team-off', 'balance'], $this->parts('sami'));
        $this->assertSame(['balance', 'my-requests'], $this->parts('lina'));
        $this->assertSame(['inbox', 'contracts-ending', 'employees-waiting'], $this->parts('huda'));

        // The manager's next action: the decision, opening the inbox's decide filter.
        // The requests themselves are the dashboard header's decision card (prompt 7), not listed twice.
        Livewire::actingAs($this->user('sami'))->test(ForYouNow::class)
            ->assertSee('2 leave requests are waiting for you')
            ->assertSeeHtml('href="'.route('inbox', ['show' => 'decide']).'"')
            ->assertDontSeeHtml('?open=leave');

        // The employee's next action: Request leave, and the step their request is at.
        Livewire::actingAs($this->user('lina'))->test(ForYouNow::class)
            ->assertSeeHtml('href="'.route('leaves.index', ['new' => 1]).'"')
            ->assertSee('Annual left:')
            // Plain text, not the old card's markup.
            ->assertDontSee('&lt;strong', false);
    }

    /** A part with nothing to show is left out; with nothing at all, one quiet line. */
    public function test_empty_parts_collapse(): void
    {
        $this->assertSame(['balance'], $this->parts('omar'));

        // super-admin: no work waiting, so only its settings shortcuts.
        $admin = $this->makeUser($this->cities['ALP'], 'super-admin');
        $this->assertSame(['admin-shortcuts'], $this->partsFor($admin));

        // An employee with no contract and nothing open: one quiet line.
        $newcomer = $this->makeUser($this->cities['ALP'], 'employee', ['leaves.view-own']);
        Livewire::actingAs($newcomer)->test(ForYouNow::class)->assertSeeHtml('data-testid="for-you-empty"');
    }

    /** AC-C4-03: every count is the page's own count, and links open that filtered page. */
    public function test_counts_match_the_pages_they_open_and_skip_inaccessible_work(): void
    {
        $this->leave('omar', '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $huda = $this->user('huda');
        $sami = $this->user('sami');

        // Decisions: the inbox page's own "decide" count.
        $inboxCount = Livewire::actingAs($sami)->test(Inbox::class)->viewData('counts')['decide'];
        $html = Livewire::actingAs($sami)->test(ForYouNow::class)->html();
        $this->assertSame(1, $inboxCount);
        $this->assertStringContainsString('1 leave request is waiting for you', $html);

        // Contracts ending: the contracts table's "ending-soon" view, Aleppo only.
        $component = Livewire::actingAs($huda)->test(ForYouNow::class);
        $expected = Contract::query()->visibleTo($huda)->inView('ending-soon', 30)->count();
        $this->assertSame(1, $expected);
        $component->assertSee('1 contract')
            ->assertSeeHtml('href="'.route('contracts.index', ['view' => 'ending-soon']).'"')
            // Awaiting: the employees table's B5 view, Aleppo only (the Damascus volunteer is not counted).
            ->assertSee('1 volunteer awaiting a contract')
            ->assertSeeHtml('href="'.route('employees.index', ['view' => 'awaiting']).'"');
    }

    /** AC-C4-02: switching role changes the parts and leaves nothing of the previous role. */
    public function test_switching_role_changes_parts_without_leaking(): void
    {
        $lina = $this->user('lina');
        $hrRole = Role::findOrCreate('HR-ALP', 'web');
        $lina->assignRole($hrRole);

        $this->assertSame(['balance'], $this->partsFor($lina->fresh()));

        $lina->fresh()->setActiveRole($hrRole->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $asHr = Livewire::actingAs($lina->fresh())->test(ForYouNow::class);
        $this->assertContains('employees-waiting', $this->partsIn($asHr->html()));

        $lina->fresh()->setActiveRole(Role::findByName('employee', 'web')->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $back = Livewire::actingAs($lina->fresh())->test(ForYouNow::class);
        $this->assertSame(['balance'], $this->partsIn($back->html()));
        $back->assertDontSee('awaiting a contract');

        // Nothing of either role is kept in the component's state.
        $this->assertSame([], $back->instance()->all());
    }

    /** Independent loading: one source failing does not hide the others. */
    public function test_a_failing_part_does_not_hide_the_others(): void
    {
        // The inbox's checklist source becomes unreadable (WorkInbox is final, so it is not mocked).
        Schema::drop('checklists');

        $component = Livewire::actingAs($this->user('huda'))->test(ForYouNow::class);

        $component->assertSeeHtml('data-part-failed="inbox"')
            ->assertSeeHtml('data-part="contracts-ending"');
    }

    /** No pay figure on the section for any role. */
    public function test_no_pay_is_shown(): void
    {
        foreach (['huda', 'sami', 'lina'] as $who) {
            Livewire::actingAs($this->user($who))->test(ForYouNow::class)->assertDontSee('987654');
        }
    }

    /**
     * G-C-5 (D-03): "starts soon" is today to today + 2. A request whose first
     * day is already behind is labelled "already started" and counted apart,
     * on the home block and on the inbox page alike.
     */
    public function test_an_already_started_request_is_not_counted_as_starting_soon(): void
    {
        $this->leave('omar', '2026-10-20', '2026-10-28', LeaveRequestStatus::Pending);
        $this->leave('lina', '2026-10-29', '2026-10-29', LeaveRequestStatus::Pending);
        $this->leave('lina', '2026-11-10', '2026-11-10', LeaveRequestStatus::Pending);
        $sami = $this->user('sami');

        Livewire::actingAs($sami)->test(ForYouNow::class)
            ->assertSee('3 leave requests are waiting for you')
            ->assertSeeHtml('data-testid="for-you-decisions-soon"')
            ->assertSee('1 starts within two days')
            ->assertSee('1 has already started');

        $html = Livewire::actingAs($sami)->test(Inbox::class)->html();
        $this->assertSame(1, substr_count($html, 'Starts soon'));
        $this->assertSame(1, substr_count($html, 'Already started'));
    }

    /**
     * G-C-10 (F7): "this week" is the rest of the calendar's Sunday–Saturday
     * week, the one its link opens. Today is Tuesday 27 October: Saturday the
     * 31st is in it, Sunday 1 November is next week.
     */
    public function test_team_off_this_week_is_the_week_its_link_opens(): void
    {
        $this->leave('lina', '2026-10-31', '2026-10-31');
        $this->leave('omar', '2026-11-01', '2026-11-01');

        Livewire::actingAs($this->user('sami'))->test(ForYouNow::class)
            ->assertSeeHtml('data-part="team-off"')
            ->assertSee('Lina Haddad')
            ->assertDontSee('Omar Hamdan')
            ->assertSeeHtml('href="'.e(route('team.calendar', ['scope' => 'reports', 'view' => 'week'])).'"');
    }

    /**
     * G-C-13 (F13): a regional HR role whose city does not exist sees nothing,
     * never every city, on the home figures and on the employees table.
     */
    public function test_an_hr_role_without_a_city_counts_and_lists_nobody(): void
    {
        $ghost = $this->makeUser($this->cities['ALP'], 'HR-XYZ', ['contracts.view', 'employees.view']);

        $this->assertSame([], $ghost->activeCityIds());
        $this->assertNotContains('employees-waiting', $this->partsFor($ghost));
        $this->assertNotContains('contracts-ending', $this->partsFor($ghost));

        // Aleppo HR lists its staff; the city-less role lists no one at all.
        Livewire::actingAs($this->user('huda'))->test(EmployeeTable::class)->assertSee('Lina Haddad');
        Livewire::actingAs($ghost)->test(EmployeeTable::class)
            ->assertDontSee(['Lina Haddad', 'Dana Damascus', 'Sami Khoury']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function parts(string $who): array
    {
        return $this->partsFor($this->user($who));
    }

    private function partsFor(User $user): array
    {
        return $this->partsIn(Livewire::actingAs($user)->test(ForYouNow::class)->html());
    }

    private function partsIn(string $html): array
    {
        preg_match_all('/data-part="([a-z-]+)"/', $html, $matches);

        return $matches[1];
    }

    private function user(string $who): User
    {
        return $this->p[$who]['user']->fresh();
    }

    private function build(): void
    {
        $this->cities = ['ALP' => $this->makeCity('ALP', '001'), 'DMS' => $this->makeCity('DMS', '003')];
        $center = Center::query()->create(['name_en' => 'Center', 'name_ar' => 'مركز', 'city_id' => $this->cities['ALP']->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']);
        $department = Department::query()->forceCreate(['name' => 'Teachers', 'code' => 'Tchr']);
        $level = JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'فريق', 'grade' => 6]);
        $program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'sz', 'code' => 'SZ', 'is_active' => true]);
        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual', 'name_ar' => 'سنوي']);
        LeaveType::query()->forceCreate(['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual', 'name_ar' => 'سنوية', 'base_days' => 14]);

        $person = function (string $key, string $first, string $last, string $city, ?int $manager, ?string $role, string $end = '2026-12-31') use ($center, $department, $level, $program) {
            $employee = $this->makeEmployee($this->cities[$city], $key, ['first_name_en' => $first, 'last_name_en' => $last]);
            $position = Position::query()->forceCreate([
                'center_id' => $city === 'ALP' ? $center->id : null, 'city_id' => $this->cities[$city]->id,
                'department_id' => $department->id, 'job_level_id' => $level->id, 'program_id' => $program->id,
                'direct_manager_id' => $manager, 'title_en' => $first, 'title_ar' => $first, 'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
            ]);
            $contract = Contract::query()->create([
                'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => 1,
                'start_date' => '2026-01-01', 'end_date' => $end, 'currency' => 2, 'salary_per' => 1, 'salary' => 987654,
                'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
            ]);
            $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 112, 'used' => 0]);
            $permissions = ['leaves.view-own', 'leaves.create', 'leaves.view-all', 'leaves.decide', 'checklists.view-own'];
            $user = $role ? $this->makeUser($this->cities[$city], $role, $permissions, $employee) : null;
            $this->p[$key] = compact('employee', 'position', 'contract', 'balance', 'user');
        };

        $person('sami', 'Sami', 'Khoury', 'ALP', null, 'manager');
        $person('lina', 'Lina', 'Haddad', 'ALP', $this->p['sami']['position']->id, 'employee');
        $person('omar', 'Omar', 'Hamdan', 'ALP', $this->p['sami']['position']->id, 'employee');
        // One Aleppo contract ends within 30 days; a Damascus one too, outside Aleppo HR's scope.
        $person('ending', 'Rami', 'Ending', 'ALP', null, null, '2026-11-15');
        $person('dms', 'Dana', 'Damascus', 'DMS', null, null, '2026-11-15');

        $huda = $this->makeUser($this->cities['ALP'], 'HR-ALP', ['contracts.view', 'employees.view', 'leaves.view-all', 'leaves.review', 'leaves.decide', 'employees.update']);
        $this->p['huda'] = ['user' => $huda];

        // Awaiting a first contract (B5): one in Aleppo, one in Damascus.
        foreach (['ALP', 'DMS'] as $city) {
            $this->makeEmployee($this->cities[$city], 'awaiting-'.$city, ['is_active' => false, 'record_status' => Employee::RECORD_COMPLETE]);
        }
    }

    private function leave(string $who, string $start, string $end, int $status = LeaveRequestStatus::Accepted): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $this->p[$who]['balance']->id,
            'type' => 'day',
            'start_at' => "{$start} 00:00:00",
            'end_at' => "{$end} 00:00:00",
            'reason' => 'private',
            'status' => $status,
        ]);
    }
}
