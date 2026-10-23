<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Employee\EmployeeTable;
use App\Models\TaskAttempt;
use App\Services\TaskTelemetry;
use App\Support\TaskAttemptSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * X2-I: anonymous task-attempt telemetry (DEC-10). Leave request (A) and leave
 * review (C) are exercised in LeaveRequestSubmitFlowTest and
 * LeaveRequestWorkflowTest, next to their own fixtures.
 */
class TaskTelemetryTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telemetry.enabled' => true]);
    }

    public function test_the_table_has_no_column_that_could_identify_a_person_or_record(): void
    {
        // DEC-10: anonymous. Adding a column here is a privacy decision, not a refactor.
        $this->assertEqualsCanonicalizing(
            ['id', 'attempt_uuid', 'task', 'role_context', 'locale', 'started_at', 'finished_at', 'duration_ms', 'outcome', 'had_validation_error'],
            Schema::getColumnListing('task_attempts'),
        );
    }

    public function test_nothing_is_recorded_while_telemetry_is_off(): void
    {
        config(['telemetry.enabled' => false]);
        $telemetry = app(TaskTelemetry::class);

        $id = $telemetry->start('leave_request');
        $telemetry->finish($id, 'completed');
        $telemetry->startLookup();

        $this->assertNull($id);
        $this->assertSame(0, TaskAttempt::query()->count());
        $this->assertFalse(session()->has(TaskTelemetry::LOOKUP_KEY));
    }

    public function test_it_is_off_unless_the_environment_turns_it_on(): void
    {
        $this->assertFalse((bool) (require config_path('telemetry.php'))['enabled']);
    }

    public function test_only_the_first_outcome_counts(): void
    {
        $telemetry = app(TaskTelemetry::class);
        $id = $telemetry->start('leave_review');

        $telemetry->finish($id, 'completed');
        $telemetry->finish($id, 'cancelled'); // a retried or late request
        $telemetry->flagValidationError($id); // after the end: ignored

        $attempt = TaskAttempt::query()->sole();
        $this->assertSame('completed', $attempt->outcome);
        $this->assertFalse($attempt->had_validation_error);
        $this->assertNotNull($attempt->duration_ms);
        $this->assertNotNull($attempt->finished_at);
    }

    public function test_unknown_tasks_and_outcomes_are_programming_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TaskTelemetry::class)->start('salary_lookup');
    }

    public function test_the_role_is_coarsened_and_never_names_a_city(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP');
        $telemetry = app(TaskTelemetry::class);

        $this->assertSame('hr', $telemetry->roleContext($hr));
        $this->assertSame('employee', $telemetry->roleContext($this->makeUser($city, 'employee')));
        $this->assertSame('admin', $telemetry->roleContext($this->makeUser($city, 'super-admin')));
        $this->assertSame('guest', $telemetry->roleContext(null));

        app()->setLocale('ar');
        $telemetry->start('employee_lookup', $hr);

        $this->assertDatabaseHas('task_attempts', ['role_context' => 'hr', 'locale' => 'ar']);
    }

    public function test_a_synthetic_series_reconciles_to_its_attempts_without_double_counting(): void
    {
        // Task-plan step 4: start / success / failure / retry, plus an abandoned
        // and an in-progress attempt.
        config(['telemetry.min_cell' => 1]);
        $telemetry = app(TaskTelemetry::class);
        $this->travelTo(now()->startOfMinute());

        $ok = $telemetry->start('leave_request');            // success
        $failedThenRetried = $telemetry->start('leave_request');
        $telemetry->flagValidationError($failedThenRetried);  // failure ...
        $telemetry->flagValidationError($failedThenRetried);  // ... seen twice, flagged once
        $cancelled = $telemetry->start('leave_request');
        $abandoned = $telemetry->start('leave_request');

        $this->travel(2)->minutes();
        $telemetry->finish($ok, 'completed');
        $telemetry->finish($ok, 'completed');                // double click
        $telemetry->finish($failedThenRetried, 'completed');  // ... retry succeeds
        $telemetry->finish($cancelled, 'cancelled');

        $this->travel(40)->minutes();                          // past the 30-minute window
        $telemetry->start('leave_request');                    // still in progress: excluded

        $all = collect(app(TaskAttemptSummary::class)->summarise(now()->subDay(), now()))
            ->firstWhere(fn ($row) => $row['task'] === 'leave_request' && $row['role_context'] === 'all');

        $this->assertSame(4, $all['n']);
        $this->assertSame(2, $all['n_completed']);
        $this->assertSame(1, $all['n_cancelled']);
        $this->assertSame(1, $all['n_abandoned']);
        $this->assertSame($all['n'], $all['n_completed'] + $all['n_cancelled'] + $all['n_abandoned']);
        $this->assertSame('2/4 (50%)', $all['completion_rate']);
        $this->assertSame('1/4 (25%)', $all['validation_error_rate']);
        $this->assertSame('120 s', $all['median_duration']);
        $this->assertSame(5, TaskAttempt::query()->count());
        $this->assertNull(TaskAttempt::query()->where('attempt_uuid', $abandoned)->value('outcome'));
    }

    public function test_small_groups_are_hidden_in_the_report(): void
    {
        $telemetry = app(TaskTelemetry::class);
        $telemetry->finish($telemetry->start('leave_review'), 'completed');
        $telemetry->finish($telemetry->start('leave_review'), 'completed');

        $all = collect(app(TaskAttemptSummary::class)->summarise(now()->subDay(), now()))
            ->firstWhere(fn ($row) => $row['task'] === 'leave_review' && $row['role_context'] === 'all');

        $this->assertTrue($all['suppressed']);
        $this->assertSame('<5', $all['attempts']);
        $this->assertSame('<5', $all['completion_rate']);
        $this->assertSame('<5', $all['median_duration']);
    }

    public function test_attempts_older_than_ninety_days_are_deleted(): void
    {
        $telemetry = app(TaskTelemetry::class);
        $this->travelTo(now()->subDays(91));
        $telemetry->start('contract_create');
        $this->travelBack();
        $telemetry->start('contract_create');

        $this->artisan('telemetry:prune')->assertSuccessful();

        $this->assertSame(1, TaskAttempt::query()->count());
    }

    public function test_the_report_command_runs(): void
    {
        $this->artisan('telemetry:report', ['--days' => 7])->assertSuccessful();
    }

    public function test_a_search_then_opening_a_profile_is_one_completed_lookup(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.view']);
        $colleague = $this->makeEmployee($city, 'lookup-target');

        Livewire::actingAs($hr)->test(EmployeeTable::class)
            ->set('globalSearch', 'Test')
            ->set('globalSearch', 'Test Person'); // typing on: still the same attempt

        $this->assertSame(1, TaskAttempt::query()->where('task', 'employee_lookup')->count());

        Livewire::actingAs($hr)->test(EmployeeProfile::class, ['employee' => $colleague]);

        $attempt = TaskAttempt::query()->sole();
        $this->assertSame('completed', $attempt->outcome);
        $this->assertSame('hr', $attempt->role_context);
        $this->assertFalse(session()->has(TaskTelemetry::LOOKUP_KEY));
    }

    public function test_a_lookup_left_open_too_long_is_not_continued(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['employees.view']);

        Livewire::actingAs($hr)->test(EmployeeTable::class)->set('globalSearch', 'Test');
        $this->travel(31)->minutes();
        Livewire::actingAs($hr)->test(EmployeeTable::class)->set('globalSearch', 'Other');

        $this->assertSame(2, TaskAttempt::query()->where('task', 'employee_lookup')->count());
    }

    public function test_opening_the_contract_composer_starts_a_contract_attempt(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create']);

        $component = Livewire::actingAs($hr)->test(ContractCreate::class);

        $this->assertNotNull($component->get('taskAttemptId'));
        $this->assertDatabaseHas('task_attempts', ['task' => 'contract_create', 'role_context' => 'hr', 'outcome' => null]);
    }

    public function test_the_browser_cannot_call_the_telemetry_hooks(): void
    {
        $city = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['contracts.create']);

        $this->expectException(DirectlyCallingLifecycleHooksNotAllowedException::class);

        Livewire::actingAs($hr)->test(ContractCreate::class)->call('dehydrateTracksTaskAttempt');
    }
}
