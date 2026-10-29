<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\Inbox\Index as Inbox;
use App\Livewire\Team\Calendar;
use App\Models\Center;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\TeamCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * C5 team calendar and coverage (team-coverage-contract.md). The fixtures are
 * the contract's §7 personas: Lina and Omar report to Sami (Study Zone,
 * teachers, center A, Homs); Rana is in finance, center B, Aleppo; Nour
 * (Homs) and Tarek (Aleppo) share the technical department; Fadi (Homs) and
 * Maya (Aleppo) are Study Zone project managers; Huda is HR-HMS. Weekend Fri
 * and Sat; Wed 4 Nov 2026 is a Homs holiday.
 */
class TeamCoverageTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $p = [];

    private array $cities = [];

    private array $centers = [];

    private array $levels = [];

    private Program $program;

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

    /** E1: a colleague sees a sick leave as "On leave", with no reason, no status and no request id. */
    public function test_e1_a_colleague_sees_sensitive_leave_as_on_leave_and_nothing_private(): void
    {
        $leave = $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');

        $entries = $this->coverage()->absences($this->user('omar'), 'directManager', $this->d('2026-11-01'), $this->d('2026-11-30'));
        $lina = $entries->firstWhere('name', 'Lina Haddad');

        $this->assertNotNull($lina);
        $this->assertSame('On leave', $lina['type']);
        $this->assertNull($lina['status']);
        $this->assertNull($lina['id']);
        $this->assertNull($lina['hours']);
        $this->assertNull($lina['substitute']);
        // Opaque: a hash, never the request id.
        $this->assertMatchesRegularExpression('/^a-[0-9a-f]{16}$/', $lina['key']);
        $this->assertNotSame('leave-'.$leave->id, $lina['key']);
        $this->assertStringNotContainsString('surgery', json_encode($entries));
    }

    /** E2: the manager sees the real type and status; the holiday is not a counted day. */
    public function test_e2_the_manager_sees_type_status_and_counted_working_days(): void
    {
        $leave = $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');

        $lina = $this->coverage()->absences($this->user('sami'), 'reports', $this->d('2026-11-01'), $this->d('2026-11-30'))->sole();

        $this->assertSame('Full Sick', $lina['type']);
        $this->assertSame('accepted', $lina['status']);
        $this->assertSame($leave->id, $lina['id']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], $lina['dates']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-05'], $lina['counted']);
        $this->assertStringNotContainsString('surgery', json_encode($lina));
    }

    /** E3, Q2: pending is hidden from colleagues and shown, marked, to the manager. */
    public function test_e3_pending_is_hidden_from_colleagues_and_marked_for_the_manager(): void
    {
        $this->leave('omar', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $range = [$this->d('2026-11-01'), $this->d('2026-11-30')];

        $this->assertTrue($this->coverage()->absences($this->user('lina'), 'directManager', ...$range)->isEmpty());

        $forSami = $this->coverage()->absences($this->user('sami'), 'reports', ...$range)->sole();
        $this->assertSame('pending', $forSami['status']);
        $this->assertSame(1, $this->coverage()->coverage($this->user('sami'), 'reports', ...$range)['2026-11-03']['pending']);
        $this->assertTrue($this->coverage()->absences($this->user('sami'), 'reports', ...[...$range, ['status' => 'accepted']])->isEmpty());
    }

    /** E4, Q3: deciding Omar's request warns about Lina; the warning is advisory. */
    public function test_e4_the_deciding_manager_is_warned_of_any_overlap(): void
    {
        $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');
        $omar = $this->leave('omar', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);

        $overlap = $this->coverage()->teamOverlap($omar);

        $this->assertSame(['2026-11-03'], $overlap['dates']);
        $this->assertSame([['name' => 'Lina Haddad', 'status' => 'accepted', 'dates' => ['2026-11-03']]], $overlap['members']);

        // Rana is in no team of Sami's: never part of the warning.
        $this->leave('rana', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $this->assertCount(1, $this->coverage()->teamOverlap($omar)['members']);
    }

    /** E5, Q4 and AC-C5-01: the requester's count is the calendar's set, on the requester's working days. */
    public function test_e5_the_requester_hint_agrees_with_the_calendar(): void
    {
        $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');
        $this->leave('nour', LeaveTypeCases::Annual, '2026-11-04', '2026-11-04');
        $omar = $this->user('omar');

        $this->assertSame(1, $this->coverage()->colleaguesAwayCount($omar, $this->d('2026-11-03'), $this->d('2026-11-04')));
        // 4 Nov alone is a holiday for Omar: no working day, nothing to warn about.
        $this->assertSame(0, $this->coverage()->colleaguesAwayCount($omar, $this->d('2026-11-04'), $this->d('2026-11-04')));

        // The calendar over the same range and team names exactly that one person.
        $calendar = $this->coverage()->absences($omar, 'colleagues', $this->d('2026-11-03'), $this->d('2026-11-04'), ['status' => 'accepted'])
            ->reject(fn (array $e) => $e['is_self'])
            ->filter(fn (array $e) => array_intersect($e['counted'], ['2026-11-03']) !== []);
        $this->assertSame(['Lina Haddad'], $calendar->pluck('name')->all());
    }

    /** E6, E7: an absence outside the viewer's set is not shown, not counted, and leaves no trace. */
    public function test_e6_e7_an_absence_outside_the_set_is_indistinguishable_from_none(): void
    {
        $range = [$this->d('2026-11-01'), $this->d('2026-11-30')];
        $lina = $this->user('lina');
        $huda = $this->user('huda');

        $before = [$this->coverage()->coverage($lina, 'colleagues', ...$range), $this->coverage()->coverage($huda, 'city', ...$range)];

        $this->leave('rana', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');

        $after = [$this->fresh()->coverage($lina, 'colleagues', ...$range), $this->fresh()->coverage($huda, 'city', ...$range)];

        $this->assertEquals($before, $after);
    }

    /** E8, E9 and §4.2: what "overlaps today" means, the same as the old upcoming scope. */
    public function test_e8_e9_overlap_semantics(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        CarbonImmutable::setTestNow('2026-11-03 12:00:00');
        $today = $this->d('2026-11-03');

        $this->leave('lina', LeaveTypeCases::Annual, '2026-11-03 08:00:00', '2026-11-03 10:00:00', type: 'hour');
        $this->leave('lina', LeaveTypeCases::Annual, '2026-11-01', '2026-11-03');
        $this->leave('lina', LeaveTypeCases::Annual, '2026-10-30', '2026-11-02');

        $entries = $this->fresh()->absences($this->user('omar'), 'directManager', $today, $today);

        $this->assertCount(2, $entries);
        $hour = $entries->firstWhere('partial', true);
        $this->assertNotNull($hour);
        // Colleague level: part of the day, without the times.
        $this->assertNull($hour['hours']);
    }

    /** E10: a rejected leave is never shown or counted. */
    public function test_e10_a_rejected_leave_never_appears(): void
    {
        $this->leave('lina', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Rejected);
        $range = [$this->d('2026-11-01'), $this->d('2026-11-30')];

        foreach ([['omar', 'directManager'], ['sami', 'reports'], ['huda', 'city']] as [$who, $scope]) {
            $this->assertTrue($this->coverage()->absences($this->user($who), $scope, ...$range)->isEmpty(), $who);
        }
    }

    /** E11, Q5 b: the manager's own leave shows in their team's view, and tells the requester. */
    public function test_e11_the_manager_belongs_to_the_team_view_and_their_absence_is_flagged(): void
    {
        $this->leave('sami', LeaveTypeCases::Annual, '2026-11-02', '2026-11-03');
        $lina = $this->user('lina');

        $this->assertSame(['Sami Khoury'], $this->coverage()->absences($lina, 'directManager', $this->d('2026-11-01'), $this->d('2026-11-30'))->pluck('name')->all());
        $this->assertTrue($this->coverage()->managerAway($lina, $this->d('2026-11-02'), $this->d('2026-11-02')));
        $this->assertFalse($this->coverage()->managerAway($lina, $this->d('2026-11-05'), $this->d('2026-11-05')));
    }

    /** E12, E13, E14: department across governorates, project-manager peers, service departments for a PM. */
    public function test_e12_e13_e14_the_colleague_set(): void
    {
        $this->leave('tarek', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $this->leave('maya', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $this->leave('logi', LeaveTypeCases::FullSick, '2026-11-03', '2026-11-03');
        $range = [$this->d('2026-11-01'), $this->d('2026-11-30')];
        $names = fn (string $who, string $scope = 'colleagues') => $this->coverage()->absences($this->user($who), $scope, ...$range)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Tarek Nasser'], $names('nour'));
        $this->assertSame(['Maya Saleh'], $names('fadi', 'programManagers'));
        $this->assertNotContains('Maya Saleh', $names('lina'));

        $maya = $this->coverage()->absences($this->user('maya'), 'serviceDepartments', ...$range)->sole();
        $this->assertSame(['Logi Volunteer', 'On leave'], [$maya['name'], $maya['type']]);
        $this->assertNotContains('Logi Volunteer', $names('fadi'));
        $this->assertNotContains('Logi Volunteer', $names('lina'));
    }

    /** §4.1: a scope the role may not use gives the same empty answer as an empty team. */
    public function test_a_scope_the_role_may_not_use_is_empty(): void
    {
        $this->leave('rana', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $lina = $this->user('lina');

        $this->assertSame(['colleagues', 'department', 'center', 'directManager', 'reports'], $this->coverage()->scopesFor($lina));

        foreach (['org', 'city', 'programManagers', 'serviceDepartments', 'nonsense'] as $scope) {
            $this->assertTrue($this->coverage()->absences($lina, $scope, $this->d('2026-11-01'), $this->d('2026-11-30'))->isEmpty(), $scope);
        }
    }

    /** §4.2 bounds: no call reads more than 62 days. */
    public function test_a_range_over_the_bound_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coverage()->absences($this->user('sami'), 'reports', $this->d('2026-11-01'), $this->d('2027-01-05'));
    }

    /** Former staff (A2D-Q3) are never listed to staff or managers. */
    public function test_former_staff_are_not_listed(): void
    {
        $this->leave('lina', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $this->p['lina']['employee']->update(['is_active' => false]);

        $this->assertTrue($this->coverage()->absences($this->user('sami'), 'reports', $this->d('2026-11-01'), $this->d('2026-11-30'))->isEmpty());
    }

    /** Step 2: query count stays flat as one center's team grows. */
    public function test_query_count_does_not_grow_with_the_team(): void
    {
        $count = function (int $members): int {
            foreach (range(1, $members) as $i) {
                $person = $this->person("extra{$members}x{$i}", 'Extra', "M{$members}n{$i}", 'HMS', 'A', 'Tchr', 6, $this->p['sami']['position']->id);
                $this->leave($person, LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
            }

            $sami = $this->user('sami');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fresh()->coverage($sami, 'reports', $this->d('2026-11-01'), $this->d('2026-11-30'));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count(1);
        $large = $count(49);

        $this->assertLessThanOrEqual($small + 6, $large, "1 member: {$small} queries, 50 members: {$large}");
    }

    // ── Livewire surfaces ────────────────────────────────────────────────

    /** AC-C5-02/03: the page in EN and AR, nothing private, no request id at colleague level. */
    public function test_the_calendar_page_shows_colleagues_without_private_data_in_both_languages(): void
    {
        $leave = $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');

        foreach (['en' => 'On leave', 'ar' => 'في إجازة'] as $locale => $label) {
            app()->setLocale($locale);

            $component = Livewire::actingAs($this->user('omar'))
                ->test(Calendar::class, ['date' => '2026-11-03', 'scope' => 'directManager'])
                ->set('date', '2026-11-03')
                ->set('scope', 'directManager')
                ->assertSee($label)
                ->assertSee($locale === 'en' ? 'Lina Haddad' : 'لينا حداد')
                ->assertDontSee('surgery')
                ->assertDontSee('Full Sick')
                ->assertDontSeeHtml('leave-'.$leave->id)
                ->assertSeeHtml('data-testid="calendar-list"')
                // An employee has no status filter: there is nothing pending to show them.
                ->assertDontSeeHtml('id="calendar-status"');

            // Only scalars in the state the browser keeps.
            foreach (['view', 'date', 'scope', 'status', 'cityId'] as $property) {
                $this->assertIsScalar($component->get($property));
            }
        }
    }

    /** Birthdays of the people the scope shows: the day only, never the year, and nobody outside the scope. */
    public function test_the_calendar_shows_birthdays_of_the_visible_team_only(): void
    {
        $this->p['lina']['employee']->forceFill(['actual_dob' => '1994-11-03'])->save();
        $this->p['rana']['employee']->forceFill(['actual_dob' => '1990-11-03'])->save();

        $birthdays = $this->coverage()->birthdays($this->user('omar'), 'directManager', $this->d('2026-11-01'), $this->d('2026-11-30'));

        $this->assertSame(['2026-11-03'], array_keys($birthdays));
        $this->assertSame(['Lina Haddad'], array_column($birthdays['2026-11-03'], 'name'));
        $this->assertSame(['key', 'name', 'avatar', 'is_self'], array_keys($birthdays['2026-11-03'][0]));

        Livewire::actingAs($this->user('omar'))
            ->test(Calendar::class)
            ->set('date', '2026-11-03')
            ->set('scope', 'directManager')
            ->assertSeeHtml('data-testid="calendar-birthday"')
            ->assertSee('Lina Haddad')
            ->assertDontSee('1994')
            ->assertDontSee('Rana Saad');

        Livewire::actingAs($this->user('lina'))
            ->test(Calendar::class)
            ->set('date', '2026-11-03')
            ->assertSee(__('team_calendar.your_birthday'));
    }

    /** A day from today on opens the leave form on that date; a past day, or someone who cannot request, gets no link. */
    public function test_a_day_from_today_on_links_to_a_leave_request(): void
    {
        $request = fn (string $date) => 'data-testid="calendar-request-'.$date.'"';

        Livewire::actingAs($this->user('omar'))
            ->test(Calendar::class)
            ->assertSeeHtml($request('2026-10-27'))
            ->assertSeeHtml(e(route('leaves.index', ['new' => 1, 'date' => '2026-10-28'])))
            ->assertDontSeeHtml($request('2026-10-26'));

        Livewire::actingAs($this->p['huda']['user']->fresh())
            ->test(Calendar::class)
            ->assertDontSeeHtml('data-testid="calendar-request-');
    }

    /** A forbidden scope from the URL falls back to the default; it never widens anything. */
    public function test_a_scope_from_the_url_is_checked_against_the_role(): void
    {
        $this->leave('rana', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');

        Livewire::actingAs($this->user('lina'))
            ->test(Calendar::class)
            ->set('date', '2026-11-03')
            ->set('scope', 'org')
            ->assertDontSee('Rana Saad')
            // Nobody visible is away; the month list keeps only Lina's own project holiday.
            ->assertDontSeeHtml('data-testid="calendar-empty"')
            ->assertSee('Homs holiday');
    }

    /** The manager's view: pending marked by text, status filter, week and day views and navigation. */
    public function test_the_manager_view_marks_pending_and_navigates(): void
    {
        $this->leave('omar', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);

        Livewire::actingAs($this->user('sami'))
            ->test(Calendar::class)
            ->set('date', '2026-11-03')
            ->set('view', 'week')
            ->assertSee('Omar Hamdan')
            ->assertSeeHtml('data-status="pending"')
            ->assertSeeHtml('id="calendar-status"')
            ->set('status', 'accepted')
            ->assertDontSee('Omar Hamdan')
            ->set('status', 'all')
            ->call('move', 1)
            ->assertSet('date', '2026-11-10')
            ->assertDontSee('Omar Hamdan')
            ->call('openDay', '2026-11-03')
            ->assertSet('view', 'day')
            ->assertSee('Omar Hamdan')
            ->call('goToday')
            ->assertSet('date', '');
    }

    /** E5 on screen, AC-C5-01: the leave form shows the same count as the service, and no names. */
    public function test_the_leave_form_shows_the_colleague_count_without_names(): void
    {
        $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');
        $this->leave('sami', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03');
        $omar = $this->user('omar');

        $component = Livewire::actingAs($omar)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $this->p['omar']['balances'][LeaveTypeCases::Annual]->id)
            ->set('form.start_at', '2026-11-03')
            ->set('form.end_at', '2026-11-04');

        $expected = $this->fresh()->colleaguesAwayCount($omar, $this->d('2026-11-03'), $this->d('2026-11-04'));
        $this->assertSame(2, $expected);
        $this->assertSame(['colleagues' => $expected, 'manager_away' => true], $component->instance()->leaveImpact['team']);

        $component->assertSeeHtml('data-testid="leave-impact-team"')
            ->assertSee('2 colleagues are already away on some of these days.')
            ->assertSee(__('team_calendar.hint.manager_away'));

        // No names in the hint itself (the substitute picker elsewhere on the form lists colleagues anyway).
        $html = $component->html();
        $start = strpos($html, 'data-testid="leave-impact-team"');
        $hint = substr($html, $start, strpos($html, '</div>', $start) - $start);
        $this->assertStringNotContainsString('Lina', $hint);
        $this->assertStringNotContainsString('Sami', $hint);
    }

    /** E4 on screen: the inbox row and the All-leaves decision show the advisory warning. */
    public function test_the_decision_screens_show_the_overlap_warning(): void
    {
        $this->leave('lina', LeaveTypeCases::FullSick, '2026-11-02', '2026-11-05');
        $omar = $this->leave('omar', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $sami = $this->user('sami');

        Livewire::actingAs($sami)->test(Inbox::class)
            ->assertSeeHtml('data-testid="team-overlap"')
            ->assertSee('Lina Haddad (Accepted): 03-11');

        Livewire::actingAs($sami)->test(AllLeaveRequestModule::class)
            ->call('openDecision', $omar->id)
            ->assertSeeHtml('data-testid="team-overlap"')
            // Advisory: Save is still a normal submit button.
            ->assertSeeHtml('wire:submit.prevent="submitDecision"');
    }

    /**
     * G-C-13 (F12): HR deciding on behalf of a manager whose team spans cities
     * is warned only about the members it may see (§4.1). Zaid reports to Sami
     * from Aleppo: Sami sees his pending leave, HR-HMS never learns of it.
     */
    public function test_the_overlap_warning_is_limited_to_what_the_decider_may_see(): void
    {
        $this->person('zaid', 'Zaid', 'Halabi', 'ALP', 'B', 'Tchr', 6, $this->p['sami']['position']->id, 'employee');
        $this->leave('zaid', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $this->leave('lina', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        $omar = $this->leave('omar', LeaveTypeCases::Annual, '2026-11-03', '2026-11-03', LeaveRequestStatus::Pending);
        // Sami is away today, so HR-HMS decides on his behalf.
        $this->leave('sami', LeaveTypeCases::Annual, '2026-10-27', '2026-10-27');

        $names = fn (User $viewer) => collect($this->fresh()->teamOverlap($omar, $viewer)['members'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Lina Haddad', 'Zaid Halabi'], $names($this->user('sami')));
        $this->assertSame(['Lina Haddad'], $names($this->user('huda')));

        // On screen the decider is the signed-in user.
        $this->fresh();
        Livewire::actingAs($this->user('huda'))->test(AllLeaveRequestModule::class)
            ->call('openDecision', $omar->id)
            ->assertSeeHtml('data-testid="team-overlap"')
            ->assertSee('Lina Haddad')
            ->assertDontSee('Zaid Halabi');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function coverage(): TeamCoverage
    {
        return app(TeamCoverage::class);
    }

    /** A new instance, so no per-request memo hides a change. */
    private function fresh(): TeamCoverage
    {
        app()->forgetInstance(TeamCoverage::class);

        return app(TeamCoverage::class);
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function user(string $who): User
    {
        return $this->p[$who]['user']->fresh();
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
            'C' => Center::query()->create(['name_en' => 'Center C', 'name_ar' => 'المركز ج', 'city_id' => $this->cities['HMS']->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']),
        ];
        $this->program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true]);

        foreach ([1 => 'Program Manager', 3 => 'Project Manager', 5 => 'Department Manager', 6 => 'Team'] as $grade => $name) {
            $this->levels[$grade] = JobLevel::query()->forceCreate(['name_en' => $name, 'name_ar' => $name, 'grade' => $grade]);
        }

        foreach ([LeaveTypeCases::Annual => 'Annual', LeaveTypeCases::FullSick => 'Full Sick'] as $id => $name) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => $name, 'name_ar' => $name === 'Annual' ? 'سنوية' : 'مرضية كاملة', 'base_days' => 14]);
        }

        // Wed 4 Nov 2026: a holiday of the project Study Zone × Homs (§6.1).
        $holiday = Holiday::query()->forceCreate([
            'name_en' => 'Homs holiday', 'name_ar' => 'عطلة حمص', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04',
            'is_recurring' => false, 'scope' => Holiday::SCOPE_PROJECTS,
        ]);
        $holiday->projects()->create(['program_id' => $this->program->id, 'city_id' => $this->cities['HMS']->id]);

        $this->person('sami', 'Sami', 'Khoury', 'HMS', 'A', 'MGMT', 5, null, 'manager', 'سامي', 'خوري');
        $samiPosition = $this->p['sami']['position']->id;
        $this->person('lina', 'Lina', 'Haddad', 'HMS', 'A', 'Tchr', 6, $samiPosition, 'employee', 'لينا', 'حداد');
        $this->person('omar', 'Omar', 'Hamdan', 'HMS', 'A', 'Tchr', 6, $samiPosition, 'employee');
        $this->person('rana', 'Rana', 'Saad', 'ALP', 'B', 'Fin', 6, null, 'employee');
        $this->person('nour', 'Nour', 'Aswad', 'HMS', 'C', 'Tech', 6, null, 'employee');
        $this->person('tarek', 'Tarek', 'Nasser', 'ALP', 'B', 'Tech', 6, null, 'employee');
        $this->person('fadi', 'Fadi', 'Bitar', 'HMS', null, 'PMH', 3, null, 'manager');
        $this->person('maya', 'Maya', 'Saleh', 'ALP', null, 'PMA', 3, null, 'manager');
        $this->person('logi', 'Logi', 'Volunteer', 'ALP', null, 'Logi', 6, null, 'employee');

        $this->p['huda'] = ['user' => $this->makeUser($this->cities['HMS'], 'HR-HMS', ['leaves.view-own', 'leaves.view-all', 'leaves.decide'])];
    }

    /** One volunteer: employee, position, running contract, balances, and a user with $role. */
    private function person(string $key, string $first, string $last, string $city, ?string $center, string $department, int $grade, ?int $managerPositionId, ?string $role = null, ?string $firstAr = null, ?string $lastAr = null): string
    {
        $department = Department::query()->firstOrCreate(['code' => $department], ['name' => $department]);
        $employee = $this->makeEmployee($this->cities[$city], $key, [
            'first_name_en' => $first, 'last_name_en' => $last,
            'first_name_ar' => $firstAr ?? $first, 'last_name_ar' => $lastAr ?? $last,
        ]);
        $position = Position::query()->forceCreate([
            'center_id' => $center ? $this->centers[$center]->id : null,
            'city_id' => $this->cities[$city]->id,
            'department_id' => $department->id,
            'job_level_id' => $this->levels[$grade]->id,
            'program_id' => $this->program->id,
            'direct_manager_id' => $managerPositionId,
            'title_en' => "{$first} position",
            'title_ar' => "منصب {$first}",
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => $this->contractType(),
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1,
            'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);
        $balances = [];

        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick] as $typeId) {
            $balances[$typeId] = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $typeId, 'deserved' => 112, 'used' => 0]);
        }

        $user = $role ? $this->makeUser($this->cities[$city], $role, ['leaves.view-own', 'leaves.view-all', 'leaves.decide', 'leaves.create'], $employee) : null;
        $this->p[$key] = compact('employee', 'position', 'contract', 'balances', 'user');

        return $key;
    }

    private function contractType(): int
    {
        return \App\Models\ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id;
    }

    private function leave(string $who, int $typeId, string $start, string $end, int $status = LeaveRequestStatus::Accepted, string $type = 'day'): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate([
            'leave_balance_id' => $this->p[$who]['balances'][$typeId]->id,
            'type' => $type,
            'start_at' => strlen($start) === 10 ? "{$start} 00:00:00" : $start,
            'end_at' => strlen($end) === 10 ? "{$end} 00:00:00" : $end,
            'reason' => 'surgery',
            'status' => $status,
        ]);
    }
}
