<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Timesheets;
use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Attendance is scoped by the active role like the other pages: an employee sees their own
 * timesheet, a manager their own and their direct reports', an HR role of a city everyone of
 * that city, HR Syria everyone.
 */
class AttendanceAccessTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    /** @var array<string, Employee> */
    private array $e = [];

    public function test_an_employee_sees_only_their_own_timesheet(): void
    {
        $this->setUpWorld();
        $user = $this->makeUser($this->e['report']->city, 'employee', ['attendance.view-own'], $this->e['report']);

        $this->assertSame(Roster::OWN, app(Roster::class)->mode($user));
        $this->assertSame(['report'], $this->seen($user));

        Livewire::actingAs($user)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->assertSee('Dana Reporter')
            ->assertDontSee('Peter Peer')
            ->assertDontSee('Boss Manager')
            // No permission to decide a day, and no way to open someone else's.
            ->call('openDay', $this->e['report']->id, '2026-09-10')
            ->assertForbidden();
    }

    public function test_attendance_view_given_by_hand_to_a_plain_employee_still_shows_only_their_own(): void
    {
        $this->setUpWorld();
        $user = $this->makeUser($this->e['peer']->city, 'employee', ['attendance.view-own', 'attendance.view'], $this->e['peer']);

        $this->assertSame(['peer'], $this->seen($user));
    }

    public function test_a_manager_sees_themselves_and_their_direct_reports_only(): void
    {
        $this->setUpWorld();
        $user = $this->makeUser($this->e['boss']->city, 'manager', ['attendance.view-own', 'attendance.view'], $this->e['boss']);

        $this->assertSame(Roster::TEAM, app(Roster::class)->mode($user));
        $this->assertSame(['boss', 'report'], $this->seen($user));

        Livewire::actingAs($user)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->assertSee('Dana Reporter')
            ->assertSee('Boss Manager')
            ->assertDontSee('Peter Peer')
            ->assertDontSee('Fay Faraway');

        // A manager does not upload, and does not decide for the team.
        $this->withoutVite();
        $this->actingAs($user)->get(route('attendance.upload'))->assertForbidden();
    }

    public function test_a_city_hr_role_sees_everyone_of_its_city_and_nobody_elses(): void
    {
        $this->setUpWorld();
        $user = $this->makeUser($this->e['boss']->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        $this->assertSame(Roster::CITY, app(Roster::class)->mode($user));
        $this->assertSame(['boss', 'peer', 'report'], $this->seen($user));
    }

    public function test_hr_syria_sees_everyone(): void
    {
        $this->setUpWorld();
        $user = $this->makeUser($this->e['boss']->city, 'HR-SYR', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        $this->assertSame(Roster::ALL, app(Roster::class)->mode($user));
        $this->assertSame(['boss', 'far', 'peer', 'report'], $this->seen($user));
    }

    public function test_the_printout_follows_the_same_scope(): void
    {
        $this->setUpWorld();
        $this->withoutVite();

        $employee = $this->makeUser($this->e['report']->city, 'employee', ['attendance.view-own'], $this->e['report']);
        $manager = $this->makeUser($this->e['boss']->city, 'manager', ['attendance.view-own', 'attendance.view'], $this->e['boss']);

        $this->actingAs($employee)->get(route('attendance.print', ['month' => '2026-09']))
            ->assertOk()->assertSee('Dana Reporter')->assertDontSee('Peter Peer')->assertDontSee('Boss Manager');

        $this->actingAs($manager)->get(route('attendance.print', ['month' => '2026-09']))
            ->assertOk()->assertSee('Dana Reporter')->assertSee('Boss Manager')->assertDontSee('Peter Peer');

        // Asking for someone outside the scope by id finds nothing.
        $this->actingAs($employee)->get(route('attendance.print', ['month' => '2026-09', 'employees' => [$this->e['peer']->id]]))->assertNotFound();
    }

    public function test_someone_outside_the_scope_cannot_be_opened_even_by_a_crafted_call(): void
    {
        $this->setUpWorld();
        $hr = $this->makeUser($this->e['boss']->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $this->e['far']->id, '2026-09-10')
            ->assertForbidden();
    }

    public function test_the_menu_places_attendance_where_each_role_looks_for_it(): void
    {
        $this->setUpWorld();
        $ids = fn (User $u) => collect(\App\Support\Navigation::for($u, false, false)['areas'])
            ->flatMap(fn ($a) => $a['items'])->pluck('id')->filter(fn ($id) => str_contains((string) $id, 'attendance'))->values()->all();

        $employee = $this->makeUser($this->e['report']->city, 'employee', ['attendance.view-own'], $this->e['report']);
        $manager = $this->makeUser($this->e['boss']->city, 'manager', ['attendance.view-own', 'attendance.view'], $this->e['boss']);
        $hr = $this->makeUser($this->e['boss']->city, 'HR-ALP', ['attendance.view-own', 'attendance.view']);

        $this->assertSame(['nav.my-space.attendance'], $ids($employee));
        $this->assertSame(['nav.my-team.attendance'], $ids($manager));
        $this->assertSame(['nav.hr.attendance'], $ids($hr));
    }

    private function setUpWorld(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 10));

        $aleppo = $this->makeCity('ALP', '001');
        $damascus = $this->makeCity('DMS', '002');

        $boss = $this->staff('boss', $aleppo, 'Boss', 'Manager');
        $report = $this->staff('report', $aleppo, 'Dana', 'Reporter', $boss);
        $this->staff('peer', $aleppo, 'Peter', 'Peer');
        $this->staff('far', $damascus, 'Fay', 'Faraway');
    }

    /** The page lists only people the uploaded reports hold punches for in the month. */
    private function withPunch(\App\Models\Employee $employee, string $at = '2026-09-02 08:00'): \App\Models\Employee
    {
        \App\Models\AttendancePunch::query()->create([
            'import_id' => \App\Models\AttendanceImport::query()->firstOrCreate(['file_name' => 'fixture.xls'])->id,
            'device_no' => (string) $employee->id,
            'employee_id' => $employee->id,
            'punched_at' => $at,
        ]);

        // Every working day of September answered, so the paper may be printed (nothing waits).
        for ($d = \Carbon\CarbonImmutable::create(2026, 9, 1); $d->month === 9; $d = $d->addDay()) {
            if (! in_array($d->dayOfWeek, [0, 6], true)) {
                \App\Models\AttendanceDayDecision::query()->updateOrCreate(['employee_id' => $employee->id, 'work_date' => $d->toDateString()], ['kind' => 'worked']);
            }
        }

        return $employee;
    }

    private function staff(string $key, City $city, string $first, string $last, ?Employee $manager = null): Employee
    {
        $position = $this->makePosition($city);

        if ($manager) {
            $position->forceFill(['direct_manager_id' => $this->e['boss']->contracts()->first()->position_id])->save();
        }

        $employee = $this->makeEmployee($city, $key, [
            'first_name_en' => $first, 'last_name_en' => $last, 'first_name_ar' => $first, 'last_name_ar' => $last,
        ]);
        $this->makeContractFor($employee, ['position_id' => $position->id]);
        $this->withPunch($employee);

        return $this->e[$key] = $employee;
    }

    private function seen(User $user): array
    {
        return app(Roster::class)->employees($user, CarbonImmutable::create(2026, 9, 1))
            ->pluck('username')->sort()->values()->all();
    }
}
