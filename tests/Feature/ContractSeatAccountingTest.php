<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Livewire\Contract\ContractEdit;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\TerminationReason;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * `positions.exist_emp` counts the position's active, non-draft contracts
 * (journeys.md §3.3 E4, §7 #1). ContractObserver is the only writer, so every
 * path that turns a contract on or off — HR terminating it, the nightly
 * deactivation, a draft, a delete — must move the counter by exactly one,
 * once, and never below zero.
 */
class ContractSeatAccountingTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_terminating_a_contract_through_the_edit_screen_frees_its_seat(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 1);
        $contract = $this->makeContract($fixtures, $position, 'terminated-edit');
        $reason = TerminationReason::query()->create(['title_en' => 'Resigned', 'title_ar' => 'استقالة']);

        $this->assertSame(1, $position->fresh()->exist_emp);
        $this->assertFalse($position->fresh()->canAssign());

        $this->mock(LeaveBalanceService::class)->shouldReceive('create');

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $this->composerPayload($fixtures, $contract, [
                'terminatedAt' => '2026-06-30',
                'terminationReasonId' => $reason->id,
            ]))
            ->assertHasNoErrors();

        $this->assertFalse($contract->fresh()->is_active);
        $this->assertSame(0, $position->fresh()->exist_emp);
        $this->assertTrue($position->fresh()->canAssign());
        $this->assertSame($this->activeRealContracts($position), $position->fresh()->exist_emp);
    }

    public function test_saving_a_terminated_contract_again_does_not_free_the_seat_twice(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 3);
        $this->makeContract($fixtures, $position, 'colleague');
        $contract = $this->makeContract($fixtures, $position, 'terminated-twice');

        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        $contract->refresh()->update(['reason_of_termination' => 'Moved away']);
        $contract->refresh()->update(['is_active' => false]);

        $this->assertSame(1, $position->fresh()->exist_emp);
    }

    public function test_clearing_the_termination_on_the_edit_screen_does_not_reclaim_the_seat(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 1);
        $contract = $this->makeContract($fixtures, $position, 'cleared-termination');
        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);
        $this->assertSame(0, $position->fresh()->exist_emp);

        $this->mock(LeaveBalanceService::class)->shouldReceive('create');

        // ContractEdit: clearing the date does not reactivate, so no seat moves.
        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract->fresh()])
            ->call('submit', $this->composerPayload($fixtures, $contract->fresh()))
            ->assertHasNoErrors();

        $this->assertNull($contract->fresh()->terminated_at);
        $this->assertFalse($contract->fresh()->is_active);
        $this->assertSame(0, $position->fresh()->exist_emp);
    }

    public function test_reactivating_a_contract_reclaims_its_seat_exactly_once(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 2);
        $contract = $this->makeContract($fixtures, $position, 'reactivated');
        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);
        $this->assertSame(0, $position->fresh()->exist_emp);

        $contract->refresh()->update(['terminated_at' => null, 'is_active' => true]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        // Already active: saving it active again claims nothing more.
        $contract->refresh()->update(['is_active' => true, 'weekly_hours' => 20]);
        $this->assertSame(1, $position->fresh()->exist_emp);
        $this->assertSame($this->activeRealContracts($position), $position->fresh()->exist_emp);
    }

    public function test_reactivating_onto_a_full_position_is_refused(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 1);
        $contract = $this->makeContract($fixtures, $position, 'reactivated-full');
        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);
        $this->makeContract($fixtures, $position, 'successor');
        $this->assertSame(1, $position->fresh()->exist_emp);

        try {
            $contract->refresh()->update(['terminated_at' => null, 'is_active' => true]);
            $this->fail('Reactivating onto a full position should be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($contract->fresh()->is_active);
        $this->assertSame(1, $position->fresh()->exist_emp);
    }

    public function test_the_daily_deactivation_frees_the_seat_of_an_ended_contract_once(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 3);
        $this->makeContract($fixtures, $position, 'still-running', ['end_date' => '2027-12-31']);
        $ended = $this->makeContract($fixtures, $position, 'ended', ['end_date' => '2026-12-31']);
        $this->assertSame(2, $position->fresh()->exist_emp);

        $this->travelTo('2027-01-02');
        $this->artisan('deactivate:contract')->assertSuccessful();

        $this->assertFalse($ended->fresh()->is_active);
        $this->assertSame(1, $position->fresh()->exist_emp);

        // A second run finds nothing to deactivate and changes nothing.
        $this->artisan('deactivate:contract')->assertSuccessful();
        $this->assertSame(1, $position->fresh()->exist_emp);
        $this->assertSame($this->activeRealContracts($position), $position->fresh()->exist_emp);
    }

    public function test_a_draft_claims_no_seat_and_frees_none(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 2);
        $this->makeContract($fixtures, $position, 'holder', ['end_date' => '2027-12-31']);
        $draft = $this->makeContract($fixtures, $position, 'draft', [
            'type' => ContractTypeEnum::Draft,
            'end_date' => '2026-12-31',
        ]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        // Terminated, ended by the nightly run, deleted: none of it touches the
        // seat a draft never held.
        $draft->update(['terminated_at' => '2026-06-30', 'is_active' => false]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        $draft->refresh()->update(['is_active' => true, 'terminated_at' => null]);
        $this->travelTo('2027-01-02');
        $this->artisan('deactivate:contract')->assertSuccessful();
        $this->assertSame(1, $position->fresh()->exist_emp);

        $draft->refresh()->delete();
        $this->assertSame(1, $position->fresh()->exist_emp);
    }

    public function test_a_contract_that_is_already_inactive_changes_nothing(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 2);
        $this->makeContract($fixtures, $position, 'holder');

        // Created inactive (an archived contract entered after the fact): no seat.
        $inactive = $this->makeContract($fixtures, $position, 'archived', ['is_active' => false]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        // Terminating it afterwards releases nothing.
        $inactive->update(['terminated_at' => '2026-06-30', 'termination_reason_id' => null]);
        $this->assertSame(1, $position->fresh()->exist_emp);

        // Nor does deleting it.
        $inactive->refresh()->delete();
        $this->assertSame(1, $position->fresh()->exist_emp);
    }

    public function test_deleting_an_active_contract_frees_its_seat(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 2);
        $contract = $this->makeContract($fixtures, $position, 'deleted');
        $this->assertSame(1, $position->fresh()->exist_emp);

        $contract->delete();

        $this->assertSame(0, $position->fresh()->exist_emp);
    }

    public function test_terminating_never_drives_the_counter_below_zero(): void
    {
        $fixtures = $this->makeFixtures();
        $position = $this->makePosition($fixtures, maxEmp: 1);
        $contract = $this->makeContract($fixtures, $position, 'drifted');

        // Live data that already drifted low.
        $position->forceFill(['exist_emp' => 0])->saveQuietly();

        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);

        $this->assertSame(0, $position->fresh()->exist_emp);
    }

    public function test_moving_and_deactivating_in_one_save_frees_only_the_old_seat(): void
    {
        $fixtures = $this->makeFixtures();
        $from = $this->makePosition($fixtures, maxEmp: 1);
        $to = $this->makePosition($fixtures, maxEmp: 1);
        $contract = $this->makeContract($fixtures, $from, 'moved-and-ended');

        $contract->update(['position_id' => $to->id, 'is_active' => false]);

        $this->assertSame(0, $from->fresh()->exist_emp);
        $this->assertSame(0, $to->fresh()->exist_emp);
    }

    public function test_recount_reports_and_repairs_a_drifted_counter_and_is_idempotent(): void
    {
        $fixtures = $this->makeFixtures();
        $drifted = $this->makePosition($fixtures, maxEmp: 2);
        $correct = $this->makePosition($fixtures, maxEmp: 2);
        $this->makeContract($fixtures, $drifted, 'seated');
        $this->makeContract($fixtures, $drifted, 'draft-only', ['type' => ContractTypeEnum::Draft]);
        $this->makeContract($fixtures, $correct, 'correct');

        // What the pre-fix observer left behind: a terminated contract still counted.
        $drifted->forceFill(['exist_emp' => 2])->saveQuietly();

        $this->artisan('positions:recount-seats', ['--dry-run' => true])
            ->expectsOutputToContain('#'.$drifted->id)
            ->assertSuccessful();
        $this->assertSame(2, $drifted->fresh()->exist_emp);

        $this->artisan('positions:recount-seats')->assertSuccessful();
        $this->assertSame(1, $drifted->fresh()->exist_emp);
        $this->assertSame(1, $correct->fresh()->exist_emp);
        $this->assertSame(2, $drifted->fresh()->max_emp);

        $this->artisan('positions:recount-seats')
            ->expectsOutputToContain('0 position')
            ->assertSuccessful();
        $this->assertSame(1, $drifted->fresh()->exist_emp);
    }

    // ────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────

    private function activeRealContracts(Position $position): int
    {
        return Contract::query()
            ->where('position_id', $position->id)
            ->where('is_active', true)
            ->where('type', '!=', ContractTypeEnum::Draft)
            ->count();
    }

    private function makeFixtures(): array
    {
        $city = $this->makeCity('ALP', '001');
        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $city->id,
            'is_active' => true,
            'weekends' => 'friday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $program = Program::query()->forceCreate(['name' => 'Core Program', 'slug' => 'core-program', 'code' => 'CORE', 'is_active' => true]);
        $annualType = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual', 'name_ar' => 'سنوي']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.update', 'contracts.view'], null);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'annualType', 'hr');
    }

    private function makePosition(array $fixtures, int $maxEmp): Position
    {
        static $counter = 0;
        $counter++;

        return Position::query()->forceCreate([
            'center_id' => $fixtures['center']->id,
            'city_id' => $fixtures['city']->id,
            'department_id' => $fixtures['department']->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'title_en' => 'Seat Position '.$counter,
            'title_ar' => 'Seat Position '.$counter,
            'max_emp' => $maxEmp,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }

    private function makeContract(array $fixtures, Position $position, string $username, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($fixtures['city'], $username);

        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => ContractTypeEnum::FullTime,
            'place' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 2,
            'salary' => 1000,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'work_hours_from' => '09:00:00',
            'work_hours_to' => '17:00:00',
            'is_active' => true,
        ], $overrides));
    }

    private function composerPayload(array $fixtures, Contract $contract, array $overrides = []): array
    {
        return array_merge([
            'cityId' => $fixtures['city']->id,
            'employeeId' => $contract->employee_id,
            'programId' => $fixtures['program']->id,
            'centerId' => $fixtures['center']->id,
            'positionId' => $contract->position_id,
            'contractTypeId' => $contract->contract_type_id,
            'type' => $contract->type,
            'place' => $contract->place,
            'startDate' => $contract->start_date->toDateString(),
            'endDate' => $contract->end_date->toDateString(),
            'scheduleMode' => 1,
            'workHoursFrom' => '09:00',
            'workHoursTo' => '17:00',
            'weeklyHours' => 40,
            'workHours2From' => '',
            'workHours2To' => '',
            'workDayFrom' => '',
            'workDayTo' => '',
            'showDaysPerWeek' => false,
            'showHoursPerWeek' => false,
            'showHoursPerDay' => false,
            'showDayRange' => false,
            'flexDaysPerWeek' => '',
            'flexHoursPerWeek' => '',
            'flexHoursPerMonth' => '',
            'flexVariesByRequirements' => false,
            'flexCoordinatedWithManager' => false,
            'flexCustomEn' => '',
            'flexCustomAr' => '',
            'payMode' => 1,
            'salary' => $contract->salary,
            'currency' => $contract->currency,
            'salaryPer' => $contract->salary_per,
            'sessionDuration' => '',
            'payTiers' => [],
            'terminatedAt' => '',
            'terminationReasonId' => '',
            'reasonOfTermination' => '',
        ], $overrides);
    }
}
