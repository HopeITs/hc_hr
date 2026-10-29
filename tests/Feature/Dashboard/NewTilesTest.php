<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Dashboard\BirthdaysCard;
use App\Livewire\Dashboard\LeaveBalanceCard;
use App\Livewire\Dashboard\NextHolidayCard;
use App\Livewire\Dashboard\WhoIsOutCard;
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
use App\Models\User;
use App\Services\TeamCoverage;
use App\Support\Dashboard\DashboardLayout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Prompt 8 tiles: who is out, next holiday, leave balance, birthdays. The
 * people are TeamCoverageTest's personas, trimmed: Lina and Omar report to
 * Sami (Study Zone, center A, Homs); Rana is in finance, center B, Aleppo;
 * Huda is HR-HMS. Today is Tue 27 Oct 2026, so "this week" is Sun 25 – Sat 31
 * Oct; Wed 4 Nov is a Study Zone × Homs holiday.
 */
class NewTilesTest extends TestCase
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

        Carbon::setTestNow('2026-10-27 10:00:00');
        CarbonImmutable::setTestNow('2026-10-27 10:00:00');
        app()->setLocale('en');
        $this->buildPersonas();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ── Who is out ───────────────────────────────────────────────────────

    public function test_who_is_out_shows_only_the_viewers_team_and_only_accepted_leave(): void
    {
        $this->leave('lina', '2026-10-27', '2026-10-28');
        $this->leave('rana', '2026-10-26', '2026-10-30');
        $this->leave('omar', '2026-10-27', '2026-10-27', LeaveRequestStatus::Pending);

        $forSami = $this->as('sami', WhoIsOutCard::class);
        $forSami->assertSee('Lina Haddad')->assertDontSee('Rana Saad')->assertDontSee('Omar Hamdan');
        $this->assertSame(1, $forSami->viewData('total'));

        // HR-HMS: the city, so Lina, never Aleppo's Rana.
        $this->as('huda', WhoIsOutCard::class)->assertSee('Lina Haddad')->assertDontSee('Rana Saad');
    }

    public function test_who_is_out_leaves_out_the_viewer_and_says_when_everyone_is_in(): void
    {
        $this->leave('sami', '2026-10-27', '2026-10-27');

        $this->as('sami', WhoIsOutCard::class)
            ->assertDontSee('Sami Khoury')
            ->assertSee('Everyone is in today');
    }

    public function test_who_is_out_counts_the_overflow_for_every_capacity(): void
    {
        $samiPosition = $this->p['sami']['position']->id;

        foreach (range(1, 6) as $i) {
            $this->person("r{$i}", "Report{$i}", 'Person', 'HMS', 'A', 'Tchr', $samiPosition, 'employee');
            $this->leave("r{$i}", '2026-10-27', '2026-10-27');
        }

        $this->leave('lina', '2026-10-27', '2026-10-27');
        $this->leave('omar', '2026-10-27', '2026-10-27');

        $card = $this->as('sami', WhoIsOutCard::class);

        $this->assertSame(8, $card->viewData('total'));
        $this->assertCount(WhoIsOutCard::SHOWN, $card->viewData('shown'));
        $card->assertSeeHtml('data-cap="2"')->assertSeeHtml('+6')
            ->assertSeeHtml('data-cap="4"')->assertSeeHtml('+4')
            ->assertSeeHtml('data-cap="6"')->assertSeeHtml('+2');
    }

    public function test_who_is_out_is_for_managers_hr_and_leadership_and_shown_for_them_by_default(): void
    {
        $layout = new DashboardLayout;

        $this->assertFalse($layout->allowed($this->user('lina'), 'who-is-out'));
        $this->assertNotContains('who-is-out', array_column($layout->defaults($this->user('lina')), 'key'));

        foreach (['sami', 'huda'] as $who) {
            $this->assertTrue($layout->allowed($this->user($who), 'who-is-out'), $who);
            $this->assertFalse($this->item($layout->defaults($this->user($who)), 'who-is-out')['hidden'], $who);
        }

        $gm = $this->makeUser($this->cities['SYR'], 'general-manager');
        $this->assertTrue($layout->allowed($gm, 'who-is-out'));
    }

    // ── Birthdays ────────────────────────────────────────────────────────

    public function test_birthdays_shows_only_the_viewers_team_this_week_without_year_or_age(): void
    {
        $this->dob('lina', '1990-10-28');
        $this->dob('omar', '1988-11-02');   // next week
        $this->dob('rana', '1992-10-29');   // Aleppo finance: in nobody's team here

        $forSami = $this->as('sami', BirthdaysCard::class);
        $forSami->assertSee('Lina Haddad')->assertDontSee('Omar Hamdan')->assertDontSee('Rana Saad')
            ->assertDontSee('1990')->assertDontSee('36');

        // A colleague of Lina's sees her; Rana, in another center and department, does not.
        $this->as('omar', BirthdaysCard::class)->assertSee('Lina Haddad');
        $this->as('rana', BirthdaysCard::class)->assertDontSee('Lina Haddad');
        $this->dob('rana', '1992-12-01');
        $this->as('rana', BirthdaysCard::class)->assertSee('No birthdays in your team this week');

        // HR-HMS: the whole city, still not Aleppo.
        $this->as('huda', BirthdaysCard::class)->assertSee('Lina Haddad')->assertDontSee('Rana Saad');
    }

    public function test_birthdays_reads_avatars_in_one_query_whatever_the_number_of_people(): void
    {
        $this->dob('lina', '1990-10-28');
        $this->queriesFor('sami', BirthdaysCard::class);   // warms the permission cache
        $one = $this->queriesFor('sami', BirthdaysCard::class);

        $this->dob('omar', '1991-10-29');
        $this->person('r1', 'Report1', 'Person', 'HMS', 'A', 'Tchr', $this->p['sami']['position']->id, 'employee');
        $this->dob('r1', '1985-10-31');
        $this->queriesFor('sami', BirthdaysCard::class);
        $three = $this->queriesFor('sami', BirthdaysCard::class);

        $this->assertSame($one, $three);
    }

    public function test_birthdays_is_for_everyone_and_shown_by_default_for_staff_and_managers_only(): void
    {
        $layout = new DashboardLayout;

        foreach (['lina' => false, 'sami' => false, 'huda' => true] as $who => $hidden) {
            $this->assertTrue($layout->allowed($this->user($who), 'birthdays'), $who);
            $this->assertSame($hidden, $this->item($layout->defaults($this->user($who)), 'birthdays')['hidden'], $who);
        }
    }

    // ── Next holiday ─────────────────────────────────────────────────────

    public function test_next_holiday_is_the_viewers_own_and_counts_down(): void
    {
        $card = $this->as('lina', NextHolidayCard::class)->assertSee('Homs holiday');

        $this->assertSame(8, $card->viewData('holiday')['days']);
        $card->assertSee('days to go');

        // Aleppo has no holiday within 60 days: a quiet line, not the Homs day.
        $this->as('rana', NextHolidayCard::class)
            ->assertDontSee('Homs holiday')
            ->assertSee('No official holiday in the next 60 days');
    }

    public function test_next_holiday_ignores_a_holiday_beyond_sixty_days(): void
    {
        Holiday::query()->forceCreate([
            'name_en' => 'Far away', 'name_ar' => 'بعيد', 'start_date' => '2027-02-01', 'end_date' => '2027-02-01',
            'is_recurring' => false, 'scope' => Holiday::SCOPE_NATIONAL,
        ]);

        $this->as('rana', NextHolidayCard::class)->assertDontSee('Far away');
    }

    public function test_next_holiday_needs_an_employee_record_and_is_shown_by_default_for_staff_and_managers(): void
    {
        $layout = new DashboardLayout;
        $noRecord = User::factory()->create(['employee_id' => null]);

        $this->assertFalse($layout->allowed($noRecord, 'next-holiday'));
        $this->assertFalse($this->item($layout->defaults($this->user('lina')), 'next-holiday')['hidden']);
        $this->assertFalse($this->item($layout->defaults($this->user('sami')), 'next-holiday')['hidden']);
    }

    // ── Leave balance ────────────────────────────────────────────────────

    public function test_leave_balance_shows_the_annual_balance_as_the_model_reports_it(): void
    {
        $this->p['lina']['balances'][LeaveTypeCases::Annual]->update(['used' => 32]);
        $balance = $this->p['lina']['balances'][LeaveTypeCases::Annual]->fresh();

        $card = $this->as('lina', LeaveBalanceCard::class);

        $this->assertSame($balance->int_remaining, $card->viewData('balance')['remaining']);
        $this->assertSame($balance->int_total, $card->viewData('balance')['total']);
        $card->assertSeeHtml('hc-ring-arc')->assertSee('Request leave');
    }

    public function test_leave_balance_offers_request_leave_only_to_who_may_request_it(): void
    {
        Role::findByName('employee', 'web')->revokePermissionTo('leaves.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as('lina', LeaveBalanceCard::class)->assertDontSee('Request leave')->assertSeeHtml('hc-ring');
    }

    public function test_leave_balance_needs_an_employee_record_and_is_in_the_gallery_for_everyone(): void
    {
        $layout = new DashboardLayout;

        $this->assertFalse($layout->allowed(User::factory()->create(['employee_id' => null]), 'leave-balance'));

        foreach (['lina', 'sami', 'huda'] as $who) {
            $item = $this->item($layout->defaults($this->user($who)), 'leave-balance');

            if ($this->user($who)->employee_id !== null) {
                $this->assertTrue($item['hidden'], $who);
            } else {
                $this->assertNull($item, $who);
            }
        }
    }

    // ── Shared ───────────────────────────────────────────────────────────

    public function test_existing_families_keep_their_default_widgets(): void
    {
        $layout = new DashboardLayout;
        // The late strip and the decisions (tiles now, first) are covered by DashboardHeaderTest.
        $shown = fn (User $u) => array_values(array_diff(array_column(array_filter($layout->defaults($u), fn ($i) => ! $i['hidden'] && $layout->isWidget($i['key'])), 'key'), ['late', 'decide']));

        $this->assertSame(
            ['for-you', 'my-leaves', 'notes', 'team', 'news', 'calendar', 'upcoming-leaves', 'today-events', 'upcoming-events', 'pending-requests', 'checklist', 'badges'],
            array_slice(array_values($shown($this->user('lina'))), 0, 12),
        );
        $this->assertSame(['next-holiday', 'birthdays'], array_slice(array_values($shown($this->user('lina'))), 12));
        $this->assertSame(['who-is-out', 'next-holiday', 'birthdays'], array_slice(array_values($shown($this->user('sami'))), 12));
    }

    public function test_each_new_tile_has_bounds_a_component_and_a_name_in_both_languages(): void
    {
        $layout = new DashboardLayout;

        foreach (['who-is-out', 'next-holiday', 'leave-balance', 'birthdays'] as $key) {
            $this->assertNotNull($layout->component($key), $key);
            // Any widget goes down to one cell (an icon); below `card` it shows as one.
            $this->assertSame('1x1', $layout->size($key, '1x1'), $key);
            $this->assertNotSame([1, 1], $layout->bounds($key)['card'], $key);

            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $this->assertNotSame('dashboard.tiles.'.$key, $layout->label($key), "{$key} {$locale}");
            }
        }
    }

    public function test_the_tiles_render_in_arabic(): void
    {
        app()->setLocale('ar');
        $this->leave('lina', '2026-10-27', '2026-10-27');

        $this->as('sami', WhoIsOutCard::class)->assertSee('الغائبون اليوم')->assertSee('تقويم الفريق');
        $this->as('lina', NextHolidayCard::class)->assertSee('عطلة حمص')->assertSee('أيام متبقية');
        $this->as('lina', LeaveBalanceCard::class)->assertSee('طلب إجازة');
        $this->as('rana', BirthdaysCard::class)->assertSee('لا أعياد ميلاد في فريقك هذا الأسبوع');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function as(string $who, string $component)
    {
        app()->forgetInstance(TeamCoverage::class);
        $this->actingAs($this->user($who));

        return Livewire::test($component);
    }

    private function queriesFor(string $who, string $component): int
    {
        app()->forgetInstance(TeamCoverage::class);
        $this->actingAs($this->user($who));
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function item(array $layout, string $key): ?array
    {
        return collect($layout)->firstWhere('key', $key);
    }

    private function user(string $who): User
    {
        return $this->p[$who]['user']->fresh();
    }

    private function dob(string $who, string $date): void
    {
        $this->p[$who]['employee']->forceFill(['actual_dob' => $date])->save();
    }

    private function buildPersonas(): void
    {
        $this->cities = [
            'SYR' => $this->makeCity('SYR', '000'),
            'HMS' => $this->makeCity('HMS', '002'),
            'ALP' => $this->makeCity('ALP', '001'),
        ];
        $this->centers = [
            'A' => Center::query()->create(['name_en' => 'Center A', 'name_ar' => 'المركز أ', 'city_id' => $this->cities['HMS']->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']),
            'B' => Center::query()->create(['name_en' => 'Center B', 'name_ar' => 'المركز ب', 'city_id' => $this->cities['ALP']->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']),
        ];
        $this->program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true]);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'Team', 'grade' => 6]);

        foreach ([LeaveTypeCases::Annual => 'Annual', LeaveTypeCases::FullSick => 'Full Sick'] as $id => $name) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => $name, 'name_ar' => $name, 'base_days' => 14]);
        }

        $holiday = Holiday::query()->forceCreate([
            'name_en' => 'Homs holiday', 'name_ar' => 'عطلة حمص', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04',
            'is_recurring' => false, 'scope' => Holiday::SCOPE_PROJECTS,
        ]);
        $holiday->projects()->create(['program_id' => $this->program->id, 'city_id' => $this->cities['HMS']->id]);

        $this->person('sami', 'Sami', 'Khoury', 'HMS', 'A', 'MGMT', null, 'manager');
        $samiPosition = $this->p['sami']['position']->id;
        $this->person('lina', 'Lina', 'Haddad', 'HMS', 'A', 'Tchr', $samiPosition, 'employee');
        $this->person('omar', 'Omar', 'Hamdan', 'HMS', 'A', 'Tchr', $samiPosition, 'employee');
        $this->person('rana', 'Rana', 'Saad', 'ALP', 'B', 'Fin', null, 'employee');

        $this->p['huda'] = ['user' => $this->makeUser($this->cities['HMS'], 'HR-HMS', ['leaves.view-own', 'leaves.view-all'])];
    }

    private function person(string $key, string $first, string $last, string $city, ?string $center, string $department, ?int $managerPositionId, string $role): void
    {
        $department = Department::query()->firstOrCreate(['code' => $department], ['name' => $department]);
        $employee = $this->makeEmployee($this->cities[$city], $key, ['first_name_en' => $first, 'last_name_en' => $last]);
        $position = Position::query()->forceCreate([
            'center_id' => $center ? $this->centers[$center]->id : null,
            'city_id' => $this->cities[$city]->id,
            'department_id' => $department->id,
            'job_level_id' => $this->level->id,
            'program_id' => $this->program->id,
            'direct_manager_id' => $managerPositionId,
            'title_en' => "{$first} position",
            'title_ar' => "منصب {$first}",
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1,
            'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);
        $balances = [];

        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick] as $typeId) {
            $balances[$typeId] = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $typeId, 'deserved' => 112, 'used' => 0]);
        }

        $user = $this->makeUser($this->cities[$city], $role, ['leaves.view-own', 'leaves.view-all', 'leaves.decide', 'leaves.create'], $employee);
        $this->p[$key] = compact('employee', 'position', 'contract', 'balances', 'user');
    }

    private function leave(string $who, string $start, string $end, int $status = LeaveRequestStatus::Accepted): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $this->p[$who]['balances'][LeaveTypeCases::Annual]->id,
            'type' => 'day',
            'start_at' => "{$start} 00:00:00",
            'end_at' => "{$end} 00:00:00",
            'reason' => 'private',
            'status' => $status,
        ]);
    }
}
