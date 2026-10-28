<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\AllLeaveRequestTable;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestTable;
use App\Livewire\Notifications\Dropdown as NotificationsDropdown;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Models\LeaveRequest;
use App\Models\TaskAttempt;
use App\Models\User;
use App\Notifications\ActionLeaveRequest;
use App\Notifications\HRSawLeaveRequest;
use App\Notifications\NewLeaveRequest;
use App\Services\LeaveNotificationService;
use App\Services\NotificationRequestState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_view_the_rejection_reason_for_their_own_leave(): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture('Operational coverage is unavailable.');

        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('openDetails', $leaveRequest->id)
            ->assertSet('selectedLeaveId', $leaveRequest->id)
            ->assertSet('detailsModal', true)
            ->assertSee(__('general.reject-reason'))
            ->assertSee('Operational coverage is unavailable.');
    }

    public function test_my_leave_table_exposes_the_details_action_for_rejected_leaves(): void
    {
        [$user] = $this->createRejectedLeaveFixture('A reason the employee should be able to open.');

        Livewire::actingAs($user)
            ->test(MyLeaveRequestTable::class)
            ->assertSeeHtml('openMyLeaveDetails');
    }

    public function test_employee_cannot_view_another_employees_leave_details(): void
    {
        [, $leaveRequest] = $this->createRejectedLeaveFixture('Private rejection reason.');
        [$otherUser] = $this->createRejectedLeaveFixture(
            'Different employee reason.',
            'other.employee@example.test',
            'other-employee',
        );

        Livewire::actingAs($otherUser)
            ->test(MyLeaveRequestModule::class)
            ->call('openDetails', $leaveRequest->id)
            ->assertNotFound();
    }

    public function test_hr_details_show_rejection_reason_status_and_decision_time(): void
    {
        $reason = 'The requested dates conflict with mandatory coverage.';
        [, $leaveRequest] = $this->createRejectedLeaveFixture($reason);
        $hr = $this->createHrUserForLeave($leaveRequest, 'details.hr@example.test');

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('show', $leaveRequest->id)
            ->assertSet('showModal', true)
            ->assertSee(LeaveRequestStatus::getLocalizedName(LeaveRequestStatus::Rejected)['value'])
            ->assertSee(__('general.reject-reason'))
            ->assertSee($reason)
            ->assertSee(__('general.decision-date'))
            ->assertSee($leaveRequest->approved_at->format('d-m-Y H:i'));
    }

    public function test_hr_details_do_not_show_rejection_panel_for_an_approved_leave(): void
    {
        [, $leaveRequest] = $this->createRejectedLeaveFixture('Stale reason.');
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Accepted,
            'reject_reason' => null,
        ]);
        $hr = $this->createHrUserForLeave($leaveRequest, 'approved.details.hr@example.test');

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('show', $leaveRequest->id)
            ->assertSee(LeaveRequestStatus::getLocalizedName(LeaveRequestStatus::Accepted)['value'])
            ->assertSee(__('general.decision-date'))
            ->assertDontSee(__('general.reject-reason'));
    }

    public function test_employee_leave_confirmation_uses_the_selected_balance_without_crashing(): void
    {
        [$employee, $existingLeave] = $this->createRejectedLeaveFixture(null);

        Livewire::actingAs($employee)
            ->test(MyLeaveRequestModule::class)
            ->set('form.leave_balance_id', $existingLeave->leave_balance_id)
            ->set('form.type', 'day')
            ->set('form.start_at', self::workingMonday()->toDateString())
            ->set('form.end_at', self::workingMonday()->addDay()->toDateString())
            ->set('form.reason', 'Final end-to-end confirmation scenario.')
            ->call('confirmSave')
            ->assertHasNoErrors();
    }

    public function test_new_leave_rejects_an_alternative_outside_the_allowed_list(): void
    {
        [$employee, $existingLeave] = $this->createRejectedLeaveFixture(null);
        $outsiderId = $this->createEmployeeInCityOf($existingLeave);

        Livewire::actingAs($employee)
            ->test(MyLeaveRequestModule::class)
            ->set('form.leave_balance_id', $existingLeave->leave_balance_id)
            ->set('form.type', 'day')
            ->set('form.start_at', self::workingMonday()->toDateString())
            ->set('form.end_at', self::workingMonday()->addDay()->toDateString())
            ->set('form.reason', 'Alternative without an active contract.')
            ->set('form.alternative_employee', $outsiderId)
            ->call('processSave')
            ->assertHasErrors(['form.alternative_employee']);

        $this->assertDatabaseMissing('leave_requests', ['alternative_employee_id' => $outsiderId]);
    }

    public function test_new_leave_persists_alternative_employee_and_forces_pending_status(): void
    {
        [$employee, $existingLeave] = $this->createRejectedLeaveFixture(null);
        $alternativeEmployeeId = $this->createColleagueForLeave($existingLeave);
        Notification::fake();

        Livewire::actingAs($employee)
            ->test(MyLeaveRequestModule::class)
            ->set('form.leave_balance_id', $existingLeave->leave_balance_id)
            ->set('form.type', 'day')
            ->set('form.start_at', self::workingMonday()->toDateString())
            ->set('form.end_at', self::workingMonday()->addDay()->toDateString())
            ->set('form.reason', 'Verify the stored alternative employee.')
            ->set('form.alternative_employee', $alternativeEmployeeId)
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('processSave')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'leave_balance_id' => $existingLeave->leave_balance_id,
            'reason' => 'Verify the stored alternative employee.',
            'alternative_employee_id' => $alternativeEmployeeId,
            'status' => LeaveRequestStatus::Pending,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function test_employee_cannot_submit_leave_against_another_employees_balance(): void
    {
        [$employee] = $this->createRejectedLeaveFixture(
            null,
            'balance.owner@example.test',
            'balance-owner',
        );
        [, $otherLeave] = $this->createRejectedLeaveFixture(
            null,
            'other.balance.owner@example.test',
            'other-balance-owner',
        );
        $before = LeaveRequest::query()->count();

        Livewire::actingAs($employee)
            ->test(MyLeaveRequestModule::class)
            ->set('form.leave_balance_id', $otherLeave->leave_balance_id)
            ->set('form.type', 'day')
            ->set('form.start_at', self::workingMonday()->toDateString())
            ->set('form.end_at', self::workingMonday()->addDay()->toDateString())
            ->set('form.reason', 'Forged balance request.')
            ->call('processSave')
            ->assertHasErrors(['form.leave_balance_id']);

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    #[DataProvider('nonRejectedStatuses')]
    public function test_non_rejected_leave_details_do_not_render_a_rejection_reason_panel(int $status): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture(null);
        $leaveRequest->update([
            'status' => $status,
            'reject_reason' => null,
        ]);

        Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('openDetails', $leaveRequest->id)
            ->assertSee(LeaveRequestStatus::getLocalizedName($status)['value'])
            ->assertDontSee(__('general.reject-reason'));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonRejectedStatuses(): array
    {
        return [
            'pending request' => [LeaveRequestStatus::Pending],
            'accepted request' => [LeaveRequestStatus::Accepted],
        ];
    }

    public function test_decision_requires_an_explicit_action_and_sends_no_notification_when_missing(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->assertSet('form.status', null)
            ->assertSet('decisionModal', true)
            ->call('submitDecision')
            ->assertHasErrors(['form.status' => 'required'])
            ->assertSet('decisionModal', true);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => LeaveRequestStatus::Pending,
            'approved_at' => null,
            'approved_by' => null,
        ]);
        Notification::assertNothingSent();
    }

    public function test_the_decision_dialog_is_a_form_so_enter_submits_it(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();

        // G-B-7: Save is the form's submit button, not a separate click handler
        // (both together would send the decision twice).
        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->assertSeeHtml('wire:submit.prevent="submitDecision"')
            ->assertDontSeeHtml('wire:click="submitDecision"')
            ->assertSeeHtml('id="reject_reason"');
    }

    public function test_decision_rejects_an_unknown_action_value(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', 999)
            ->call('submitDecision')
            ->assertHasErrors(['form.status' => 'in'])
            ->assertSet('decisionModal', true);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => LeaveRequestStatus::Pending,
        ]);
        Notification::assertNothingSent();
    }

    public function test_valid_rejection_is_persisted_and_sends_the_decision_notification(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'Required operational coverage is unavailable.')
            ->call('submitDecision')
            ->assertHasNoErrors()
            ->assertSet('decisionModal', false)
            ->assertDispatched('leaveRequestDecisionSaved');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => LeaveRequestStatus::Rejected,
            'reject_reason' => 'Required operational coverage is unavailable.',
            'approved_by' => $decisionMaker->employee_id,
        ]);
        Notification::assertSentTo(
            $leaveRequest->leaveBalance->contract->employee->account,
            ActionLeaveRequest::class,
        );
    }

    public function test_valid_approval_is_persisted_and_updates_the_leave_balance(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('submitDecision')
            ->assertHasNoErrors()
            ->assertSet('decisionModal', false);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => LeaveRequestStatus::Accepted,
            'reject_reason' => null,
            'approved_by' => $decisionMaker->employee_id,
        ]);
        $this->assertGreaterThan(
            0,
            (float) DB::table('leave_balances')->where('id', $leaveRequest->leave_balance_id)->value('used'),
        );
        Notification::assertSentTo(
            $leaveRequest->leaveBalance->contract->employee->account,
            ActionLeaveRequest::class,
        );
    }

    public function test_out_of_scope_hr_cannot_view_edit_or_decide_another_citys_leave(): void
    {
        [, $targetLeave] = $this->createPendingDecisionFixture();
        [$outOfScopeHr] = $this->createRejectedLeaveFixture(
            null,
            'out-of-scope.hr@example.test',
            'out-of-scope-hr',
        );
        $outOfScopeHr->assignRole(Role::findOrCreate('HR-XXX', 'web'));

        foreach (['show', 'edit', 'openDecision'] as $action) {
            Livewire::actingAs($outOfScopeHr->fresh())
                ->test(AllLeaveRequestModule::class)
                ->call($action, $targetLeave->id)
                ->assertForbidden();
        }
    }

    public function test_out_of_scope_hr_cannot_delete_or_mark_another_citys_leave_as_reviewed(): void
    {
        [, $targetLeave] = $this->createPendingDecisionFixture();
        [$outOfScopeHr] = $this->createRejectedLeaveFixture(
            null,
            'restricted.hr@example.test',
            'restricted-hr',
        );
        $outOfScopeHr->assignRole(Role::findOrCreate('HR-XXX', 'web'));

        Livewire::actingAs($outOfScopeHr->fresh())
            ->test(AllLeaveRequestTable::class)
            ->call('delete', ['id' => $targetLeave->id])
            ->assertForbidden();

        Livewire::actingAs($outOfScopeHr->fresh())
            ->test(AllLeaveRequestTable::class)
            ->call('markAsReviewed', ['id' => $targetLeave->id])
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $targetLeave->id,
            'status' => LeaveRequestStatus::Pending,
            'hr_approved' => false,
        ]);
    }

    public function test_pending_leave_cannot_be_marked_as_reviewed_by_hr(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestTable::class)
            ->call('markAsReviewed', ['id' => $leaveRequest->id])
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'hr_approved' => false,
        ]);
        Notification::assertNothingSent();
    }

    public function test_hr_review_is_one_way_and_notifies_recipients_only_once(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Accepted,
            'approved_at' => now(),
        ]);
        $employee = $leaveRequest->leaveBalance->contract->employee->account;
        Notification::fake();

        $component = Livewire::actingAs($hr)
            ->test(AllLeaveRequestTable::class)
            ->call('markAsReviewed', ['id' => $leaveRequest->id])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'hr_approved' => true,
        ]);
        Notification::assertSentToTimes($employee, HRSawLeaveRequest::class, 1);

        $component
            ->call('markAsReviewed', ['id' => $leaveRequest->id])
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'hr_approved' => true,
        ]);
        Notification::assertSentToTimes($employee, HRSawLeaveRequest::class, 1);
    }

    public function test_direct_manager_can_open_a_pending_team_members_leave_decision(): void
    {
        [, $teamMemberLeave] = $this->createPendingDecisionFixture();
        [$manager] = $this->createRejectedLeaveFixture(
            null,
            'direct.manager@example.test',
            'direct-manager',
        );
        $manager->assignRole(Role::findOrCreate('manager', 'web'));
        $manager->givePermissionTo('leaves.decide');

        $managerPositionId = DB::table('contracts')
            ->where('employee_id', $manager->employee_id)
            ->value('position_id');
        $teamMemberPositionId = DB::table('contracts')
            ->where('id', $teamMemberLeave->leaveBalance->contract_id)
            ->value('position_id');

        DB::table('positions')
            ->where('id', $teamMemberPositionId)
            ->update(['direct_manager_id' => $managerPositionId]);

        Livewire::actingAs($manager->fresh())
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $teamMemberLeave->id)
            ->assertSet('decisionLeave.id', $teamMemberLeave->id)
            ->assertSet('decisionModal', true);
    }

    public function test_all_leaves_table_names_the_requesting_employee_for_a_direct_manager(): void
    {
        $this->assertAllLeavesTableNamesRequestingEmployee('manager');
    }

    public function test_all_leaves_table_names_the_requesting_employee_for_a_general_manager(): void
    {
        $this->assertAllLeavesTableNamesRequestingEmployee('general-manager');
    }

    public function test_a_decided_leave_cannot_be_decided_again_or_deducted_twice(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        $component = Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('submitDecision')
            ->assertHasNoErrors();

        $usedAfterFirstDecision = (float) DB::table('leave_balances')
            ->where('id', $leaveRequest->leave_balance_id)
            ->value('used');

        // A repeat (double click, retry after a lost response) is told its
        // decision is already saved (G-B-1), never shown a Forbidden page.
        $component
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('submitDecision')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($event, $params) => ($params['type'] ?? null) === 'info'
                && str_contains($params['message'] ?? '', 'Your decision to accept this request was already saved'));

        $this->assertSame(
            $usedAfterFirstDecision,
            (float) DB::table('leave_balances')
                ->where('id', $leaveRequest->leave_balance_id)
                ->value('used'),
        );
        Notification::assertSentToTimes(
            $leaveRequest->leaveBalance->contract->employee->account,
            ActionLeaveRequest::class,
            1,
        );
    }

    /**
     * G-B-1: two people had the same request open; the second to save is told
     * who decided it and when, and nothing of theirs is written or sent.
     */
    public function test_a_decision_saved_after_someone_else_decided_reports_who_decided(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $colleague = $this->createHrUserForLeave($leaveRequest, 'colleague.hr@example.test');
        $colleague->givePermissionTo('leaves.decide');
        Notification::fake();

        $staleDialog = Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->assertSet('decisionModal', true);

        Livewire::actingAs($colleague)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'No cover for these dates.')
            ->call('submitDecision')
            ->assertHasNoErrors();

        $decidedAt = $leaveRequest->fresh()->approved_at->format('d-m-Y, H:i');

        // Back in the first person's session (actingAs is global to the test).
        $this->actingAs($hr);

        $staleDialog
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('submitDecision')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSet('decisionModal', false)
            ->assertSet('showModal', true)
            ->assertSet('linkedLeaveMode', 'details')
            ->assertDispatched('leaveRequestDecisionSaved')
            ->assertDispatched('toast', fn ($event, $params) => ($params['type'] ?? null) === 'info'
                && str_contains($params['message'], 'already rejected by')
                && str_contains($params['message'], $colleague->profile->name)
                && str_contains($params['message'], $decidedAt)
                && str_contains($params['message'], 'Your decision was not saved'));

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => LeaveRequestStatus::Rejected,
            'reject_reason' => 'No cover for these dates.',
            'approved_by' => $colleague->employee_id,
        ]);
        $this->assertSame(0.0, (float) DB::table('leave_balances')->where('id', $leaveRequest->leave_balance_id)->value('used'));
        Notification::assertSentToTimes(
            $leaveRequest->leaveBalance->contract->employee->account,
            ActionLeaveRequest::class,
            1,
        );
    }

    /** A stale "reject without a reason" is not asked for a reason first. */
    public function test_a_stale_decision_is_reported_before_validation(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        $staleDialog = Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id);

        $leaveRequest->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);

        $staleDialog
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->call('submitDecision')
            ->assertHasNoErrors()
            ->assertSet('showModal', true)
            ->assertDispatched('toast', fn ($event, $params) => str_contains($params['message'], 'already accepted on'));

        $this->assertSame(LeaveRequestStatus::Accepted, $leaveRequest->fresh()->status);
    }

    public function test_opening_the_decision_of_an_already_decided_request_shows_its_details(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $colleague = $this->createHrUserForLeave($leaveRequest, 'colleague.hr@example.test');
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Accepted,
            'approved_at' => now(),
            'approved_by' => $colleague->employee_id,
        ]);

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->assertOk()
            ->assertSet('decisionModal', false)
            ->assertSet('showModal', true)
            ->assertDispatched('toast', fn ($event, $params) => str_contains($params['message'], 'already accepted by')
                && str_contains($params['message'], $colleague->profile->name));
    }

    /** Who decided is only told to someone who may still see the request. */
    public function test_an_already_decided_request_out_of_scope_is_still_forbidden(): void
    {
        [, $targetLeave] = $this->createPendingDecisionFixture();
        $targetLeave->update(['status' => LeaveRequestStatus::Rejected, 'approved_at' => now()]);
        [$outOfScopeHr] = $this->createRejectedLeaveFixture(
            null,
            'stale.out-of-scope.hr@example.test',
            'stale-out-of-scope-hr',
        );
        $outOfScopeHr->assignRole(Role::findOrCreate('HR-XXX', 'web'));

        Livewire::actingAs($outOfScopeHr->fresh())
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $targetLeave->id)
            ->assertForbidden()
            ->assertNotDispatched('toast');
    }

    public function test_pending_request_cannot_generate_a_decision_notification_payload(): void
    {
        [$user, $leaveRequest] = $this->createPendingDecisionFixture();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot create a leave decision notification');

        (new ActionLeaveRequest($leaveRequest))->toArray($user);
    }

    public function test_rejection_notification_includes_the_reason_for_employee_and_hr_recipients(): void
    {
        $reason = 'Coverage unavailable — التغطية غير متاحة';
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture($reason);

        $employeePayload = (new ActionLeaveRequest($leaveRequest))->toArray($user);
        $hrPayload = (new ActionLeaveRequest($leaveRequest, 'hr'))->toArray($user);

        $this->assertStringContainsString("Reason: {$reason}", $employeePayload['body']['en']);
        $this->assertStringContainsString("سبب الرفض: {$reason}", $employeePayload['body']['ar']);
        $this->assertStringContainsString("Reason: {$reason}", $hrPayload['body']['en']);
        $this->assertStringContainsString("سبب الرفض: {$reason}", $hrPayload['body']['ar']);
    }

    public function test_approval_notification_does_not_contain_rejection_reason_language(): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture('A stale reason.');
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Accepted,
            'reject_reason' => null,
        ]);

        $payload = (new ActionLeaveRequest($leaveRequest->fresh()))->toArray($user);

        $this->assertStringNotContainsString('Reason:', $payload['body']['en']);
        $this->assertStringNotContainsString('سبب الرفض:', $payload['body']['ar']);
    }

    public function test_rejected_notification_cannot_be_created_without_a_reason(): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a rejection reason');

        (new ActionLeaveRequest($leaveRequest))->toArray($user);
    }

    public function test_database_notification_persists_the_rejection_reason_in_both_languages(): void
    {
        $reason = 'Schedule conflict — تعارض في الجدول';
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture($reason);

        $user->notify(new ActionLeaveRequest($leaveRequest));

        $storedPayload = $user->notifications()->sole()->data;

        $this->assertStringContainsString("Reason: {$reason}", $storedPayload['body']['en']);
        $this->assertStringContainsString("سبب الرفض: {$reason}", $storedPayload['body']['ar']);
    }

    public function test_leave_notifications_link_to_the_exact_request_and_destination_mode(): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture('A specific reason.');

        $employeeAction = (new ActionLeaveRequest($leaveRequest))->toArray($user);
        $hrAction = (new ActionLeaveRequest($leaveRequest, 'hr'))->toArray($user);
        $newRequest = (new NewLeaveRequest($leaveRequest))->toArray($user);
        $employeeReview = (new HRSawLeaveRequest($leaveRequest))->toArray($user);
        $managerReview = (new HRSawLeaveRequest($leaveRequest, 'manager'))->toArray($user);

        $this->assertNotificationUrl($employeeAction['url'], $leaveRequest->id);
        $this->assertNotificationUrl($hrAction['url'], $leaveRequest->id, 'details');
        // Someone without the All leaves page (a direct manager with only the
        // employee role, DEC-05 revision 2) is sent to the work inbox instead.
        $this->assertSame(route('inbox', ['open' => "leave:{$leaveRequest->id}:decide"]), $newRequest['url']);
        $user->givePermissionTo('leaves.view-all');
        $this->assertNotificationUrl((new NewLeaveRequest($leaveRequest))->toArray($user->fresh())['url'], $leaveRequest->id, 'decision');
        $this->assertNotificationUrl($employeeReview['url'], $leaveRequest->id);
        $this->assertNotificationUrl($managerReview['url'], $leaveRequest->id, 'details');
        $this->assertStringContainsString('Test Employee', $newRequest['body']['en']);
        $this->assertStringContainsString('اختبار موظف', $newRequest['body']['ar']);
        $this->assertStringContainsString('Test Employee', $hrAction['body']['en']);
        $this->assertStringContainsString('اختبار موظف', $managerReview['body']['ar']);
    }

    public function test_employee_notification_deep_link_opens_the_owned_leave_details(): void
    {
        [$user, $leaveRequest] = $this->createRejectedLeaveFixture('Deep-linked reason.');

        Livewire::actingAs($user)
            ->withQueryParams(['leave' => $leaveRequest->id])
            ->test(MyLeaveRequestModule::class)
            ->assertSet('selectedLeaveId', $leaveRequest->id)
            ->assertSet('linkedLeaveId', $leaveRequest->id)
            ->assertSet('detailsModal', true)
            ->assertSee('Deep-linked reason.');
    }

    public function test_new_request_deep_link_opens_the_pending_decision_modal(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();

        Livewire::actingAs($decisionMaker)
            ->withQueryParams([
                'leave' => $leaveRequest->id,
                'mode' => 'decision',
            ])
            ->test(AllLeaveRequestModule::class)
            ->assertSet('linkedLeaveId', $leaveRequest->id)
            ->assertSet('linkedLeaveMode', 'decision')
            ->assertSet('decisionModal', true);
    }

    public function test_stale_decision_notification_falls_back_to_read_only_details(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Accepted,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($decisionMaker)
            ->withQueryParams([
                'leave' => $leaveRequest->id,
                'mode' => 'decision',
            ])
            ->test(AllLeaveRequestModule::class)
            ->assertSet('linkedLeaveMode', 'details')
            ->assertSet('decisionModal', false)
            ->assertSet('showModal', true);
    }

    public function test_closing_a_deep_linked_modal_clears_its_query_state(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();

        Livewire::actingAs($decisionMaker)
            ->withQueryParams([
                'leave' => $leaveRequest->id,
                'mode' => 'decision',
            ])
            ->test(AllLeaveRequestModule::class)
            ->call('closeDecision')
            ->assertSet('decisionModal', false)
            ->assertSet('linkedLeaveId', null)
            ->assertSet('linkedLeaveMode', null);
    }

    public function test_new_request_notifies_all_hr_and_manager_holders_exactly_once(): void
    {
        [$employee, $leaveRequest] = $this->createRejectedLeaveFixture(null);
        [$firstManager, $secondManager] = $this->attachSharedManagerPosition($leaveRequest);
        $firstHr = $this->createHrUserForLeave($leaveRequest, 'first.hr@example.test');
        $secondHr = $this->createHrUserForLeave($leaveRequest, 'second.hr@example.test');
        $inactiveHr = $this->createHrUserForLeave(
            $leaveRequest,
            'inactive.hr@example.test',
            false,
        );
        Notification::fake();

        app(LeaveNotificationService::class)->sendNewRequest($leaveRequest, $employee);

        foreach ([$firstManager, $secondManager, $firstHr, $secondHr] as $recipient) {
            Notification::assertSentToTimes($recipient, NewLeaveRequest::class, 1);
        }

        Notification::assertNotSentTo($employee, NewLeaveRequest::class);
        Notification::assertNotSentTo($inactiveHr, NewLeaveRequest::class);
        Notification::assertCount(4);
    }

    public function test_decision_notifies_employee_and_every_other_active_city_hr_once(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        $employee = $leaveRequest->leaveBalance->contract->employee->account;
        $otherHr = $this->createHrUserForLeave($leaveRequest, 'other.hr@example.test');
        $inactiveHr = $this->createHrUserForLeave(
            $leaveRequest,
            'inactive.decision.hr@example.test',
            false,
        );
        $leaveRequest->update([
            'status' => LeaveRequestStatus::Rejected,
            'reject_reason' => 'Recipient verification.',
        ]);
        Notification::fake();

        app(LeaveNotificationService::class)->sendDecision(
            $leaveRequest->fresh(),
            $decisionMaker,
        );

        Notification::assertSentToTimes($employee, ActionLeaveRequest::class, 1);
        Notification::assertSentTo(
            $otherHr,
            fn (ActionLeaveRequest $notification) => $notification->recipientType === 'hr',
        );
        Notification::assertNotSentTo($decisionMaker, ActionLeaveRequest::class);
        Notification::assertNotSentTo($inactiveHr, ActionLeaveRequest::class);
        Notification::assertCount(2);
    }

    public function test_hr_review_notifies_each_manager_holder_and_employee_without_duplicates(): void
    {
        [$hrActor, $leaveRequest] = $this->createPendingDecisionFixture();
        [$firstManager, $secondManager] = $this->attachSharedManagerPosition($leaveRequest);
        $employee = $leaveRequest->leaveBalance->contract->employee->account;
        Notification::fake();

        app(LeaveNotificationService::class)->sendHrReview($leaveRequest, $hrActor);

        Notification::assertSentToTimes($employee, HRSawLeaveRequest::class, 1);
        Notification::assertSentToTimes($firstManager, HRSawLeaveRequest::class, 1);
        Notification::assertSentToTimes($secondManager, HRSawLeaveRequest::class, 1);
        Notification::assertNotSentTo($hrActor, HRSawLeaveRequest::class);
        Notification::assertCount(3);
    }

    /** G-B-4/5: All leaves has phone cards; a decided request opens read-only, never the editor. */
    public function test_gb45_all_leaves_cards_and_a_decided_request_opens_the_read_only_details(): void
    {
        [$hr, $pending] = $this->createPendingDecisionFixture();
        [, $decided] = $this->createRejectedLeaveFixture('Busy week', 'colleague@example.test', 'colleague');
        $sameCity = $pending->leaveBalance->contract->employee->city_id;
        DB::table('employees')->where('id', $decided->leaveBalance->contract->employee_id)->update(['city_id' => $sameCity]);

        $html = Livewire::actingAs($hr)->test(AllLeaveRequestTable::class)->html();

        foreach ([$pending, $decided] as $leave) {
            $this->assertStringContainsString('data-card="'.$leave->id.'"', $html);
            $this->assertStringContainsString(e($leave->leaveBalance->contract->employee->name), $html);
        }

        // Pending opens the decision; decided opens the details (row and card alike).
        $this->assertStringContainsString(e("\$dispatch('openDecisionRequestLeave', { id: {$pending->id} })"), $html);
        $this->assertStringContainsString(e("\$dispatch('openShowRequestLeave', { id: {$decided->id} })"), $html);
        $this->assertStringNotContainsString('x-on:click.prevent="'.e("\$dispatch('openRequestLeaveEdit', { id: {$decided->id} })").'"', $html);
        $this->assertStringNotContainsString('x-on:click="'.e("\$dispatch('openRequestLeaveEdit', { id: {$decided->id} })").'"', $html);
    }

    /** G-B-4: My leaves has phone cards with the leave type first and the same actions. */
    public function test_gb4_my_leaves_cards_show_the_request_and_its_actions(): void
    {
        [$employee, $leave] = $this->createRejectedLeaveFixture('Busy week');

        $html = Livewire::actingAs($employee)->test(MyLeaveRequestTable::class)->html();

        $this->assertStringContainsString('data-card="'.$leave->id.'"', $html);
        $this->assertStringContainsString(e($leave->leaveBalance->leaveType->name_en), $html);
        $this->assertStringContainsString(e("\$dispatch('openMyLeaveDetails', { id: {$leave->id} })"), $html);
    }

    public function test_all_leaves_table_names_the_requesting_employee_for_a_super_admin(): void
    {
        [$decisionMaker, $leave] = $this->createPendingDecisionFixture();

        // A bare super-admin account: the role sees every request org-wide,
        // so it needs no team or city fixture of its own.
        $admin = User::query()->create([
            'employee_id' => $decisionMaker->employee_id,
            'email' => 'super.admin@example.test',
            'password' => 'password',
        ]);
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));

        $employee = $leave->leaveBalance->contract->employee;

        Livewire::actingAs($admin->fresh())
            ->test(AllLeaveRequestTable::class)
            ->assertSee(trans_choice('general.employee', 1))
            ->assertSee($employee->name);
    }

    /**
     * @return array{0: User, 1: LeaveRequest}
     */
    /** A5-M: a chosen decision is guarded; closing it writes nothing and clears the deep link. */
    public function test_closing_a_decision_with_a_choice_asks_and_discarding_writes_nothing(): void
    {
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->call('requestClose', 'decisionModal')
            ->assertSet('decisionModal', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'Coverage is unavailable.')
            ->call('requestClose', 'decisionModal')
            ->assertSet('decisionModal', true)
            ->assertSet('form.reject_reason', 'Coverage is unavailable.')
            ->assertDispatched('confirm-dialog', method: 'discardChanges', payload: 'decisionModal')
            ->call('discardChanges', 'decisionModal')
            ->assertSet('decisionModal', false)
            ->assertSet('linkedLeaveId', null)
            ->call('openDecision', $leaveRequest->id)
            ->assertSet('form.status', null)
            ->assertSet('form.reject_reason', null);

        $this->assertSame(LeaveRequestStatus::Pending, (int) $leaveRequest->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_the_hr_request_form_is_guarded_and_the_details_dialog_is_not(): void
    {
        [$decisionMaker] = $this->createPendingDecisionFixture();
        $before = LeaveRequest::query()->count();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('create')
            ->call('requestClose', 'formModal')
            ->assertSet('formModal', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('create')
            ->set('form.reason', 'Typed by HR')
            ->call('requestClose', 'formModal')
            ->assertSet('formModal', true)
            ->assertDispatched('confirm-dialog', method: 'discardChanges', payload: 'formModal')
            ->call('discardChanges', 'formModal')
            ->assertSet('formModal', false)
            ->assertSet('form.reason', '')
            ->assertSeeHtml("\$wire.call(this.guard, 'formModal')")
            ->assertSeeHtml("\$wire.call(this.guard, 'decisionModal')")
            ->assertDontSeeHtml(route('leaves.all').'"');

        // Read-only details keep their ordinary close; the guard refuses them.
        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('requestClose', 'showModal')
            ->assertNotFound();

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_x2i_closing_a_decision_cancels_and_a_submitted_one_completes_its_attempt(): void
    {
        config(['telemetry.enabled' => true]);
        [$decisionMaker, $leaveRequest] = $this->createPendingDecisionFixture();
        Notification::fake();

        Livewire::actingAs($decisionMaker)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leaveRequest->id)
            ->call('closeDecision')
            ->call('openDecision', $leaveRequest->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->call('submitDecision')
            ->assertHasErrors(['form.reject_reason'])
            ->set('form.reject_reason', 'Required operational coverage is unavailable.')
            ->call('submitDecision')
            ->assertHasNoErrors();

        $this->assertSame(
            [['leave_review', 'cancelled', false], ['leave_review', 'completed', true]],
            TaskAttempt::query()->orderBy('id')->get()
                ->map(fn (TaskAttempt $a) => [$a->task, $a->outcome, $a->had_validation_error])
                ->all(),
        );
    }

    /**
     * Build only the domain records required by the leave details flow.
     *
     * The repository's EmployeeFactory still targets removed `name_*` columns,
     * so these fixtures intentionally use the current migration schema.
     *
     * @return array{0: User, 1: LeaveRequest}
     */
    public function test_new_request_notification_is_awaiting_action_until_the_request_is_decided(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $leaveRequest->update(['reason' => 'Confidential: surgery follow-up']);
        $hr->notify(new NewLeaveRequest($leaveRequest->fresh()));
        $notification = $hr->notifications()->first();

        $this->assertStringNotContainsString('surgery', json_encode($notification->data, JSON_UNESCAPED_UNICODE));

        $states = app(NotificationRequestState::class);
        $pending = $states->forMany($hr, [$notification])[$notification->id];
        $this->assertSame('awaiting', $pending['state']);
        $this->assertNotNull($pending['next_step']);

        Livewire::actingAs($hr)->test(NotificationsIndex::class)->assertSee(__('general.notification-state.awaiting'));

        $leaveRequest->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);

        $decided = $states->forMany($hr, [$notification->fresh()])[$notification->id];
        $this->assertSame('handled', $decided['state']);
        $this->assertNull($decided['next_step']);
        $this->assertNull($notification->fresh()->read_at, 'Handled is separate from unread');

        Livewire::actingAs($hr)->test(NotificationsIndex::class)
            ->assertSee(__('general.notification-state.handled'))
            ->assertDontSee(__('general.notification-state.awaiting'));
    }

    public function test_opening_a_notification_for_a_deleted_request_is_a_safe_outcome(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $hr->notify(new NewLeaveRequest($leaveRequest->fresh()));
        $notification = $hr->notifications()->first();
        $leaveRequest->delete();

        Livewire::actingAs($hr)
            ->test(NotificationsDropdown::class)
            ->call('openNotification', $notification->id)
            ->assertNoRedirect()
            ->assertDispatched('toast');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame('unavailable', app(NotificationRequestState::class)
            ->forMany($hr, [$notification->fresh()])[$notification->id]['state']);
    }

    public function test_opening_a_notification_after_losing_access_does_not_navigate(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $hr->notify(new NewLeaveRequest($leaveRequest->fresh()));
        $notification = $hr->notifications()->first();

        // Role change since the message was written: no role, no city.
        $hr->syncRoles([]);

        Livewire::actingAs($hr->fresh())
            ->test(NotificationsDropdown::class)
            ->call('openNotification', $notification->id)
            ->assertNoRedirect()
            ->assertDispatched('toast');

        Livewire::actingAs($hr->fresh())->test(NotificationsIndex::class)
            ->assertSee(__('general.notification-state.unavailable'))
            ->assertDontSee($leaveRequest->leaveBalance->leaveType->name_en.' leave request from');
    }

    public function test_an_authorized_open_still_navigates_to_the_record(): void
    {
        [$hr, $leaveRequest] = $this->createPendingDecisionFixture();
        $hr->notify(new NewLeaveRequest($leaveRequest->fresh()));
        $notification = $hr->notifications()->first();

        Livewire::actingAs($hr)
            ->test(NotificationsDropdown::class)
            ->call('openNotification', $notification->id)
            ->assertRedirect();
    }

    /**
     * The all-leaves table lists other people's requests, so the employee
     * column has to be there for every role that can reach the page.
     */
    public function test_gb6_a_manager_editing_a_team_members_request_sees_the_employee_and_can_save(): void
    {
        [$manager, $leave] = $this->createManagerEditFixture();
        $employee = $leave->leaveBalance->contract->employee;

        $component = Livewire::actingAs($manager)
            ->test(AllLeaveRequestModule::class)
            ->call('edit', $leave->id)
            ->assertSet('formModal', true)
            ->assertSet('form.employee_id', $employee->id)
            ->assertSeeHtml('data-testid="leave-form-employee"')
            ->assertSee($employee->name)
            ->assertDontSeeHtml('id="employee_id"');

        $component->set('form.reason', 'Changed by the direct manager')
            ->call('confirmSave')
            ->assertHasNoErrors()
            ->call('processSave')
            ->assertHasNoErrors();

        $leave->refresh();
        $this->assertSame('Changed by the direct manager', $leave->reason);
        $this->assertSame($employee->id, $leave->leaveBalance->contract->employee_id);
    }

    public function test_gb6_a_manager_cannot_move_a_team_members_request_onto_their_own_balance(): void
    {
        [$manager, $leave] = $this->createManagerEditFixture();
        $managerBalanceId = DB::table('leave_balances')
            ->whereIn('contract_id', DB::table('contracts')->where('employee_id', $manager->employee_id)->pluck('id'))
            ->value('id');
        $this->assertNotNull($managerBalanceId);

        Livewire::actingAs($manager)
            ->test(AllLeaveRequestModule::class)
            ->call('edit', $leave->id)
            ->set('form.leave_balance_id', $managerBalanceId)
            ->call('confirmSave')
            ->assertHasErrors('form.leave_balance_id');

        $this->assertSame($leave->leave_balance_id, $leave->fresh()->leave_balance_id);
    }

    /**
     * A pending request whose employee reports to a manager (direct-manager
     * position), the way test_direct_manager_can_open_a_pending_team_members_leave_decision builds it.
     *
     * @return array{0: User, 1: LeaveRequest}
     */
    // --- C3: who decides (DEC-05 revision, approval-source-contract.md §4.3, C3-Q1) ---

    public function test_c3_the_direct_manager_decides_and_the_decision_records_it(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        Notification::fake();

        Livewire::actingAs($manager)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leave->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'No cover.')
            ->call('submitDecision')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave->id, 'status' => LeaveRequestStatus::Rejected,
            'decision_basis' => 'manager', 'decided_on_behalf_of' => null,
        ]);
    }

    public function test_c3_hr_cannot_decide_while_the_direct_manager_is_at_work(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $this->makeDirectManagerOf($leave);

        // HR still sees the request, but may not decide it.
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('view', $leave));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('decide', $leave));

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leave->id)
            ->assertForbidden();

        $this->assertSame(LeaveRequestStatus::Pending, $leave->fresh()->status);
    }

    public function test_c3_hr_decides_on_behalf_while_the_manager_is_on_accepted_leave(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $this->putOnAcceptedLeaveToday($manager);
        Notification::fake();

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leave->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'No cover while the manager is away.')
            ->call('submitDecision')
            ->assertHasNoErrors();

        $leave->refresh();
        $this->assertSame('on_behalf', $leave->decision_basis);
        $this->assertSame($manager->employee_id, $leave->decided_on_behalf_of);

        app()->setLocale('en');
        $this->assertStringContainsString('on behalf of', app(\App\Services\LeaveRequestNextStep::class)->describe($leave));
        $this->assertStringContainsString($manager->profile->name, app(\App\Services\LeaveRequestNextStep::class)->describe($leave));
    }

    public function test_c3_hr_decides_when_there_is_no_direct_manager(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        Notification::fake();

        $this->assertSame('no_manager', app(\App\Services\LeaveDecisionAuthority::class)->basisFor($hr, $leave));

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $leave->id)
            ->set('form.status', LeaveRequestStatus::Rejected)
            ->set('form.reject_reason', 'Not this week.')
            ->call('submitDecision')
            ->assertHasNoErrors();

        $this->assertSame('no_manager', $leave->fresh()->decision_basis);
    }

    public function test_gc2_hr_cannot_decide_its_own_request_as_no_manager(): void
    {
        [$hr] = $this->createPendingDecisionFixture();
        $own = $this->pendingOwnRequestOf($hr);
        $authority = app(\App\Services\LeaveDecisionAuthority::class);

        $this->assertSame([], $authority->directManagers($own)->all());
        $this->assertNull($authority->basisFor($hr, $own));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('decide', $own));

        Livewire::actingAs($hr)
            ->test(AllLeaveRequestModule::class)
            ->call('openDecision', $own->id)
            ->assertForbidden();

        $this->assertSame(LeaveRequestStatus::Pending, $own->fresh()->status);
    }

    public function test_gc2_hr_cannot_decide_its_own_request_on_behalf_of_its_absent_manager(): void
    {
        [$hr] = $this->createPendingDecisionFixture();
        $own = $this->pendingOwnRequestOf($hr);
        $this->putOnAcceptedLeaveToday($this->makeDirectManagerOf($own));
        $other = $this->createHrUserForLeave($own, 'other.hr@example.test');
        $other->givePermissionTo(['leaves.decide', 'leaves.view-all']);

        $this->assertNull(app(\App\Services\LeaveDecisionAuthority::class)->basisFor($hr, $own));
        // Another HR person in scope still stands in for the absent manager.
        $this->assertSame('on_behalf', app(\App\Services\LeaveDecisionAuthority::class)->basisFor($other, $own));
    }

    // --- G-C-1: the direct-manager position is the authority (DEC-05 revision 2) ---

    public function test_gc1_a_direct_manager_with_only_the_employee_role_decides_from_the_inbox(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave, asEmployeeOnly: true);
        $this->assertFalse($manager->hasActivePermission('leaves.decide'));
        Notification::fake();
        $inbox = app(\App\Services\WorkInbox::class);

        $this->assertSame('manager', app(\App\Services\LeaveDecisionAuthority::class)->basisFor($manager, $leave));
        $this->assertTrue($inbox->hasSources($manager));
        $this->assertSame(["leave:{$leave->id}:decide"], $inbox->rows($manager)->pluck('key')->all());
        // No escalation: HR does not stand in while that manager is at work.
        $this->assertNull(app(\App\Services\LeaveDecisionAuthority::class)->basisFor($hr, $leave));

        Livewire::actingAs($manager)
            ->test(\App\Livewire\Inbox\Index::class)
            ->call('decide', "leave:{$leave->id}:decide", $inbox->leaveVersion($leave->fresh()), LeaveRequestStatus::Accepted)
            ->assertHasNoErrors();

        $this->assertSame(LeaveRequestStatus::Accepted, $leave->fresh()->status);
        $this->assertSame('manager', $leave->fresh()->decision_basis);
    }

    public function test_gc1_the_next_step_names_a_direct_manager_whatever_their_role(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave, asEmployeeOnly: true);

        app()->setLocale('en');
        $this->assertStringContainsString(
            $manager->profile->name,
            app(\App\Services\LeaveRequestNextStep::class)->describe($leave->fresh()),
        );
    }

    public function test_gc1_the_new_request_notice_opens_the_inbox_for_a_manager_without_all_leaves(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave, asEmployeeOnly: true);

        $data = (new NewLeaveRequest($leave->fresh()->load('leaveBalance.leaveType', 'leaveBalance.contract.employee')))->toArray($manager);

        $this->assertSame(route('inbox', ['open' => "leave:{$leave->id}:decide"]), $data['url']);
    }

    public function test_gc1_one_reminder_goes_to_the_manager_and_city_hr_after_three_days(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave, asEmployeeOnly: true);
        $leave->forceFill(['created_at' => now()->subDays(3)->subMinute(), 'start_at' => today()->addDays(10), 'end_at' => today()->addDays(11)])->save();
        Notification::fake();

        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();
        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();

        Notification::assertSentToTimes($manager, \App\Notifications\LeaveDecisionReminder::class, 1);
        Notification::assertSentTo($manager, \App\Notifications\LeaveDecisionReminder::class, fn ($n) => $n->recipientType === 'manager');
        Notification::assertSentToTimes($hr, \App\Notifications\LeaveDecisionReminder::class, 1);
        Notification::assertSentTo($hr, \App\Notifications\LeaveDecisionReminder::class, fn ($n) => $n->recipientType === 'hr');
        Notification::assertNotSentTo($leave->leaveBalance->contract->employee->account, \App\Notifications\LeaveDecisionReminder::class);
    }

    public function test_gc1_a_reminder_goes_one_day_before_the_start_even_if_sent_today(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave->forceFill(['created_at' => now(), 'start_at' => today()->addDay(), 'end_at' => today()->addDays(2)])->save();
        Notification::fake();

        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();

        Notification::assertSentToTimes($manager, \App\Notifications\LeaveDecisionReminder::class, 1);
    }

    public function test_gc18_hr_that_decides_is_reminded_to_decide(): void
    {
        // No direct manager: HR is the decider (C3-Q1).
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $leave->forceFill(['created_at' => now()->subDays(4), 'start_at' => today()->addDays(10), 'end_at' => today()->addDays(11)])->save();
        Notification::fake();

        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();

        Notification::assertSentToTimes($hr, \App\Notifications\LeaveDecisionReminder::class, 1);
        Notification::assertSentTo($hr, \App\Notifications\LeaveDecisionReminder::class, function ($n) use ($hr, $leave) {
            $data = $n->toArray($hr);

            return $n->recipientType === 'hr_decider'
                && $data['subject']['action'] === 'decide'
                && $data['url'] === route('inbox', ['open' => "leave:{$leave->id}:decide"]);
        });
    }

    public function test_gc17_the_home_shows_decisions_to_a_manager_with_only_the_employee_role(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave, asEmployeeOnly: true);

        Livewire::actingAs($manager)
            ->test(\App\Livewire\Dashboard\ForYouNow::class)
            ->assertSeeHtml('data-testid="for-you-decisions-count"');
    }

    public function test_n03_hr_reads_you_not_the_hr_team_in_the_next_step(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $nextStep = app(\App\Services\LeaveRequestNextStep::class);
        app()->setLocale('en');

        // No direct manager: HR decides, so HR reads "your decision".
        $this->assertSame(__('leaves.next-step.pending-you'), $nextStep->describe($leave->fresh(), $hr));

        // With a manager at work: HR reads that it was informed, not "the HR team".
        $manager = $this->makeDirectManagerOf($leave);
        $text = $nextStep->describe($leave->fresh(), $hr);
        $this->assertStringContainsString('You have been informed', $text);
        $this->assertStringContainsString($manager->profile->name, $text);
        $this->assertStringNotContainsString('HR team', $text);
    }

    public function test_n07_hr_does_not_mark_its_own_decision_as_seen(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $leave->update(['status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'x', 'approved_at' => now(), 'approved_by' => $hr->employee_id, 'hr_approved' => false]);
        $other = $this->createHrUserForLeave($leave, 'second.hr@example.test');
        $other->givePermissionTo(['leaves.review', 'leaves.view-all']);
        $inbox = app(\App\Services\WorkInbox::class);

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('review', $leave->fresh()));
        $this->assertSame(0, $inbox->snapshot($hr)['counts']['review']);
        // Another HR person still sees it.
        $this->assertSame(["leave:{$leave->id}:review"], $inbox->rows($other)->pluck('key')->all());
    }

    public function test_n09_arabic_agrees_with_one_female_manager(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        DB::table('employees')->where('id', $manager->employee_id)->update(['gender' => \App\Enums\Gender::Female]);
        app()->setLocale('ar');

        $text = app(\App\Services\LeaveRequestNextStep::class)->describe($leave->fresh());

        $this->assertStringContainsString('مديرتك', $text);
        $this->assertStringContainsString('إليها', $text);
        $this->assertStringNotContainsString('إليه ', $text);
    }

    public function test_gc1_no_reminder_before_it_is_due_or_once_decided(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave->forceFill(['created_at' => now()->subDays(2), 'start_at' => today()->addDays(5), 'end_at' => today()->addDays(6)])->save();
        Notification::fake();

        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $leave->forceFill(['created_at' => now()->subDays(5), 'status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'x'])->save();
        $this->artisan('leaves:send-decision-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_c3_the_technical_manager_neither_sees_nor_decides(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        [$tech] = $this->createRejectedLeaveFixture(null, 'tech.manager@example.test', 'tech-manager');
        $tech->assignRole(Role::findOrCreate('manager', 'web'));
        $tech->givePermissionTo(['leaves.decide', 'leaves.view-all']);
        $techPositionId = DB::table('contracts')->where('employee_id', $tech->employee_id)->value('position_id');
        DB::table('positions')
            ->where('id', DB::table('contracts')->where('id', $leave->leaveBalance->contract_id)->value('position_id'))
            ->update(['tech_manager_id' => $techPositionId]);

        $tech = $tech->fresh();
        $this->assertFalse(LeaveRequest::query()->manageableBy($tech)->whereKey($leave->id)->exists());
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($tech)->allows('decide', $leave));
    }

    // --- C3: HR review, note and objection (G3, G5, C3D-Q5, C3D-Q6) ---

    public function test_c3_seen_by_hr_keeps_an_optional_note_and_is_one_way(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);
        Notification::fake();

        app(\App\Services\LeaveRequestReview::class)->markSeen($leave->id, $hr, '  Cover arranged with the center.  ');

        $leave->refresh();
        $this->assertTrue($leave->hr_approved);
        $this->assertSame(['note', 'Cover arranged with the center.'], [$leave->notes->first()->kind, $leave->notes->first()->body]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\LeaveRequestReview::class)->markSeen($leave->id, $hr);
    }

    public function test_c3_an_objection_needs_a_note_returns_the_days_and_goes_back_to_the_manager(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now(), 'approved_by' => $manager->employee_id, 'computed_hours' => 16]);
        $leave->leaveBalance->update(['used' => 40]);
        Notification::fake();

        // The note is required (C3D-Q5): nothing changes without it.
        try {
            app(\App\Services\LeaveRequestReview::class)->object($leave->id, $hr, '   ');
            $this->fail('An objection without a note was accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }
        $this->assertSame(LeaveRequestStatus::Accepted, $leave->fresh()->status);
        $this->assertEquals(40, $leave->leaveBalance->fresh()->used);

        app(\App\Services\LeaveRequestReview::class)->object($leave->id, $hr, 'Dates overlap the center audit.');

        $leave->refresh();
        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
        $this->assertNull($leave->approved_by);
        $this->assertFalse($leave->hr_approved);
        // The 16 hours deducted on acceptance come back in the same step (C3D-Q6).
        $this->assertEquals(24, $leave->leaveBalance->fresh()->used);
        $this->assertSame(['objection', 'Dates overlap the center audit.'], [$leave->notes->first()->kind, $leave->notes->first()->body]);

        Notification::assertSentTo($manager, \App\Notifications\LeaveRequestObjected::class);
        Notification::assertSentTo($leave->leaveBalance->contract->employee->account, \App\Notifications\LeaveRequestObjected::class);

        // Pending again: a second objection is refused and gives nothing back twice.
        try {
            app(\App\Services\LeaveRequestReview::class)->object($leave->id, $hr, 'Again.');
            $this->fail('A pending request was objected to.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }
        $this->assertEquals(24, $leave->leaveBalance->fresh()->used);
    }

    public function test_c3_a_manager_cannot_object(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\LeaveRequestReview::class)->object($leave->id, $manager, 'Not mine to object.');
    }

    // --- C3: the work inbox, leave rows (approval-source-contract.md §3, §4.1) ---

    public function test_c3_inbox_rows_are_the_actionable_work_of_each_role(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $inbox = app(\App\Services\WorkInbox::class);

        // Pending: the manager decides; HR, with the manager at work, has nothing to do yet.
        $this->assertSame(["leave:{$leave->id}:decide"], $inbox->rows($manager)->pluck('key')->all());
        $this->assertSame([], $inbox->rows($hr)->where('source', 'leave')->pluck('key')->all());

        // Decided: the row leaves the manager's inbox and becomes HR's to review.
        $leave->update(['status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'x', 'approved_at' => now()]);
        $this->assertSame(0, $inbox->count($manager));
        $this->assertSame(["leave:{$leave->id}:review"], $inbox->rows($hr)->pluck('key')->all());

        // Rows never carry the leave reason (§3.2).
        $this->assertArrayNotHasKey('reason', $inbox->rows($hr)->first());
    }

    public function test_c3_deciding_from_the_inbox_uses_the_domain_decision_and_cannot_deduct_twice(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave->update(['computed_hours' => 8]);
        $leave->leaveBalance->update(['used' => 0, 'deserved' => 160]);
        Notification::fake();
        $key = "leave:{$leave->id}:decide";
        $version = app(\App\Services\WorkInbox::class)->leaveVersion($leave->fresh());

        $inbox = Livewire::actingAs($manager)->test(\App\Livewire\Inbox\Index::class);
        $inbox->call('decide', $key, $version, LeaveRequestStatus::Accepted)->assertHasNoErrors();

        $this->assertSame(LeaveRequestStatus::Accepted, $leave->fresh()->status);
        $this->assertSame('manager', $leave->fresh()->decision_basis);
        Notification::assertSentTo($leave->leaveBalance->contract->employee->account, ActionLeaveRequest::class);
        $usedAfterFirst = (float) $leave->leaveBalance->fresh()->used;

        // The same decision again (double click / retry): already handled, nothing deducted twice.
        $inbox->call('decide', $key, $version, LeaveRequestStatus::Accepted)
            ->assertSet("outcomes.leave-{$leave->id}-decide.outcome", 'already_handled');
        $this->assertEquals($usedAfterFirst, (float) $leave->leaveBalance->fresh()->used);
    }

    public function test_c3_a_request_changed_since_it_was_opened_is_not_decided(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $staleVersion = app(\App\Services\WorkInbox::class)->leaveVersion($leave->fresh());

        // The requester moved the dates while the manager had the inbox open.
        $this->travel(2)->seconds();
        $leave->update(['start_at' => now()->addWeeks(2)->startOfDay(), 'end_at' => now()->addWeeks(2)->addDay()->startOfDay()]);

        Livewire::actingAs($manager)->test(\App\Livewire\Inbox\Index::class)
            ->call('decide', "leave:{$leave->id}:decide", $staleVersion, LeaveRequestStatus::Accepted)
            ->assertSet("outcomes.leave-{$leave->id}-decide.outcome", 'changed');

        $this->assertSame(LeaveRequestStatus::Pending, $leave->fresh()->status);
    }

    public function test_c3_a_rejection_without_a_reason_is_invalid_on_its_row(): void
    {
        [, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $version = app(\App\Services\WorkInbox::class)->leaveVersion($leave->fresh());

        Livewire::actingAs($manager)->test(\App\Livewire\Inbox\Index::class)
            ->call('decide', "leave:{$leave->id}:decide", $version, LeaveRequestStatus::Rejected)
            ->assertHasErrors(["reasons.leave-{$leave->id}-decide"]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->fresh()->status);
    }

    public function test_c3_hr_reviews_and_objects_from_the_inbox(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $leave->update(['status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'x', 'approved_at' => now()]);
        Notification::fake();
        $key = "leave:{$leave->id}:review";
        $slug = "leave-{$leave->id}-review";

        $inbox = Livewire::actingAs($hr)->test(\App\Livewire\Inbox\Index::class);

        // An objection needs its note; the error sits on the row's note field.
        $inbox->call('object', $key)->assertHasErrors(["notes.{$slug}"]);
        $this->assertSame(LeaveRequestStatus::Rejected, $leave->fresh()->status);

        $inbox->set("notes.{$slug}", 'Please reconsider: cover was found.')->call('object', $key)->assertHasNoErrors();
        $this->assertSame(LeaveRequestStatus::Pending, $leave->fresh()->status);
        $this->assertSame('objection', $leave->fresh()->notes->first()->kind);
    }

    // --- G-C-3, 4, 7, 11, 12 ---

    /** G-C-3 (F3): without leaves.review the inbox may neither mark seen nor object, whatever is called. */
    public function test_gc3_inbox_review_and_objection_need_the_review_permission(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now(), 'computed_hours' => 8]);
        $leave->leaveBalance->update(['used' => 40]);
        $hr->revokePermissionTo('leaves.review');
        $hr = $hr->fresh();
        Notification::fake();

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('review', $leave->fresh()));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('object', $leave->fresh()));

        $key = "leave:{$leave->id}:review";
        $inbox = Livewire::actingAs($hr)->test(\App\Livewire\Inbox\Index::class);
        $inbox->call('markSeen', $key)->assertSet("outcomes.leave-{$leave->id}-review.outcome", 'not_allowed');
        $inbox->set("notes.leave-{$leave->id}-review", 'Direct call.')->call('object', $key)
            ->assertSet("outcomes.leave-{$leave->id}-review.outcome", 'not_allowed');

        $leave->refresh();
        $this->assertSame(LeaveRequestStatus::Accepted, $leave->status);
        $this->assertFalse($leave->hr_approved);
        $this->assertEquals(40, $leave->leaveBalance->fresh()->used);
    }

    /** G-C-4 (D-02, D-04): HR's review row says who decided, from the stored basis. */
    public function test_gc4_the_review_row_names_the_decider_and_basis(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $other = $this->createHrUserForLeave($leave, 'other.hr@example.test');
        app()->setLocale('en');
        $next = app(\App\Services\LeaveRequestNextStep::class);

        $leave->update([
            'status' => LeaveRequestStatus::Accepted, 'approved_at' => now(), 'approved_by' => $other->employee_id,
            'decision_basis' => 'on_behalf', 'decided_on_behalf_of' => $manager->employee_id,
        ]);
        $summary = $next->decisionSummary($leave->fresh());
        $this->assertStringContainsString('on behalf of', $summary);
        $this->assertStringContainsString($other->profile->name, $summary);
        $this->assertStringContainsString($manager->profile->name, $summary);
        $this->assertStringNotContainsString('The manager', $summary);

        Livewire::actingAs($hr)->test(\App\Livewire\Inbox\Index::class)
            ->assertSee('accepted this request on behalf of')
            ->assertDontSee('The manager accepted');

        // No direct manager, and a decision from before C3 (no basis): never "the manager".
        $leave->update(['decision_basis' => 'no_manager', 'decided_on_behalf_of' => null]);
        $this->assertStringContainsString('(no direct manager)', $next->decisionSummary($leave->fresh()));

        $leave->update(['status' => LeaveRequestStatus::Rejected, 'reject_reason' => 'x', 'decision_basis' => null]);
        $this->assertSame("\u{2068}{$other->profile->name}\u{2069} rejected this request.", $next->decisionSummary($leave->fresh()));

        app()->setLocale('ar');
        $this->assertStringNotContainsString('رفض المدير', $next->decisionSummary($leave->fresh()));
    }

    /** G-C-7 (F5): the decide filter runs before the cap, so a decidable request behind 100 others still appears. */
    public function test_gc7_a_decidable_request_behind_the_cap_is_listed_and_counted(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        // HR may see, but not decide, its own requests (G-C-2): older than $leave, more than the cap.
        $own = $this->pendingOwnRequestOf($hr);
        $own->forceFill(['created_at' => now()->subDays(30)])->save();

        for ($i = 0; $i < \App\Services\WorkInbox::PER_SOURCE; $i++) {
            $own->replicate()->forceFill(['created_at' => now()->subDays(20)])->save();
        }
        $leave->forceFill(['created_at' => now()])->save();

        $inbox = app(\App\Services\WorkInbox::class);
        $this->assertSame(["leave:{$leave->id}:decide"], $inbox->rows($hr)->where('action', 'decide')->pluck('key')->values()->all());
        $this->assertSame(1, $inbox->snapshot($hr)['counts']['decide']);
    }

    /** G-C-11 (F10): a former employee's pending request is not decidable and is pending work nowhere. */
    public function test_gc11_a_former_employees_pending_request_is_nobodys_work(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('decide', $leave));

        DB::table('employees')->where('id', $leave->leaveBalance->contract->employee_id)->update(['is_active' => false]);
        $leave = $leave->fresh();

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($hr)->allows('decide', $leave));
        $this->assertSame(0, app(\App\Services\WorkInbox::class)->snapshot($hr)['counts']['decide']);

        Livewire::actingAs($hr)->test(AllLeaveRequestModule::class)
            ->call('refreshCounts')
            ->assertSet('counts.pending', 0);
        $this->assertFalse(LeaveRequest::query()->manageableBy($hr)->exceptFormerStaffPending()->whereKey($leave->id)->exists());

        // Their decided requests stay, as history.
        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);
        $this->assertTrue(LeaveRequest::query()->manageableBy($hr)->exceptFormerStaffPending()->whereKey($leave->id)->exists());
    }

    /** G-C-12 (D-06): the next-step text fits who reads it. */
    public function test_gc12_the_next_step_is_worded_for_its_reader(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $leave = $leave->fresh();
        $next = app(\App\Services\LeaveRequestNextStep::class);
        app()->setLocale('en');

        $this->assertStringContainsString('your manager', $next->describe($leave));
        $this->assertSame('Waiting for your decision.', $next->describe($leave, $manager));

        $forHr = $next->describe($leave, $hr);
        $this->assertStringContainsString('the direct manager', $forHr);
        $this->assertStringNotContainsString('your manager', $forHr);
    }

    /** G-C-12 (D-07): the bell stops counting a notice once its request is handled; HR's informational notice still counts. */
    public function test_gc12_the_bell_does_not_count_handled_requests(): void
    {
        [$hr, $leave] = $this->createPendingDecisionFixture();
        $manager = $this->makeDirectManagerOf($leave);
        $manager->notify(new NewLeaveRequest($leave->fresh()));
        $hr->notify(new NewLeaveRequest($leave->fresh()));
        $states = app(NotificationRequestState::class);

        $this->assertSame(1, $states->unreadCount($manager));
        // HR may not decide while the manager is at work: informed, not "already handled".
        $this->assertSame(1, $states->unreadCount($hr));
        $this->assertNotSame('handled', $states->forMany($hr, $hr->notifications)[$hr->notifications->first()->id]['state']);

        $leave->update(['status' => LeaveRequestStatus::Accepted, 'approved_at' => now()]);

        $this->assertSame(0, $states->unreadCount($manager));
        $this->assertSame(1, $manager->unreadNotifications()->count(), 'Handled is separate from unread');
        Livewire::actingAs($manager)->test(\App\Livewire\Notifications\Counter::class)->assertSet('count', 0);
    }

    /** C1: the sidebar's work inbox counts the team's pending request, and drops it once decided. */
    public function test_c1_work_inbox_counts_pending_requests_of_the_managers_team(): void
    {
        [$manager, $leave] = $this->createManagerEditFixture();
        $manager->setActiveRole($manager->roles()->where('name', 'manager')->value('roles.id'));

        Livewire::actingAs($manager->fresh())
            ->test(\App\Livewire\Layout\Sidebar::class)
            ->assertSeeHtml('data-testid="nav-badge">1<');

        $leave->update(['status' => LeaveRequestStatus::Rejected]);

        Livewire::actingAs($manager->fresh())
            ->test(\App\Livewire\Layout\Sidebar::class)
            ->assertSeeHtml('data-testid="nav-badge">0<');
    }

    /** A manager who holds the position the requester's position reports to, with the real manager permissions. */
    private function makeDirectManagerOf(LeaveRequest $leave, bool $asEmployeeOnly = false): User
    {
        [$manager] = $this->createRejectedLeaveFixture(null, uniqid('manager.').'@example.test', uniqid('manager-'));

        if (! $asEmployeeOnly) {
            $role = \App\Models\Role::findOrCreate('manager', 'web');
            $manager->assignRole($role);
            $manager->setActiveRole($role->id);
            $manager->givePermissionTo(['leaves.decide', 'leaves.view-all']);
        }

        $managerPositionId = DB::table('contracts')->where('employee_id', $manager->employee_id)->value('position_id');
        DB::table('positions')
            ->where('id', DB::table('contracts')->where('id', $leave->leaveBalance->contract_id)->value('position_id'))
            ->update(['direct_manager_id' => $managerPositionId]);

        return $manager->fresh();
    }

    /** $user's own leave request, made pending and placed in their HR role's city. */
    private function pendingOwnRequestOf(User $user): LeaveRequest
    {
        DB::table('employees')->where('id', $user->employee_id)->update(['city_id' => $user->activeCityIds()[0]]);
        $own = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn ($contract) => $contract->where('employee_id', $user->employee_id))
            ->firstOrFail();
        $own->update(['status' => LeaveRequestStatus::Pending, 'reject_reason' => null, 'approved_at' => null, 'approved_by' => null]);

        return $own->fresh();
    }

    private function putOnAcceptedLeaveToday(User $manager): void
    {
        LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn ($contract) => $contract->where('employee_id', $manager->employee_id))
            ->firstOrFail()
            ->update([
                'status' => LeaveRequestStatus::Accepted,
                'type' => 'day',
                'start_at' => today()->subDay(),
                'end_at' => today()->addDay(),
            ]);
    }

    private function createManagerEditFixture(): array
    {
        [, $leave] = $this->createPendingDecisionFixture();
        [$manager] = $this->createRejectedLeaveFixture(null, 'direct.manager@example.test', 'direct-manager');
        $manager->assignRole(Role::findOrCreate('manager', 'web'));

        $managerPositionId = DB::table('contracts')->where('employee_id', $manager->employee_id)->value('position_id');
        $teamMemberPositionId = DB::table('contracts')->where('id', $leave->leaveBalance->contract_id)->value('position_id');
        DB::table('positions')->where('id', $teamMemberPositionId)->update(['direct_manager_id' => $managerPositionId]);

        return [$manager->fresh(), $leave->fresh()];
    }

    private function assertAllLeavesTableNamesRequestingEmployee(string $roleName): void
    {
        [, $teamMemberLeave] = $this->createPendingDecisionFixture();
        [$manager] = $this->createRejectedLeaveFixture(
            null,
            $roleName.'@example.test',
            $roleName.'-holder',
        );
        $manager->assignRole(Role::findOrCreate($roleName, 'web'));

        $managerPositionId = DB::table('contracts')
            ->where('employee_id', $manager->employee_id)
            ->value('position_id');
        $teamMemberPositionId = DB::table('contracts')
            ->where('id', $teamMemberLeave->leaveBalance->contract_id)
            ->value('position_id');

        DB::table('positions')
            ->where('id', $teamMemberPositionId)
            ->update(['direct_manager_id' => $managerPositionId]);

        $teamMember = $teamMemberLeave->leaveBalance->contract->employee;

        Livewire::actingAs($manager->fresh())
            ->test(AllLeaveRequestTable::class)
            ->assertSee(trans_choice('general.employee', 1))
            ->assertSee($teamMember->name);
    }

    private function createRejectedLeaveFixture(
        ?string $rejectReason,
        string $email = 'employee@example.test',
        string $username = 'employee',
    ): array {
        $suffix = str_replace(['@', '.', '-'], '_', $email);

        $cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo',
            'name_ar' => 'حلب',
            'slug' => 'A'.substr(md5($suffix), 0, 2),
            'code' => substr(md5('code_'.$suffix), 0, 3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $programId = DB::table('programs')->insertGetId([
            'name' => 'Program '.$suffix,
            'code' => substr(md5('program_code_'.$suffix), 0, 6),
            'slug' => 'program-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center '.$suffix,
            'name_ar' => 'مركز اختبار',
            'weekends' => '0,6',
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Department '.$suffix,
            'code' => substr(md5('department_'.$suffix), 0, 8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jobLevelId = DB::table('job_levels')->insertGetId([
            'name_en' => 'Officer',
            'name_ar' => 'موظف',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'program_id' => $programId,
            'center_id' => $centerId,
            'city_id' => $cityId,
            'job_level_id' => $jobLevelId,
            'department_id' => $departmentId,
            'title_en' => 'Officer',
            'title_ar' => 'موظف',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $cityId,
            'first_name_en' => 'Test',
            'first_name_ar' => 'اختبار',
            'last_name_en' => 'Employee',
            'last_name_ar' => 'موظف',
            'username' => $username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual',
            'name_ar' => 'سنوي',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId,
            'position_id' => $positionId,
            'contract_type_id' => $contractTypeId,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'weekly_hours' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'name_en' => 'Annual Leave',
            'name_ar' => 'إجازة سنوية',
            'base_days' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaveBalanceId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId,
            'type_id' => $leaveTypeId,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaveRequestId = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $leaveBalanceId,
            'type' => 'day',
            'start_at' => now()->addWeek()->startOfDay(),
            'end_at' => now()->addWeek()->addDay()->startOfDay(),
            'reason' => 'Personal leave request.',
            'approved_by' => $employeeId,
            'approved_at' => now(),
            'reject_reason' => $rejectReason,
            'status' => LeaveRequestStatus::Rejected,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'email' => $email,
            'password' => 'password',
        ]);
        $user->givePermissionTo(['leaves.create', 'leaves.update']);

        return [$user, LeaveRequest::query()->findOrFail($leaveRequestId)];
    }

    private function createPendingDecisionFixture(): array
    {
        [, $leaveRequest] = $this->createRejectedLeaveFixture(null);

        $leaveRequest->update([
            'status' => LeaveRequestStatus::Pending,
            'reject_reason' => null,
            'approved_at' => null,
            'approved_by' => null,
        ]);

        [$decisionMaker] = $this->createRejectedLeaveFixture(
            null,
            'decision.maker@example.test',
            'decision-maker',
        );
        $citySlug = $leaveRequest->leaveBalance->contract->employee->city->slug;
        $role = Role::findOrCreate('HR-'.$citySlug, 'web');
        $decisionMaker->assignRole($role);
        // C3: the requester has no direct manager, so HR in scope decides
        // (C3-Q1); deciding and reviewing need their permissions.
        $decisionMaker->givePermissionTo(['leaves.decide', 'leaves.review', 'leaves.view-all']);

        return [$decisionMaker->fresh(), $leaveRequest->fresh()];
    }

    private function createHrUserForLeave(
        LeaveRequest $leaveRequest,
        string $email,
        bool $active = true,
    ): User {
        $suffix = substr(md5($email), 0, 8);
        $employee = $leaveRequest->leaveBalance->contract->employee;

        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $employee->city_id,
            'first_name_en' => 'HR',
            'first_name_ar' => 'موارد بشرية',
            'last_name_en' => $suffix,
            'last_name_ar' => $suffix,
            'username' => 'hr-'.$suffix,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'email' => $email,
            'password' => 'password',
        ]);

        $user->assignRole(Role::findOrCreate('HR-'.$employee->city->slug, 'web'));

        return $user->fresh();
    }

    /**
     * A colleague holding an active contract on the requester's own position,
     * which LeaveAlternativeEmployees always accepts as an alternative.
     */
    private function createColleagueForLeave(LeaveRequest $leaveRequest): int
    {
        $colleagueId = $this->createEmployeeInCityOf($leaveRequest);
        $contract = $leaveRequest->leaveBalance->contract;

        DB::table('contracts')->insert([
            'employee_id' => $colleagueId,
            'position_id' => $contract->position_id,
            'contract_type_id' => $contract->contract_type_id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'weekly_hours' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $colleagueId;
    }

    private function createEmployeeInCityOf(LeaveRequest $leaveRequest): int
    {
        $suffix = substr(md5((string) $leaveRequest->id), 0, 8);
        $employee = $leaveRequest->leaveBalance->contract->employee;

        return DB::table('employees')->insertGetId([
            'city_id' => $employee->city_id,
            'first_name_en' => 'Alternative',
            'first_name_ar' => 'بديل',
            'last_name_en' => 'Colleague',
            'last_name_ar' => 'موظف',
            'username' => 'alternative-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Attach the same direct and technical manager position with two active
     * holders, reproducing both historical duplicate and missed-recipient cases.
     *
     * @return array{0: User, 1: User}
     */
    private function attachSharedManagerPosition(LeaveRequest $leaveRequest): array
    {
        $contract = $leaveRequest->leaveBalance->contract;
        $position = $contract->position;

        $managerPositionId = DB::table('positions')->insertGetId([
            'program_id' => $position->program_id,
            'center_id' => $position->center_id,
            'city_id' => $position->city_id,
            'job_level_id' => $position->job_level_id,
            'department_id' => $position->department_id,
            'title_en' => 'Shared Manager',
            'title_ar' => 'مدير مشترك',
            'max_emp' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('positions')
            ->where('id', $position->id)
            ->update([
                'direct_manager_id' => $managerPositionId,
                'tech_manager_id' => $managerPositionId,
            ]);

        $users = collect(['first', 'second'])->map(function (string $label) use (
            $contract,
            $managerPositionId,
            $position
        ): User {
            $employeeId = DB::table('employees')->insertGetId([
                'city_id' => $position->city_id,
                'first_name_en' => ucfirst($label),
                'first_name_ar' => $label,
                'last_name_en' => 'Manager',
                'last_name_ar' => 'مدير',
                'username' => $label.'-shared-manager',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('contracts')->insert([
                'employee_id' => $employeeId,
                'position_id' => $managerPositionId,
                'contract_type_id' => $contract->contract_type_id,
                'start_date' => now()->subMonth(),
                'end_date' => now()->addYear(),
                'currency' => 2,
                'weekly_hours' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return User::query()->create([
                'employee_id' => $employeeId,
                'email' => $label.'.shared.manager@example.test',
                'password' => 'password',
            ]);
        });

        $leaveRequest->unsetRelation('leaveBalance');

        return [$users[0], $users[1]];
    }

    private function assertNotificationUrl(string $url, int $leaveRequestId, ?string $mode = null): void
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame((string) $leaveRequestId, $query['leave'] ?? null);

        if ($mode === null) {
            $this->assertArrayNotHasKey('mode', $query);
        } else {
            $this->assertSame($mode, $query['mode'] ?? null);
        }
    }

    /**
     * A Monday at least a month ahead whose Monday and Tuesday are working
     * days outside December, so the weekend (0,6) and the December rule
     * (A3-D D-7) never depend on the day the suite runs.
     */
    private static function workingMonday(): Carbon
    {
        $monday = now()->addMonth()->next(Carbon::MONDAY)->startOfDay();

        while ($monday->month === 12 || $monday->copy()->addDay()->month === 12) {
            $monday->addWeek();
        }

        return $monday;
    }
}
