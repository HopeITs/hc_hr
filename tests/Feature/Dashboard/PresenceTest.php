<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\RecordPresence;
use App\Livewire\Dashboard\ClockPresence;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\TeamCoverage;
use App\Support\Dashboard\Presence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * "Who is online" on the big clock: a last-seen stamp written at most once a
 * minute, read only within the viewer's own scope (TeamCoverage's default
 * scope, as the who-is-out and birthdays tiles). Personas as NewTilesTest:
 * Lina and Omar report to Sami (center A, Homs); Rana is in finance, center
 * B, Aleppo; Huda is HR-HMS; Hiba is HR-SYR.
 */
class PresenceTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private array $p = [];

    private array $cities = [];

    private array $centers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-29 10:00:00');
        CarbonImmutable::setTestNow('2026-10-29 10:00:00');
        app()->setLocale('en');
        $this->buildPersonas();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_manager_sees_only_their_own_team_online(): void
    {
        $this->everyoneOnline();

        $card = $this->as('sami')
            ->assertSee('Lina Haddad')->assertSee('Omar Hamdan')
            ->assertDontSee('Rana Saad')->assertDontSee('Huda')->assertDontSee('Hiba')
            ->assertDontSee('Sami Khoury');   // never the viewer

        $this->assertSame(2, $card->viewData('count'));
        $this->assertSame(2, $card->viewData('total'));
    }

    public function test_an_employee_never_sees_someone_outside_their_colleagues(): void
    {
        $this->everyoneOnline();

        $this->as('lina')->assertDontSee('Rana Saad')->assertDontSee('Lina Haddad');

        // Rana, alone in Aleppo finance: nobody from Homs, not even as a count.
        $rana = $this->as('rana')
            ->assertDontSee('Lina Haddad')->assertDontSee('Omar Hamdan')->assertDontSee('Sami Khoury')
            ->assertSee(__('dashboard_clock.presence.none'));
        $this->assertSame(0, $rana->viewData('count'));
        $this->assertSame(0, $rana->viewData('total'));
    }

    public function test_city_hr_sees_their_city_and_hr_syr_sees_everyone(): void
    {
        $this->everyoneOnline();

        $this->as('huda')
            ->assertSee('Lina Haddad')->assertSee('Omar Hamdan')->assertSee('Sami Khoury')
            ->assertDontSee('Rana Saad');

        $this->as('hiba')
            ->assertSee('Lina Haddad')->assertSee('Sami Khoury')->assertSee('Rana Saad');
    }

    public function test_online_means_seen_in_the_last_five_minutes(): void
    {
        $this->seen('lina', now()->subMinutes(Presence::ONLINE_MINUTES - 1));
        $this->seen('omar', now()->subMinutes(Presence::ONLINE_MINUTES + 1));

        $card = $this->as('sami')->assertSee('Lina Haddad')->assertDontSee('Omar Hamdan');

        $this->assertSame(1, $card->viewData('count'));
        $this->assertSame(2, $card->viewData('total'));
    }

    public function test_it_polls_once_a_minute_and_only_while_on_screen(): void
    {
        $this->as('sami')->assertSeeHtml('wire:poll.visible.60s');
    }

    public function test_the_stamp_is_written_at_most_once_a_minute(): void
    {
        $presence = app(Presence::class);
        $user = $this->user('lina');

        $this->assertTrue($presence->touch($user));
        $this->assertSame('2026-10-29 10:00:00', $this->stamp('lina'));

        // 30 seconds later, on the same account: no write, and not even a query.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertFalse($presence->touch($user, now()->addSeconds(30)));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        // A fresh copy of the account (another request) is held back by the WHERE.
        $this->assertFalse($presence->touch($this->user('lina'), now()->addSeconds(45)));
        $this->assertSame('2026-10-29 10:00:00', $this->stamp('lina'));

        // A minute on, it moves.
        $this->assertTrue($presence->touch($this->user('lina'), now()->addSeconds(61)));
        $this->assertSame('2026-10-29 10:01:01', $this->stamp('lina'));
    }

    public function test_every_web_request_of_a_signed_in_user_records_presence(): void
    {
        Route::middleware('web')->get('/_presence-probe', fn () => 'ok');

        $this->get('/_presence-probe')->assertOk();
        $this->assertNull($this->stamp('lina'));

        $this->actingAs($this->user('lina'))->get('/_presence-probe')->assertOk();
        $this->assertSame('2026-10-29 10:00:00', $this->stamp('lina'));
        $this->assertContains(RecordPresence::class, app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web']);
    }

    private function everyoneOnline(): void
    {
        foreach (array_keys($this->p) as $who) {
            $this->seen($who, now()->subMinute());
        }
    }

    private function seen(string $who, $at): void
    {
        DB::table('users')->where('id', $this->p[$who]['user']->id)->update(['last_seen_at' => $at]);
    }

    private function stamp(string $who): ?string
    {
        $value = DB::table('users')->where('id', $this->p[$who]['user']->id)->value('last_seen_at');

        return $value === null ? null : Carbon::parse($value)->toDateTimeString();
    }

    private function as(string $who)
    {
        app()->forgetInstance(TeamCoverage::class);
        $this->actingAs($this->user($who));

        return Livewire::test(ClockPresence::class);
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
        ];
        $program = Program::query()->forceCreate(['name' => 'Study Zone', 'slug' => 'study-zone', 'code' => 'SZ', 'is_active' => true]);
        $level = JobLevel::query()->forceCreate(['name_en' => 'Team', 'name_ar' => 'Team', 'grade' => 6]);

        $this->person('sami', 'Sami', 'Khoury', 'HMS', 'A', 'MGMT', null, 'manager', $program, $level);
        $samiPosition = $this->p['sami']['position']->id;
        $this->person('lina', 'Lina', 'Haddad', 'HMS', 'A', 'Tchr', $samiPosition, 'employee', $program, $level);
        $this->person('omar', 'Omar', 'Hamdan', 'HMS', 'A', 'Tchr', $samiPosition, 'employee', $program, $level);
        $this->person('rana', 'Rana', 'Saad', 'ALP', 'B', 'Fin', null, 'employee', $program, $level);

        $this->p['huda'] = ['user' => $this->makeUser($this->cities['HMS'], 'HR-HMS', [], $this->makeEmployee($this->cities['HMS'], 'huda', ['first_name_en' => 'Huda', 'last_name_en' => 'Nasr']))];
        $this->p['hiba'] = ['user' => $this->makeUser($this->cities['SYR'], 'HR-SYR', [], $this->makeEmployee($this->cities['SYR'], 'hiba', ['first_name_en' => 'Hiba', 'last_name_en' => 'Aziz']))];
    }

    private function person(string $key, string $first, string $last, string $city, string $center, string $department, ?int $managerPositionId, string $role, Program $program, JobLevel $level): void
    {
        $department = Department::query()->firstOrCreate(['code' => $department], ['name' => $department]);
        $employee = $this->makeEmployee($this->cities[$city], $key, ['first_name_en' => $first, 'last_name_en' => $last]);
        $position = Position::query()->forceCreate([
            'center_id' => $this->centers[$center]->id,
            'city_id' => $this->cities[$city]->id,
            'department_id' => $department->id,
            'job_level_id' => $level->id,
            'program_id' => $program->id,
            'direct_manager_id' => $managerPositionId,
            'title_en' => "{$first} position",
            'title_ar' => "منصب {$first}",
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id,
            'contract_type_id' => ContractType::query()->firstOrCreate(['id' => 1], ['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 2, 'salary_per' => 1,
            'weekly_hours' => 40, 'is_active' => true, 'auto_renew' => false,
        ]);

        $user = $this->makeUser($this->cities[$city], $role, [], $employee);
        $this->p[$key] = compact('employee', 'position', 'user');
    }
}
