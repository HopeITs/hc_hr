<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessContractAutoRenewals;
use App\Console\Commands\SendContractExpirationWarnings;
use App\Livewire\Contract\ContractEdit;
use App\Livewire\Contract\ContractsTable;
use App\Models\Center;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\TerminationReason;
use App\Notifications\ContractsAutoRenewedSummary;
use App\Notifications\ContractsExpiringAutoRenewWarning;
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use stdClass;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ContractAutoRenewalWorkflowTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_warning_command_sends_single_contract_notification_to_direct_manager(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $manager = $this->makeManagerWithPosition($fixtures);
        $employee = $this->makeEmployee($fixtures['city'], 'single-expiring');
        $position = $this->makePosition($fixtures, 'Single Report', $manager['position']->id);

        $contract = $this->makeContract($fixtures, $employee, $position, [
            'end_date' => '2026-09-23',
            'auto_renew' => true,
        ]);

        Artisan::call(SendContractExpirationWarnings::class);

        Notification::assertSentTo(
            $manager['user'],
            ContractsExpiringAutoRenewWarning::class,
            function (ContractsExpiringAutoRenewWarning $notification, array $channels) use ($contract, $fixtures): bool {
                $data = $notification->toArray($fixtures['hr']);

                return in_array('database', $channels, true)
                    && in_array('mail', $channels, true)
                    && count($notification->contracts) === 1
                    && $notification->contracts[0]['id'] === $contract->id
                    && str_contains($data['body']['ar'], 'خاصية التجديد التلقائي مفعّلة')
                    && str_contains($data['body']['ar'], 'إلغاء خاصية التجديد التلقائي')
                    && str_contains($data['body']['ar'], '2026-09-23')
                    && ! array_key_exists('url', $data);
            },
        );
    }

    public function test_warning_command_batches_multiple_contracts_for_the_same_direct_manager(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $manager = $this->makeManagerWithPosition($fixtures);
        $first = $this->makeEmployee($fixtures['city'], 'batch-expiring-one', [
            'first_name_en' => 'Batch',
            'first_name_ar' => 'Batch',
            'last_name_en' => 'Expiring One',
            'last_name_ar' => 'Expiring One',
        ]);
        $second = $this->makeEmployee($fixtures['city'], 'batch-expiring-two', [
            'first_name_en' => 'Batch',
            'first_name_ar' => 'Batch',
            'last_name_en' => 'Expiring Two',
            'last_name_ar' => 'Expiring Two',
        ]);
        $firstPosition = $this->makePosition($fixtures, 'First Report', $manager['position']->id);
        $secondPosition = $this->makePosition($fixtures, 'Second Report', $manager['position']->id);

        $this->makeContract($fixtures, $first, $firstPosition, [
            'end_date' => '2026-09-23',
            'auto_renew' => true,
        ]);
        $this->makeContract($fixtures, $second, $secondPosition, [
            'end_date' => '2026-09-23',
            'auto_renew' => true,
        ]);

        Artisan::call(SendContractExpirationWarnings::class);

        Notification::assertSentTo(
            $manager['user'],
            ContractsExpiringAutoRenewWarning::class,
            function (ContractsExpiringAutoRenewWarning $notification, array $channels): bool {
                $data = $notification->toArray(new stdClass);

                return in_array('database', $channels, true)
                    && in_array('mail', $channels, true)
                    && count($notification->contracts) === 2
                    && str_contains($data['body']['ar'], 'العقود التالية لديها خاصية التجديد التلقائي مفعّلة')
                    && str_contains($data['body']['ar'], 'إلغاء خاصية التجديد التلقائي')
                    && str_contains($data['body']['ar'], 'Batch Expiring One')
                    && str_contains($data['body']['ar'], 'Batch Expiring Two')
                    && str_contains($data['body']['ar'], '2026-09-23')
                    && ! array_key_exists('url', $data);
            },
        );
    }

    public function test_warning_command_does_not_send_on_non_reminder_days(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $manager = $this->makeManagerWithPosition($fixtures);
        $employee = $this->makeEmployee($fixtures['city'], 'not-reminded');
        $position = $this->makePosition($fixtures, 'Not Reminded Report', $manager['position']->id);

        $this->makeContract($fixtures, $employee, $position, [
            'end_date' => '2026-09-24',
            'auto_renew' => true,
        ]);

        Artisan::call(SendContractExpirationWarnings::class);

        Notification::assertNothingSent();
    }

    public function test_warning_command_does_not_repeat_same_contract_on_same_day(): void
    {
        $this->travelTo('2026-09-16 09:00:00');

        $fixtures = $this->makeFixtures();
        $manager = $this->makeManagerWithPosition($fixtures);
        $employee = $this->makeEmployee($fixtures['city'], 'deduped-reminder');
        $position = $this->makePosition($fixtures, 'Deduped Reminder Report', $manager['position']->id);

        $this->makeContract($fixtures, $employee, $position, [
            'end_date' => '2026-09-23',
            'auto_renew' => true,
        ]);

        Artisan::call(SendContractExpirationWarnings::class);
        Artisan::call(SendContractExpirationWarnings::class);

        $this->assertSame(
            1,
            $manager['user']
                ->notifications()
                ->where('type', ContractsExpiringAutoRenewWarning::class)
                ->count(),
        );
    }

    public function test_warning_command_sends_weekly_reminders_during_last_thirty_days(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $manager = $this->makeManagerWithPosition($fixtures);
        $employee = $this->makeEmployee($fixtures['city'], 'weekly-reminded');
        $position = $this->makePosition($fixtures, 'Weekly Reminded Report', $manager['position']->id);

        $contract = $this->makeContract($fixtures, $employee, $position, [
            'end_date' => '2026-10-07',
            'auto_renew' => true,
        ]);

        Artisan::call(SendContractExpirationWarnings::class);

        Notification::assertSentTo(
            $manager['user'],
            ContractsExpiringAutoRenewWarning::class,
            fn (ContractsExpiringAutoRenewWarning $notification): bool => $notification->daysUntilExpiration === 21
                && $notification->contracts[0]['id'] === $contract->id,
        );
    }

    public function test_process_auto_renewals_duplicates_only_auto_renewable_expired_contracts(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $renewedEmployee = $this->makeEmployee($fixtures['city'], 'renewed-expired');
        $skippedEmployee = $this->makeEmployee($fixtures['city'], 'skipped-expired');
        $renewedPosition = $this->makePosition($fixtures, 'Renewed Position');
        $skippedPosition = $this->makePosition($fixtures, 'Skipped Position');

        $source = $this->makeContract($fixtures, $renewedEmployee, $renewedPosition, [
            'start_date' => '2026-01-01',
            'end_date' => '2026-09-15',
            'salary' => 1200,
            'is_active' => false,
            'auto_renew' => true,
        ]);
        $skipped = $this->makeContract($fixtures, $skippedEmployee, $skippedPosition, [
            'start_date' => '2026-01-01',
            'end_date' => '2026-09-15',
            'is_active' => false,
            'auto_renew' => false,
        ]);
        $activeExpired = $this->makeContract(
            $fixtures,
            $this->makeEmployee($fixtures['city'], 'active-expired'),
            $this->makePosition($fixtures, 'Active Expired Position'),
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-09-15',
                'is_active' => true,
                'auto_renew' => true,
            ],
        );

        Artisan::call(ProcessContractAutoRenewals::class);

        $renewal = Contract::query()
            ->where('employee_id', $renewedEmployee->id)
            ->where('id', '!=', $source->id)
            ->sole();

        $this->assertFalse($source->fresh()->is_active);
        $this->assertTrue($renewal->is_active);
        $this->assertTrue($renewal->auto_renew);
        $this->assertSame('2026-09-16', $renewal->start_date->toDateString());
        $this->assertSame('2026-12-31', $renewal->end_date->toDateString());
        $this->assertSame($source->position_id, $renewal->position_id);
        $this->assertSame('1200.00', $renewal->salary);

        $this->assertFalse($skipped->fresh()->is_active);
        $this->assertSame(1, Contract::query()->where('employee_id', $skippedEmployee->id)->count());
        $this->assertSame(1, Contract::query()->where('employee_id', $activeExpired->employee_id)->count());

        Notification::assertSentTo($fixtures['hr'], ContractsAutoRenewedSummary::class);
    }

    public function test_contracts_table_auto_renew_toggle_is_hr_only(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeEmployee($fixtures['city'], 'table-toggle');
        $position = $this->makePosition($fixtures, 'Table Toggle Position');
        $contract = $this->makeContract($fixtures, $employee, $position, ['auto_renew' => true]);
        $manager = $this->makeUser($fixtures['city'], 'manager', ['contracts.update', 'contracts.view']);

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractsTable::class)
            ->call('toggleAutoRenewConfirmed', ['id' => $contract->id])
            ->assertOk();

        $this->assertFalse($contract->fresh()->auto_renew);

        Livewire::actingAs($manager)
            ->test(ContractsTable::class)
            ->call('toggleAutoRenewConfirmed', ['id' => $contract->id])
            ->assertStatus(403);
    }

    public function test_contract_edit_auto_renew_payload_is_hr_only(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeEmployee($fixtures['city'], 'form-toggle');
        $position = $this->makePosition($fixtures, 'Form Toggle Position');
        $contract = $this->makeContract($fixtures, $employee, $position, ['auto_renew' => true]);
        $payload = $this->composerPayload($fixtures, $contract, ['autoRenew' => false]);

        $this->mock(LeaveBalanceService::class)
            ->shouldReceive('create')
            ->once();

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $payload)
            ->assertHasNoErrors();

        $this->assertFalse($contract->fresh()->auto_renew);

        Contract::query()
            ->whereKey($contract)
            ->update(['auto_renew' => true]);

        $contract = $contract->fresh();
        $payload = $this->composerPayload($fixtures, $contract, ['autoRenew' => false]);
        // Sees and edits every contract, but is not HR. (A manager no longer
        // fits here: they only reach their own reports' contracts at all.)
        $generalManager = $this->makeUser($fixtures['city'], 'general-manager', ['contracts.update', 'contracts.view']);

        Livewire::actingAs($generalManager)
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $payload)
            ->assertStatus(403);

        $this->assertTrue($contract->fresh()->auto_renew);
    }

    public function test_terminating_a_contract_switches_auto_renew_off(): void
    {
        $fixtures = $this->makeFixtures();
        $employee = $this->makeEmployee($fixtures['city'], 'terminated-edit');
        $position = $this->makePosition($fixtures, 'Terminated Edit Position');
        $contract = $this->makeContract($fixtures, $employee, $position, ['auto_renew' => true]);
        $reason = TerminationReason::query()->create(['title_en' => 'Resigned', 'title_ar' => 'استقالة']);

        // HR leaves the auto-renew switch on in the same submit: the
        // termination still wins.
        $payload = $this->composerPayload($fixtures, $contract, [
            'autoRenew' => true,
            'terminatedAt' => '2026-06-30',
            'terminationReasonId' => $reason->id,
        ]);

        $this->mock(LeaveBalanceService::class)->shouldReceive('create');

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->call('submit', $payload)
            ->assertHasNoErrors();

        $contract->refresh();
        $this->assertSame('2026-06-30', $contract->terminated_at->toDateString());
        $this->assertFalse($contract->is_active);
        $this->assertFalse($contract->auto_renew);
    }

    public function test_contract_card_has_a_termination_row_shown_only_when_terminated(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeContract(
            $fixtures,
            $this->makeEmployee($fixtures['city'], 'terminated-card'),
            $this->makePosition($fixtures, 'Terminated Card Position'),
        );

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractEdit::class, ['contract' => $contract])
            ->assertSeeHtml('x-show="isTerminated"')
            ->assertSeeHtml('x-text="cardTerminated"');
    }

    public function test_a_termination_saved_on_the_model_switches_auto_renew_off(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeContract(
            $fixtures,
            $this->makeEmployee($fixtures['city'], 'terminated-model'),
            $this->makePosition($fixtures, 'Terminated Model Position'),
            ['auto_renew' => true],
        );

        $contract->update(['terminated_at' => '2026-06-30', 'is_active' => false]);

        $this->assertFalse($contract->fresh()->auto_renew);
    }

    public function test_process_auto_renewals_skips_terminated_contracts(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        Notification::fake();

        $fixtures = $this->makeFixtures();
        $employee = $this->makeEmployee($fixtures['city'], 'terminated-expired');
        $contract = $this->makeContract($fixtures, $employee, $this->makePosition($fixtures, 'Terminated Expired Position'), [
            'start_date' => '2026-01-01',
            'end_date' => '2026-09-15',
            'is_active' => false,
        ]);

        // Legacy row: terminated before the observer forced the flag off.
        Contract::query()->whereKey($contract)->update([
            'terminated_at' => '2026-05-01',
            'auto_renew' => true,
        ]);

        Artisan::call(ProcessContractAutoRenewals::class);

        $this->assertSame(1, Contract::query()->where('employee_id', $employee->id)->count());
    }

    public function test_contracts_table_shows_termination_date_and_hides_auto_renew_toggle(): void
    {
        $fixtures = $this->makeFixtures();
        $contract = $this->makeContract(
            $fixtures,
            $this->makeEmployee($fixtures['city'], 'terminated-table'),
            $this->makePosition($fixtures, 'Terminated Table Position'),
            ['terminated_at' => '2026-06-30', 'is_active' => false],
        );

        Livewire::actingAs($fixtures['hr'])
            ->test(ContractsTable::class)
            ->assertSee('2026-06-30')
            ->assertDontSee('confirmToggleAutoRenew')
            ->call('toggleAutoRenewConfirmed', ['id' => $contract->id])
            ->assertStatus(403);

        $this->assertFalse($contract->fresh()->auto_renew);
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
        ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Service Agreement', 'name_ar' => 'اتفاقية خدمة']);
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.update', 'contracts.view'], null);
        $hr->update(['email' => 'hr.alp@example.test']);

        return compact('city', 'center', 'department', 'jobLevel', 'program', 'annualType', 'hr');
    }

    private function makeManagerWithPosition(array $fixtures): array
    {
        $employee = $this->makeEmployee($fixtures['city'], 'direct-manager');
        $position = $this->makePosition($fixtures, 'Direct Manager');
        $user = $this->makeUser($fixtures['city'], 'manager', [], $employee);
        $this->makeContract($fixtures, $employee, $position, [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'auto_renew' => false,
        ]);

        return compact('employee', 'position', 'user');
    }

    private function makePosition(array $fixtures, string $title, ?int $directManagerId = null): Position
    {
        return Position::query()->forceCreate([
            'center_id' => $fixtures['center']->id,
            'city_id' => $fixtures['city']->id,
            'department_id' => $fixtures['department']->id,
            'job_level_id' => $fixtures['jobLevel']->id,
            'program_id' => $fixtures['program']->id,
            'direct_manager_id' => $directManagerId,
            'title_en' => $title,
            'title_ar' => $title,
            'max_emp' => 5,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
    }

    private function makeContract(array $fixtures, $employee, Position $position, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'contract_type_id' => $fixtures['annualType']->id,
            'type' => 1,
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
