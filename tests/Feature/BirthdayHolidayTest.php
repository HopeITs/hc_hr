<?php

namespace Tests\Feature;

use App\Livewire\Employee\Leave\BirthdayHolidayCard;
use App\Models\BirthdayHolidayChoice;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Notifications\BirthdayChoiceDecided;
use App\Notifications\BirthdayChoiceNeeded;
use App\Notifications\BirthdayChoiceSubmitted;
use App\Services\BirthdayHoliday;
use App\Services\LeaveRequestPricing;
use App\Services\TeamCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The birthday holiday (team-coverage-contract §6.1, H-4 … H-6). No center,
 * so the default weekend applies: Sunday and Saturday. Week of Mon 2 – Sun 8
 * Nov 2026: Wed 4 is a working day, Sat 7 is not.
 */
class BirthdayHolidayTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    private Program $program;

    private Position $managerPosition;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 10:00:00');
        $this->makeCity(City::NATIONAL_SLUG, '000');
        $this->city = $this->makeCity('HMS', '002');
        $this->program = Program::query()->forceCreate(['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'study-zone', 'is_active' => true]);

        $this->managerPosition = $this->position();
        [, $this->manager] = $this->volunteer('1980-01-15', $this->managerPosition, role: 'manager');

        $this->birthdayWindow('2026-01-01', '2026-12-31');
    }

    public function test_a_birthday_on_a_working_day_is_a_holiday(): void
    {
        [$contract] = $this->volunteer('1995-11-04');

        $this->assertSame(['2026-11-04'], $this->dates($contract));
    }

    public function test_the_birthday_is_skipped_by_the_leave_quote(): void
    {
        [$contract] = $this->volunteer('1995-11-04');

        $holidays = app(LeaveRequestPricing::class)->holidayDates($contract, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-06'));

        $this->assertSame(['2026-11-04' => true], $holidays);
    }

    public function test_a_birthday_on_a_weekend_needs_an_approved_working_day_in_the_same_week(): void
    {
        [$contract, $user] = $this->volunteer('1995-11-07');
        $holiday = Holiday::sole();
        $birthdays = app(BirthdayHoliday::class);

        // Nothing until a day is chosen and approved.
        $this->assertSame([], $this->dates($contract));

        $this->assertRefused(fn () => $birthdays->choose($contract, $holiday, 2026, CarbonImmutable::parse('2026-11-10')), 'same-week');
        $this->assertRefused(fn () => $birthdays->choose($contract, $holiday, 2026, CarbonImmutable::parse('2026-11-08')), 'working-day');

        $choice = $birthdays->choose($contract, $holiday, 2026, CarbonImmutable::parse('2026-11-05'));
        $this->assertSame([], $this->dates($contract), 'pending does not count');

        $birthdays->decide($this->manager, $choice, true);

        $this->assertSame(['2026-11-05'], $this->dates($contract));
    }

    public function test_a_working_day_birthday_cannot_be_moved(): void
    {
        [$contract] = $this->volunteer('1995-11-04');

        $this->assertRefused(
            fn () => app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05')),
            'no-choice-needed',
        );
    }

    public function test_only_the_direct_manager_decides(): void
    {
        [$contract, $user] = $this->volunteer('1995-11-07');
        $choice = app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));
        [, $stranger] = $this->volunteer('1980-02-02', $this->position(), role: 'manager');

        $this->assertFalse(app(BirthdayHoliday::class)->canDecide($stranger, $choice));
        $this->assertFalse(app(BirthdayHoliday::class)->canDecide($user, $choice));
        $this->assertTrue(app(BirthdayHoliday::class)->canDecide($this->manager, $choice));

        Livewire::actingAs($stranger)->test(BirthdayHolidayCard::class)
            ->call('decide', $choice->id, true)
            ->assertForbidden();

        $this->assertSame(BirthdayHolidayChoice::PENDING, $choice->fresh()->status);
    }

    public function test_the_card_lets_the_volunteer_pick_and_the_manager_approve(): void
    {
        [$contract, $user] = $this->volunteer('1995-11-07');
        $key = Holiday::sole()->id.':2026';

        Livewire::actingAs($user)->test(BirthdayHolidayCard::class)
            ->assertSee(__('holidays.birthday.card-title'))
            ->set("picked.{$key}", '2026-11-06')
            ->call('choose', $key)
            ->assertHasNoErrors();

        $choice = BirthdayHolidayChoice::sole();

        Livewire::actingAs($this->manager)->test(BirthdayHolidayCard::class)
            ->assertSee(__('holidays.birthday.team-title'))
            ->call('decide', $choice->id, true);

        $this->assertSame(['2026-11-06'], $this->dates($contract));
    }

    public function test_no_date_of_birth_no_birthday(): void
    {
        [$contract] = $this->volunteer(null);

        $this->assertSame([], $this->dates($contract));
    }

    public function test_actual_dob_wins_over_the_id_dob(): void
    {
        [$contract] = $this->volunteer('1995-11-07', actualDob: '1995-11-03');

        $this->assertSame(['2026-11-03'], $this->dates($contract));
    }

    public function test_contract_ended_before_the_birthday_gives_none(): void
    {
        [$contract] = $this->volunteer('1995-11-04', end: '2026-10-31');

        $this->assertSame([], $this->dates($contract));
    }

    public function test_service_agreements_get_no_birthday(): void
    {
        [$contract] = $this->volunteer('1995-11-04', contractTypeId: 5);

        $this->assertSame([], $this->dates($contract));
    }

    public function test_a_window_that_is_not_renewed_gives_none_next_year(): void
    {
        [$contract] = $this->volunteer('1995-11-04', end: '2027-12-31');

        $this->assertSame([], $this->dates($contract, '2027-11-01', '2027-11-30'));
    }

    // ── Notifications (meeting 19/11, track 3) ───────────────────────────

    public function test_a_volunteer_whose_birthday_falls_on_a_weekend_is_asked_once_to_pick_a_day(): void
    {
        Notification::fake();
        [, $user] = $this->volunteer('1995-11-07');

        $this->artisan('birthday:notify-conflicts')->assertSuccessful();
        $this->artisan('birthday:notify-conflicts')->assertSuccessful();
        $this->travelTo('2026-10-30 10:00:00');
        $this->artisan('birthday:notify-conflicts')->assertSuccessful();

        Notification::assertSentToTimes($user, BirthdayChoiceNeeded::class, 1);
        Notification::assertSentTo($user, BirthdayChoiceNeeded::class, function (BirthdayChoiceNeeded $n) use ($user) {
            return $n->year === 2026 && $n->birthday->toDateString() === '2026-11-07'
                && $n->toArray($user)['url'] === route('leaves.index');
        });
    }

    public function test_no_choice_reminder_when_none_is_needed_or_already_made(): void
    {
        Notification::fake();
        [, $workingDay] = $this->volunteer('1995-11-04');
        [, $tooFar] = $this->volunteer('1995-11-21');
        [$contract, $chose] = $this->volunteer('1995-11-08');
        app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));

        $this->artisan('birthday:notify-conflicts')->assertSuccessful();

        Notification::assertNotSentTo([$workingDay, $tooFar, $chose], BirthdayChoiceNeeded::class);
    }

    public function test_the_reminder_horizon_comes_from_config(): void
    {
        Notification::fake();
        config(['birthday-holiday.notify_days_ahead' => 30]);
        [, $user] = $this->volunteer('1995-11-21');

        $this->artisan('birthday:notify-conflicts')->assertSuccessful();

        Notification::assertSentToTimes($user, BirthdayChoiceNeeded::class, 1);
    }

    public function test_a_service_agreement_is_never_asked(): void
    {
        Notification::fake();
        [, $user] = $this->volunteer('1995-11-07', contractTypeId: 5);

        $this->artisan('birthday:notify-conflicts')->assertSuccessful();

        Notification::assertNotSentTo($user, BirthdayChoiceNeeded::class);
    }

    public function test_a_choice_goes_to_the_direct_manager_to_decide_and_to_city_hr_for_information(): void
    {
        Notification::fake();
        [$contract, $user] = $this->volunteer('1995-11-07');
        $cityHr = $this->makeUser($this->city, 'HR-HMS');
        $otherHr = $this->makeUser($this->makeCity('ALP', '001'), 'HR-ALP');
        [, $otherManager] = $this->volunteer('1980-02-02', $this->position(), role: 'manager');

        app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));

        Notification::assertSentTo($this->manager, BirthdayChoiceSubmitted::class, fn (BirthdayChoiceSubmitted $n) => ! $n->forHr
            && $n->toArray($this->manager)['subject']['action'] === 'decide');
        Notification::assertSentTo($cityHr, BirthdayChoiceSubmitted::class, fn (BirthdayChoiceSubmitted $n) => $n->forHr
            && $n->toArray($cityHr)['subject']['action'] === null);
        Notification::assertNotSentTo([$otherHr, $otherManager, $user], BirthdayChoiceSubmitted::class);

        // HR is informed, not given the decision.
        $this->assertFalse(app(BirthdayHoliday::class)->canDecide($cityHr, BirthdayHolidayChoice::sole()));
    }

    public function test_city_hr_falls_back_to_hr_syria(): void
    {
        Notification::fake();
        [$contract] = $this->volunteer('1995-11-07');
        $national = $this->makeUser($this->city, 'HR-SYR');

        app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));

        Notification::assertSentTo($national, BirthdayChoiceSubmitted::class, fn (BirthdayChoiceSubmitted $n) => $n->forHr);
    }

    public function test_the_volunteer_is_told_the_decision(): void
    {
        Notification::fake();
        [$contract, $user] = $this->volunteer('1995-11-07');
        $choice = app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));

        app(BirthdayHoliday::class)->decide($this->manager, $choice, false);

        Notification::assertSentTo($user, BirthdayChoiceDecided::class, fn (BirthdayChoiceDecided $n) => $n->toArray($user)['title']['en'] === __('holidays.birthday.notify.rejected-title', [], 'en'));
        Notification::assertNotSentTo($this->manager, BirthdayChoiceDecided::class);
    }

    public function test_a_refused_decision_tells_nobody(): void
    {
        Notification::fake();
        [$contract, $user] = $this->volunteer('1995-11-07');
        $choice = app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));
        [, $stranger] = $this->volunteer('1980-02-02', $this->position(), role: 'manager');

        Livewire::actingAs($stranger)->test(BirthdayHolidayCard::class)
            ->call('decide', $choice->id, true)
            ->assertForbidden();

        Notification::assertNotSentTo($user, BirthdayChoiceDecided::class);
    }

    // ── The day off on the team calendar ────────────────────────────────

    public function test_the_birthday_holiday_shows_as_leave_to_the_manager_and_neutrally_to_colleagues(): void
    {
        [, $user] = $this->volunteer('1995-11-04');
        [, $colleague] = $this->volunteer('1990-03-03');
        $coverage = app(TeamCoverage::class);
        $day = CarbonImmutable::parse('2026-11-04');

        $managerView = $coverage->absences($this->manager, 'reports', $day, $day)->sole();
        $this->assertSame(__('team_calendar.birthday_holiday'), $managerView['type']);
        $this->assertSame('accepted', $managerView['status']);
        $this->assertSame(['2026-11-04'], $managerView['counted']);
        $this->assertSame(1, $coverage->coverage($this->manager, 'reports', $day, $day)['2026-11-04']['away']);

        $colleagueView = $coverage->absences($colleague, 'colleagues', $day, $day)->sole();
        $this->assertSame(__('team_calendar.on_leave'), $colleagueView['type']);
        $this->assertNull($colleagueView['status']);
        $this->assertNull($colleagueView['id']);

        $own = $coverage->absences($user, 'colleagues', $day, $day)->firstWhere('is_self', true);
        $this->assertSame(__('team_calendar.birthday_holiday'), $own['type']);
    }

    public function test_a_moved_day_shows_only_once_approved(): void
    {
        [$contract] = $this->volunteer('1995-11-07');
        [$from, $to] = [CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08')];

        $choice = app(BirthdayHoliday::class)->choose($contract, Holiday::sole(), 2026, CarbonImmutable::parse('2026-11-05'));
        $this->assertCount(0, app(TeamCoverage::class)->absences($this->manager, 'reports', $from, $to));

        app(BirthdayHoliday::class)->decide($this->manager, $choice, true);

        $entry = app(TeamCoverage::class)->absences($this->manager, 'reports', $from, $to)->sole();
        $this->assertSame(['2026-11-05'], $entry['dates']);
    }

    public function test_the_viewers_birthday_is_not_a_holiday_for_the_rest_of_the_team(): void
    {
        [, $user] = $this->volunteer('1995-11-04');
        $this->volunteer('1990-03-03');
        $day = CarbonImmutable::parse('2026-11-04');

        $grid = app(TeamCoverage::class)->coverage($user, 'colleagues', $day, $day)['2026-11-04'];

        // All three (the manager is a colleague too) are expected; the birthday is an absence, not a closed day.
        $this->assertFalse($grid['is_holiday']);
        $this->assertSame(3, $grid['expected']);
        $this->assertSame(1, $grid['away']);
    }

    /** @return list<string> */
    private function dates(Contract $contract, string $from = '2026-11-01', string $to = '2026-11-30'): array
    {
        return app(BirthdayHoliday::class)->datesFor($contract->fresh(), CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    private function assertRefused(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail("Expected the choice to be refused ({$key}).");
        } catch (ValidationException $e) {
            $this->assertSame(__('holidays.birthday.'.$key), $e->errors()['chosen_date'][0]);
        }
    }

    private function birthdayWindow(string $from, string $to): Holiday
    {
        return Holiday::query()->create([
            'name_en' => 'Birthday', 'name_ar' => 'عيد ميلاد', 'start_date' => $from, 'end_date' => $to,
            'scope' => Holiday::SCOPE_NATIONAL, 'kind' => Holiday::KIND_BIRTHDAY,
        ]);
    }

    private function position(): Position
    {
        static $n = 0;
        $n++;

        return Position::query()->forceCreate([
            'department_id' => Department::query()->firstOrCreate(['code' => 'Tchr'], ['name' => 'Teachers'])->id,
            'city_id' => $this->city->id,
            'job_level_id' => JobLevel::query()->firstOrCreate(['grade' => 6], ['name_en' => 'Volunteer', 'name_ar' => 'متطوع'])->id,
            'program_id' => $this->program->id,
            'direct_manager_id' => null,
            'title_en' => "Position {$n}",
            'title_ar' => "منصب {$n}",
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }

    /** @return array{0: Contract, 1: User} */
    private function volunteer(?string $dob, ?Position $position = null, string $role = 'employee', ?string $actualDob = null, string $end = '2026-12-31', int $contractTypeId = 1): array
    {
        $position ??= tap($this->position(), fn ($p) => $p->forceFill(['direct_manager_id' => $this->managerPosition->id])->save());
        $employee = $this->makeEmployee($this->city, 'v-'.uniqid(), ['dob' => $dob, 'actual_dob' => $actualDob]);

        ContractType::query()->find($contractTypeId)
            ?? ContractType::query()->forceCreate(['id' => $contractTypeId, 'name_en' => 'Type', 'name_ar' => 'نوع']);
        $contract = Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => $contractTypeId,
            'start_date' => '2026-01-01', 'end_date' => $end,
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);

        return [$contract, $this->makeUser($this->city, $role, ['leaves.view-own'], $employee)];
    }
}
