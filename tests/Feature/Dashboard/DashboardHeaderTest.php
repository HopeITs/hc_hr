<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Contract\ContractsTable;
use App\Livewire\Dashboard\DecisionsCard;
use App\Livewire\Dashboard\Header;
use App\Livewire\Dashboard\LateCard;
use App\Livewire\Inbox\Index as Inbox;
use App\Models\Center;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Prompt 7: the top of the dashboard (the headline) and what were its
 * late strip and decisions, tiles now (LateCard, DecisionsCard). Aleppo: manager Sami with reports Lina
 * and Omar, HR-ALP Huda. Damascus: Dana with her own manager, outside
 * Aleppo's scope. Today is Tuesday 27 October 2026.
 */
class DashboardHeaderTest extends TestCase
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

    public function test_the_headline_says_what_waits_in_three_states(): void
    {
        // 1. Decisions: the inbox's own decide count.
        $this->leave('lina', '2026-11-03', LeaveRequestStatus::Pending);
        $this->leave('omar', '2026-11-10', LeaveRequestStatus::Pending);
        $this->assertSame(2, Livewire::actingAs($this->user('sami'))->test(Inbox::class)->viewData('counts')['decide']);
        $this->header('sami')->assertSee('2 leave requests need your decision');

        // 2. Only items to confirm: the inbox count, as on the badge.
        $item = ChecklistItem::query()->firstOrCreate(['name' => 'Laptop'], ['order' => 1]);
        Checklist::query()->create(['item_id' => $item->id, 'employee_id' => $this->p['omar']['employee']->id, 'delivered_id' => $this->p['lina']['employee']->id, 'delivered_status' => 'pending']);
        $this->header('lina')->assertSee('1 item in your inbox')
            ->assertDontSeeHtml('data-testid="decision-card"');

        // 3. Nothing at all.
        $this->header('omar')->assertSee('Nothing is waiting on you today')
            ->assertDontSeeHtml('data-testid="late-band"')
            ->assertDontSeeHtml('data-testid="decision-card"')
            // The greeting is the small line above.
            ->assertSee('Hello Omar,');
    }

    public function test_arabic_counts_use_all_six_plural_forms(): void
    {
        app()->setLocale('ar');

        $forms = array_map(fn (int $n) => trans_choice('dashboard.header.headline.leave', $n, ['count' => $n]), [0, 1, 2, 3, 11, 100]);

        $this->assertCount(6, array_unique($forms));
        $this->assertSame('طلبا إجازة بانتظار قرارك', $forms[2]);
        $this->assertSame('11 طلبَ إجازة بانتظار قرارك', $forms[4]);
    }

    public function test_the_late_band_appears_for_a_started_leave_and_goes_once_it_is_decided(): void
    {
        $this->leave('omar', '2026-11-10', LeaveRequestStatus::Pending);
        $this->lateTile('sami')->assertDontSeeHtml('data-testid="late-band"');

        // Started yesterday, still no decision: late.
        $late = $this->leave('lina', '2026-10-26', LeaveRequestStatus::Pending);
        $this->lateTile('sami')
            ->assertSeeHtml('data-testid="late-band"')
            ->assertSeeHtml('role="alert"')
            ->assertSee('1 leave started with no decision')
            ->assertSee('Lina Haddad')
            ->assertSeeHtml('href="'.route('inbox', ['open' => "leave:{$late->id}:decide"]).'"');

        // A leave starting today is not late yet.
        $late->forceFill(['start_at' => '2026-10-27 00:00:00'])->save();
        $this->lateTile('sami')->assertDontSeeHtml('data-testid="late-band"');

        $late->forceFill(['start_at' => '2026-10-26 00:00:00', 'status' => LeaveRequestStatus::Accepted])->save();
        $this->lateTile('sami')->assertDontSeeHtml('data-testid="late-band"');
    }

    public function test_a_contract_that_ended_without_renewal_is_late_until_it_is_renewed(): void
    {
        $this->lateTile('huda')->assertDontSeeHtml('data-testid="late-band"');

        // Rami's contract ended on Friday; Dana's too, but she is in Damascus.
        $this->p['rami']['contract']->forceFill(['end_date' => '2026-10-23', 'is_active' => false])->save();
        $this->p['dana']['contract']->forceFill(['end_date' => '2026-10-23', 'is_active' => false])->save();

        $huda = $this->user('huda');
        $this->assertSame(1, Contract::query()->visibleTo($huda)->inView('not-renewed', 30)->count());
        $this->lateTile('huda')
            ->assertSeeHtml('data-testid="late-band"')
            ->assertSee('1 contract ended without renewal')
            ->assertSee('Rami Ending')
            ->assertDontSee('Dana Damascus')
            ->assertSeeHtml('href="'.route('contracts.index', ['view' => 'not-renewed']).'"');
        // Nothing in the inbox, so the headline says what is late.
        $this->header('huda')->assertSee('1 thing is late')->assertDontSeeHtml('data-testid="late-band"');

        // The page it opens lists the same contract, and only it.
        Livewire::actingAs($huda)->withQueryParams(['view' => 'not-renewed'])->test(ContractsTable::class)
            ->assertSee('Rami')->assertDontSee('Dana')->assertDontSee('Lina Haddad');

        // A renewal was made: no longer late.
        Contract::query()->create([
            'employee_id' => $this->p['rami']['employee']->id, 'position_id' => $this->p['rami']['position']->id, 'contract_type_id' => 1,
            'start_date' => '2026-10-24', 'end_date' => '2027-10-23', 'currency' => 2, 'salary_per' => 1, 'salary' => 987654,
            'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);
        $this->lateTile('huda')->assertDontSeeHtml('data-testid="late-band"');
    }

    public function test_decisions_are_shown_oldest_first_and_only_in_scope(): void
    {
        $newer = $this->leave('omar', '2026-11-10', LeaveRequestStatus::Pending, '2026-10-26 09:00:00');
        $oldest = $this->leave('lina', '2026-11-20', LeaveRequestStatus::Pending, '2026-10-20 09:00:00');
        $middle = $this->leave('lina', '2026-11-03', LeaveRequestStatus::Pending, '2026-10-24 09:00:00');
        // Damascus: Dana's manager decides it, never Sami.
        $this->leave('dana', '2026-10-25', LeaveRequestStatus::Pending, '2026-10-01 09:00:00');

        $html = $this->decisionsTile('sami')
            ->assertSeeHtml('aria-roledescription="carousel"')
            ->assertSeeHtml('aria-roledescription="slide"')
            ->assertSee('3 decisions wait on you')
            ->assertSee('Review leave')
            ->assertDontSee('Dana')
            ->assertDontSee('987654')
            ->assertDontSee('private')
            ->html();

        preg_match_all('/data-decision="([^"]+)"/', $html, $keys);
        $this->assertSame(["leave:{$oldest->id}:decide", "leave:{$middle->id}:decide", "leave:{$newer->id}:decide"], $keys[1]);

        // Waiting a week: the card says so.
        $this->assertStringContainsString('Waiting 1 week', $html);
    }

    public function test_the_dashboard_draws_the_header_above_the_grid(): void
    {
        $this->leave('lina', '2026-11-03', LeaveRequestStatus::Pending);

        $html = $this->actingAs($this->user('sami'))->get(route('dashboard'))->assertOk()
            ->assertSee('1 leave request needs your decision')
            ->getContent();

        $this->assertLessThan(strpos($html, 'class="hc-home-screen"'), strpos($html, 'data-testid="dashboard-headline"'));
        // The job title is the secondary line.
        $this->assertStringContainsString('data-testid="dashboard-job-title"', $html);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function lateTile(string $who)
    {
        return Livewire::actingAs($this->user($who))->test(LateCard::class);
    }

    private function decisionsTile(string $who)
    {
        return Livewire::actingAs($this->user($who))->test(DecisionsCard::class);
    }

    private function header(string $who)
    {
        return Livewire::actingAs($this->user($who))->test(Header::class, ['name' => $this->p[$who]['employee']?->first_name ?? '']);
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

        $person = function (string $key, string $first, string $last, string $city, ?int $manager, ?string $role) use ($center, $department, $level, $program) {
            $employee = $this->makeEmployee($this->cities[$city], $key, ['first_name_en' => $first, 'last_name_en' => $last]);
            $position = Position::query()->forceCreate([
                'center_id' => $city === 'ALP' ? $center->id : null, 'city_id' => $this->cities[$city]->id,
                'department_id' => $department->id, 'job_level_id' => $level->id, 'program_id' => $program->id,
                'direct_manager_id' => $manager, 'title_en' => $first.' post', 'title_ar' => $first, 'max_emp' => 5, 'exist_emp' => 0, 'is_active' => true,
            ]);
            $contract = Contract::query()->create([
                'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => 1,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1, 'salary' => 987654,
                'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
            ]);
            $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => LeaveTypeCases::Annual, 'deserved' => 112, 'used' => 0]);
            $permissions = ['leaves.view-own', 'leaves.create', 'leaves.view-all', 'leaves.decide', 'checklists.view-own', 'checklists.deliver'];
            $user = $role ? $this->makeUser($this->cities[$city], $role, $permissions, $employee) : null;
            $this->p[$key] = compact('employee', 'position', 'contract', 'balance', 'user');
        };

        $person('sami', 'Sami', 'Khoury', 'ALP', null, 'manager');
        $person('lina', 'Lina', 'Haddad', 'ALP', $this->p['sami']['position']->id, 'employee');
        $person('omar', 'Omar', 'Hamdan', 'ALP', $this->p['sami']['position']->id, 'employee');
        $person('rami', 'Rami', 'Ending', 'ALP', null, null);
        $person('dms-manager', 'Fadi', 'Manager', 'DMS', null, 'manager');
        $person('dana', 'Dana', 'Damascus', 'DMS', $this->p['dms-manager']['position']->id, null);

        $huda = $this->makeUser($this->cities['ALP'], 'HR-ALP', ['contracts.view', 'employees.view', 'leaves.view-all']);
        $this->p['huda'] = ['user' => $huda, 'employee' => null];
    }

    private function leave(string $who, string $start, int $status, ?string $createdAt = null): LeaveRequest
    {
        $leave = LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $this->p[$who]['balance']->id,
            'type' => 'day',
            'start_at' => "{$start} 00:00:00",
            'end_at' => "{$start} 00:00:00",
            'reason' => 'private',
            'status' => $status,
        ]);

        if ($createdAt) {
            $leave->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $leave;
    }
}
