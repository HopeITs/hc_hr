<?php

namespace Tests\Feature;

use App\Console\Commands\CheckEmployeeIntegrity;
use App\Livewire\Employee\EmployeeTable;
use App\Models\City;
use App\Models\Employee;
use App\Notifications\EmployeeRecordsDisagree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * T8 (scenario run 2026-11-26): people, contracts, roles and positions that
 * disagree are listed nightly, HR Syria is told, and a deactivation can no
 * longer leave a running contract behind.
 */
class EmployeeIntegrityCheckTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-26 10:00:00');
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_deactivation_is_refused_while_a_contract_runs(): void
    {
        $employee = $this->makeEmployee($this->city, 't8.running');
        $this->makeContractFor($employee);

        Livewire::actingAs($this->makeUser($this->city, 'HR-ALP', ['employees.update']))
            ->test(EmployeeTable::class)
            ->call('toggleItemStatusConfirmed', ['id' => $employee->id]);

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_deactivation_goes_through_once_the_contract_has_ended(): void
    {
        $employee = $this->makeEmployee($this->city, 't8.ended');
        $this->makeContractFor($employee, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'is_active' => false]);

        Livewire::actingAs($this->makeUser($this->city, 'HR-ALP', ['employees.update']))
            ->test(EmployeeTable::class)
            ->call('toggleItemStatusConfirmed', ['id' => $employee->id]);

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_the_check_lists_every_case_writes_nothing_and_fails(): void
    {
        // An inactive employee whose role was never stood down (pre-2026-07-31).
        $departed = $this->makeEmployee($this->city, 't8.departed');
        $departedUser = $this->makeUser($this->city, 'employee', [], $departed);
        Employee::query()->whereKey($departed->id)->update(['is_active' => false]);

        // A contract past its end date that deactivate:contract did not close.
        $late = $this->makeContractFor($this->makeEmployee($this->city, 't8.late'), ['end_date' => '2026-10-08']);

        // A running contract of an inactive employee (the 2026-09-17 batch).
        $orphan = $this->makeEmployee($this->city, 't8.orphan');
        $orphanContract = $this->makeContractFor($orphan);
        Employee::query()->whereKey($orphan->id)->update(['is_active' => false]);

        $never = $this->makeEmployee($this->city, 't8.never');
        $future = $this->makeEmployee($this->city, 't8.future');
        $this->makeContractFor($future, ['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $ceo = $this->makePosition($this->city, ['title_en' => 'Chief Executive Officer']);
        $deputy = $this->makePosition($this->city, ['title_en' => 'Deputy Chief Executive Officer']);

        $findings = app(CheckEmployeeIntegrity::class)->findings();

        $this->assertSame([$departedUser->id], $findings['roles']);
        $this->assertSame([$late->id], $findings['ended']);
        $this->assertSame([$orphanContract->id], $findings['inactive']);
        $this->assertContains($never->id, $findings['never_contracted']);
        $this->assertContains($late->employee_id, $findings['last_contract_ended']);
        $this->assertNotContains($future->id, array_merge($findings['never_contracted'], $findings['last_contract_ended']));
        $this->assertContains($deputy->id, $findings['no_manager']);
        $this->assertNotContains($ceo->id, $findings['no_manager']);

        $this->artisan('employees:integrity-check', ['--check' => true])->assertExitCode(1);

        $this->assertTrue($departedUser->roles()->wherePivot('is_active', true)->exists());
        $this->assertTrue($late->fresh()->is_active);
    }

    /**
     * R2-3c: a future-dated termination keeps the contract active until its
     * date (R2-3), so one still active after that date means the nightly
     * deactivation did not run. The termination day itself is not late yet.
     */
    public function test_a_contract_still_active_after_its_termination_date_is_listed(): void
    {
        $missed = $this->makeContractFor($this->makeEmployee($this->city, 'r23c.missed'), [
            'end_date' => '2027-06-30', 'terminated_at' => '2026-11-25',
        ]);
        $tonight = $this->makeContractFor($this->makeEmployee($this->city, 'r23c.tonight'), [
            'end_date' => '2027-06-30', 'terminated_at' => '2026-11-26',
        ]);
        $later = $this->makeContractFor($this->makeEmployee($this->city, 'r23c.later'), [
            'end_date' => '2027-06-30', 'terminated_at' => '2026-12-15',
        ]);
        // makeContractFor saves through the observer; keep all three active as
        // a missed nightly run would leave them.
        $ids = [$missed->id, $tonight->id, $later->id];
        \App\Models\Contract::query()->whereKey($ids)->update(['is_active' => true]);

        $ended = app(CheckEmployeeIntegrity::class)->findings()['ended'];

        $this->assertContains($missed->id, $ended);
        $this->assertNotContains($tonight->id, $ended);
        $this->assertNotContains($later->id, $ended);
    }

    public function test_hr_syria_is_told_once_per_list_not_every_night(): void
    {
        Notification::fake();
        Cache::flush();
        $hrSyria = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR');
        $this->makeEmployee($this->city, 't8.never');

        $this->artisan('employees:integrity-check', ['--check' => true])->assertExitCode(1);
        $this->artisan('employees:integrity-check', ['--check' => true])->assertExitCode(1);

        Notification::assertSentToTimes($hrSyria, EmployeeRecordsDisagree::class, 1);

        $this->makeEmployee($this->city, 't8.never.2');
        $this->artisan('employees:integrity-check', ['--check' => true])->assertExitCode(1);

        Notification::assertSentToTimes($hrSyria, EmployeeRecordsDisagree::class, 2);
    }

    public function test_without_check_it_stands_down_the_roles_of_inactive_employees(): void
    {
        $departed = $this->makeEmployee($this->city, 't8.departed');
        $user = $this->makeUser($this->city, 'employee', [], $departed);
        Employee::query()->whereKey($departed->id)->update(['is_active' => false]);

        $this->artisan('employees:integrity-check')->assertExitCode(0);

        $this->assertFalse($user->roles()->wherePivot('is_active', true)->exists());
    }
}
