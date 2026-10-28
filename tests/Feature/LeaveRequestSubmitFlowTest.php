<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\TaskAttempt;
use App\Models\User;
use App\Notifications\NewLeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A3 steps 3-4: field-linked errors that keep the typed input, the real
 * status and next actor after a send, and one row plus one set of
 * notifications however often the same save arrives.
 */
class LeaveRequestSubmitFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $manager;

    private User $hr;

    private LeaveBalance $annual;

    private LeaveBalance $sick;

    private LeaveRequest $pending;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 October 2026: the dates below are fixed working days
        // (weekend 0,6), so the weekend and December rules (A3-D) never
        // depend on the day the suite runs.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->createFixture();
    }

    public function test_reversed_dates_are_reported_on_the_end_date_and_keep_the_input(): void
    {
        $start = now()->addMonth()->addDays(3)->toDateString();
        $end = now()->addMonth()->toDateString();

        $component = $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', $start)
            ->set('form.end_at', $end)
            ->set('form.reason', 'Reversed dates must not be lost.')
            ->call('confirmSave')
            ->assertHasErrors(['form.end_at' => 'after_or_equal'])
            ->assertHasNoErrors(['form.start_at'])
            ->assertNotDispatched('confirm-dialog')
            ->assertSet('showModal', true)
            ->assertSet('form.start_at', $start)
            ->assertSet('form.end_at', $end)
            ->assertSet('form.reason', 'Reversed dates must not be lost.')
            ->assertSet('form.leave_balance_id', (string) $this->sick->id)
            ->assertSee(__('leaves.validation.end-before-start'));

        $this->assertSame(
            __('leaves.validation.end-before-start'),
            $component->errors()->first('form.end_at'),
        );

        $before = LeaveRequest::query()->count();
        $component->call('processSave')->assertHasErrors(['form.end_at']);
        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_an_own_overlap_is_reported_on_the_start_date_with_the_conflicting_dates(): void
    {
        $start = $this->pending->start_at->toDateString();
        $end = $this->pending->end_at->copy()->addDays(2)->toDateString();
        $before = LeaveRequest::query()->count();

        $component = $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', $start)
            ->set('form.end_at', $end)
            ->set('form.reason', 'Overlaps the pending annual leave.')
            ->call('confirmSave')
            ->assertHasErrors(['form.start_at'])
            ->assertNotDispatched('confirm-dialog')
            ->assertSet('form.start_at', $start)
            ->assertSet('form.end_at', $end)
            ->assertSet('form.reason', 'Overlaps the pending annual leave.');

        $message = $component->errors()->first('form.start_at');
        $this->assertStringContainsString($this->pending->start_at->format('d-m-Y'), $message);
        $this->assertStringContainsString($this->pending->end_at->format('d-m-Y'), $message);
        $this->assertStringContainsString(__('leaves.status.pending'), $message);

        // The write path checks again, even without the dialog.
        $component->call('processSave')
            ->assertHasErrors(['form.start_at'])
            ->assertSet('form.start_at', $start)
            ->assertSet('showModal', true);

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_an_hourly_overlap_is_reported_on_the_leave_date_and_rejected_requests_do_not_block(): void
    {
        $day = $this->pending->start_at->toDateString();

        $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->set('form.type', 'hour')
            ->set('form.leave_date', $day)
            ->set('form.start_hour', '10:00')
            ->set('form.end_hour', '12:00')
            ->set('form.reason', 'Hours inside a pending day leave.')
            ->call('processSave')
            ->assertHasErrors(['form.leave_date'])
            ->assertSet('form.start_hour', '10:00')
            ->assertSet('form.type', 'hour');

        // A rejected request on the same days is not a commitment.
        $this->pending->update(['status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'No.']);

        $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', $this->pending->start_at->toDateString())
            ->set('form.end_at', $this->pending->end_at->toDateString())
            ->set('form.reason', 'Same days as a rejected request.')
            ->call('processSave')
            ->assertHasNoErrors();
    }

    public function test_editing_a_request_does_not_conflict_with_itself(): void
    {
        Notification::fake();

        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('edit', $this->pending->id)
            ->set('form.reason', 'Edited without moving the dates.')
            ->call('confirmSave')
            ->assertHasNoErrors()
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertSet('submittedLeaveId', $this->pending->id)
            ->assertSet('submittedKind', 'updated');

        $this->assertSame('Edited without moving the dates.', $this->pending->fresh()->reason);
        Notification::assertNothingSent();
    }

    public function test_a_partial_form_reports_only_required_fields(): void
    {
        $this->openNewRequest()
            ->set('form.reason', 'Only a reason.')
            ->call('confirmSave')
            ->assertHasErrors([
                'form.leave_balance_id' => 'required',
                'form.start_at' => 'required',
                'form.end_at' => 'required',
            ])
            ->assertHasNoErrors(['form.reason'])
            ->assertNotDispatched('confirm-dialog')
            ->assertSet('form.reason', 'Only a reason.')
            ->assertSet('showModal', true);
    }

    public function test_the_same_save_sent_twice_creates_one_request_and_one_set_of_notifications(): void
    {
        Notification::fake();
        $before = LeaveRequest::query()->count();

        $component = $this->fillValidNewRequest();
        $token = $component->get('form.submission_token');
        $this->assertNotEmpty($token);

        // Double click: the second call arrives after the first closed the form.
        $component->call('processSave')
            ->assertHasNoErrors()
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $this->assertSame($before + 1, LeaveRequest::query()->count());
        $created = LeaveRequest::query()->latest('id')->firstOrFail();

        // Retry of the original payload after a lost response: same token,
        // same input, form still open in that snapshot.
        $this->fillValidNewRequest()
            ->set('form.submission_token', $token)
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertSet('submittedLeaveId', $created->id);

        $this->assertSame($before + 1, LeaveRequest::query()->count());
        Notification::assertSentToTimes($this->manager, NewLeaveRequest::class, 1);
        Notification::assertSentToTimes($this->hr, NewLeaveRequest::class, 1);
        Notification::assertCount(2);
    }

    public function test_a_second_tab_with_its_own_token_cannot_send_the_same_dates_again(): void
    {
        Notification::fake();
        $before = LeaveRequest::query()->count();

        $this->fillValidNewRequest()->call('processSave')->assertHasNoErrors();

        $this->fillValidNewRequest()
            ->call('processSave')
            ->assertHasErrors(['form.start_at'])
            ->assertSet('showModal', true);

        $this->assertSame($before + 1, LeaveRequest::query()->count());
        Notification::assertCount(2);
    }

    public function test_a_failed_save_can_be_retried_with_the_same_form(): void
    {
        Notification::fake();
        $before = LeaveRequest::query()->count();

        $component = $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', $this->pending->start_at->toDateString())
            ->set('form.end_at', $this->pending->end_at->toDateString())
            ->set('form.reason', 'First try overlaps, second does not.')
            ->call('processSave')
            ->assertHasErrors(['form.start_at']);

        $component
            ->set('form.start_at', '2026-11-25')
            ->set('form.end_at', '2026-11-25')
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $this->assertSame($before + 1, LeaveRequest::query()->count());
        Notification::assertSentToTimes($this->manager, NewLeaveRequest::class, 1);
    }

    public function test_success_shows_the_pending_status_and_the_real_next_actors(): void
    {
        Notification::fake();

        $component = $this->fillValidNewRequest()
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($event, $params) => str_contains(
                $params['message'] ?? '',
                __('leaves.status.pending'),
            ));

        $created = LeaveRequest::query()->latest('id')->firstOrFail();
        $this->assertSame(LeaveRequestStatus::Pending, $created->status);

        $component
            ->assertSet('submittedLeaveId', $created->id)
            ->assertSeeHtml('data-testid="leave-submitted-result"')
            ->assertSee(__('leaves.submit.sent-title'))
            ->assertSee(__('leaves.status.pending'))
            ->assertSee('Rami Haddad')
            ->assertSee('Aleppo')
            ->assertSee('HR team');
    }

    public function test_the_result_is_worded_in_arabic_when_the_locale_is_arabic(): void
    {
        Notification::fake();
        app()->setLocale('ar');

        $this->fillValidNewRequest()
            ->call('processSave')
            ->assertHasNoErrors()
            ->assertSee('أُرسل طلب إجازتك')
            ->assertSee('قيد المراجعة')
            ->assertSee('رامي حداد')
            ->assertSee('فريق الموارد البشرية في')
            ->assertSee('حلب');
    }

    public function test_a_direct_manager_without_the_manager_role_is_still_named(): void
    {
        $this->manager->removeRole('manager');

        // DEC-05 revision 2 (G-C-1): Rami holds the manager position, which is
        // the authority whatever his role, so he is still the one who decides.
        $this->openDetailsFor($this->pending)
            ->assertSee(__('leaves.next-step.title'))
            ->assertSee('Waiting for a decision from your manager')
            ->assertDontSee(__('leaves.next-step.pending-hr', ['city' => 'Aleppo']));
    }

    public function test_details_show_who_decided_and_the_remaining_hr_step(): void
    {
        $this->pending->update([
            'status' => LeaveRequestStatus::Accepted,
            'approved_by' => $this->manager->employee_id,
            'approved_at' => now(),
            'hr_approved' => false,
        ]);

        $this->openDetailsFor($this->pending)
            ->assertSeeHtml('data-testid="leave-next-step"')
            ->assertSee('Rami Haddad')
            ->assertSee(__('leaves.next-step.awaiting-hr'));

        $this->pending->update(['hr_approved' => true]);

        $this->openDetailsFor($this->pending)
            ->assertSee(__('leaves.next-step.hr-seen'));

        $this->pending->update([
            'status' => LeaveRequestStatus::Rejected,
            'reject_reason' => 'Busy week.',
            'hr_approved' => false,
        ]);

        $this->openDetailsFor($this->pending)
            ->assertSee(__('leaves.status.rejected'))
            ->assertSee(__('leaves.next-step.awaiting-hr'));
    }

    public function test_switching_away_from_annual_resets_the_hour_unit_but_keeps_the_input(): void
    {
        $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->annual->id)
            ->set('form.type', 'hour')
            ->set('form.reason', 'Keep me.')
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->assertSet('form.type', 'day')
            ->assertSet('form.reason', 'Keep me.');
    }

    public function test_x2i_a_sent_request_is_one_completed_attempt_flagged_by_its_earlier_error(): void
    {
        config(['telemetry.enabled' => true]);
        Notification::fake();

        $component = $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', '2026-11-24')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'Reversed first, then fixed.')
            ->call('confirmSave')
            ->assertHasErrors(['form.end_at']);

        $component->set('form.start_at', '2026-11-23')
            ->call('processSave')
            ->assertHasNoErrors()
            ->call('processSave'); // double click after the form closed

        $attempt = TaskAttempt::query()->sole();
        $this->assertSame('leave_request', $attempt->task);
        $this->assertSame('completed', $attempt->outcome);
        $this->assertTrue($attempt->had_validation_error);
        // This fixture user holds permissions but no role.
        $this->assertSame('none', $attempt->role_context);
    }

    public function test_x2i_reopening_the_form_closes_the_earlier_attempt_as_cancelled(): void
    {
        config(['telemetry.enabled' => true]);

        $this->openNewRequest()->call('create');

        $this->assertSame(['cancelled', null], TaskAttempt::query()->orderBy('id')->pluck('outcome')->all());
    }

    private function openNewRequest()
    {
        return Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->assertSet('showModal', true);
    }

    private function fillValidNewRequest()
    {
        return $this->openNewRequest()
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', '2026-11-23')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'A valid one-day request.');
    }

    private function openDetailsFor(LeaveRequest $leaveRequest)
    {
        return Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('openDetails', $leaveRequest->id)
            ->assertSet('detailsModal', true);
    }

    /**
     * Schema-level fixture, as in LeaveSelectorStateTest: a staff member in
     * Aleppo with annual and sick balances and one pending annual request, a
     * direct manager holding the manager role, and one active HR-ALP account.
     */
    private function createFixture(): void
    {
        $now = now();
        $cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $programId = DB::table('programs')->insertGetId([
            'name' => 'Program', 'code' => 'PRG', 'slug' => 'program', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center', 'name_ar' => 'مركز', 'weekends' => '0,6', 'city_id' => $cityId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Department', 'code' => 'DEP', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $jobLevelId = DB::table('job_levels')->insertGetId([
            'name_en' => 'Officer', 'name_ar' => 'موظف', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $position = fn (string $en, string $ar, ?int $managerPositionId = null) => DB::table('positions')->insertGetId([
            'program_id' => $programId, 'center_id' => $centerId, 'city_id' => $cityId,
            'job_level_id' => $jobLevelId, 'department_id' => $departmentId,
            'title_en' => $en, 'title_ar' => $ar, 'direct_manager_id' => $managerPositionId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $employee = fn (string $firstEn, string $firstAr, string $lastEn, string $lastAr, string $username) => DB::table('employees')->insertGetId([
            'city_id' => $cityId, 'first_name_en' => $firstEn, 'first_name_ar' => $firstAr,
            'last_name_en' => $lastEn, 'last_name_ar' => $lastAr, 'username' => $username,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $contract = fn (int $employeeId, int $positionId) => DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId, 'position_id' => $positionId, 'contract_type_id' => $contractTypeId,
            'start_date' => $now->copy()->subMonth(), 'end_date' => $now->copy()->addYear(),
            'currency' => 2, 'weekly_hours' => 40, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $managerPositionId = $position('Manager', 'مدير');
        $staffPositionId = $position('Officer', 'موظف', $managerPositionId);

        $managerEmployeeId = $employee('Rami', 'رامي', 'Haddad', 'حداد', 'rami-manager');
        $contract($managerEmployeeId, $managerPositionId);

        $staffEmployeeId = $employee('Test', 'اختبار', 'Employee', 'موظف', 'submit-flow-employee');
        $this->contractId = $contract($staffEmployeeId, $staffPositionId);

        $hrEmployeeId = $employee('Hana', 'هناء', 'HR', 'موارد', 'hana-hr');

        DB::table('leave_types')->insert([
            [
                'id' => LeaveTypeCases::Annual, 'name_en' => 'Annual Leave', 'name_ar' => 'إجازة سنوية',
                'base_days' => 20, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => LeaveTypeCases::Annual + 1, 'name_en' => 'Sick Leave', 'name_ar' => 'إجازة مرضية',
                'base_days' => 15, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $annualId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $this->contractId, 'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sickId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $this->contractId, 'type_id' => LeaveTypeCases::Annual + 1,
            'deserved' => 120, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Two working days next week (Monday-Tuesday), so the sick-leave
        // maximum and weekends never interfere.
        $monday = $now->copy()->next('Monday')->addWeek()->startOfDay();
        $pendingId = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $annualId, 'type' => 'day',
            'start_at' => $monday, 'end_at' => $monday->copy()->addDay(),
            'reason' => 'Pending annual leave.', 'status' => LeaveRequestStatus::Pending,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->user = User::query()->create([
            'employee_id' => $staffEmployeeId, 'email' => 'submit.flow@example.test', 'password' => 'password',
        ]);
        $this->user->givePermissionTo(['leaves.create', 'leaves.update']);

        $this->manager = User::query()->create([
            'employee_id' => $managerEmployeeId, 'email' => 'rami.manager@example.test', 'password' => 'password',
        ]);
        $this->manager->assignRole(Role::findOrCreate('manager', 'web'));

        $this->hr = User::query()->create([
            'employee_id' => $hrEmployeeId, 'email' => 'hana.hr@example.test', 'password' => 'password',
        ]);
        $this->hr->assignRole(Role::findOrCreate('HR-ALP', 'web'));

        $this->annual = LeaveBalance::query()->findOrFail($annualId);
        $this->sick = LeaveBalance::query()->findOrFail($sickId);
        $this->pending = LeaveRequest::query()->findOrFail($pendingId);
    }
}
