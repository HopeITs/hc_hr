<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\AllLeaveRequestTable;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestTable;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ActionLeaveRequest;
use App\Notifications\HRSawLeaveRequest;
use App\Notifications\NewLeaveRequest;
use App\Services\LeaveNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->set('form.start_at', now()->addMonth()->toDateString())
            ->set('form.end_at', now()->addMonth()->addDay()->toDateString())
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
            ->set('form.start_at', now()->addMonth()->toDateString())
            ->set('form.end_at', now()->addMonth()->addDay()->toDateString())
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
            ->set('form.start_at', now()->addMonth()->toDateString())
            ->set('form.end_at', now()->addMonth()->addDay()->toDateString())
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
            ->set('form.start_at', now()->addMonth()->toDateString())
            ->set('form.end_at', now()->addMonth()->addDay()->toDateString())
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

        $component
            ->set('form.status', LeaveRequestStatus::Accepted)
            ->call('submitDecision')
            ->assertForbidden();

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
        $this->assertNotificationUrl($newRequest['url'], $leaveRequest->id, 'decision');
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
     * The all-leaves table lists other people's requests, so the employee
     * column has to be there for every role that can reach the page.
     */
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

    /**
     * Build only the domain records required by the leave details flow.
     *
     * The repository's EmployeeFactory still targets removed `name_*` columns,
     * so these fixtures intentionally use the current migration schema.
     *
     * @return array{0: User, 1: LeaveRequest}
     */
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

    /**
     * @return array{0: User, 1: LeaveRequest}
     */
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
}
