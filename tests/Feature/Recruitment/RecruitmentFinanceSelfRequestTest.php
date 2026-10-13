<?php

namespace Tests\Feature\Recruitment;

use App\Enums\WorkflowSkipReason;
use App\Enums\WorkflowStepKey;
use App\Models\Permission;
use App\Notifications\RecruitmentApprovalUnwound;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentWorkflowInstanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Two cases where the workflow cannot simply run its course.
 *
 * The organisation has one financial approver, so when they are the one
 * asking there is nobody to ask. And an approver sending a request back
 * unwinds decisions other people already made, which those people should hear
 * about from the system rather than by noticing later.
 */
class RecruitmentFinanceSelfRequestTest extends TestCase
{
    use BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /**
     * The financial approver's own request skips financial review.
     *
     * Not silently: the step is created, marked skipped with its reason, and
     * an event says so. A step that simply never appeared would leave the
     * record claiming a two-step workflow that was defined with three.
     */
    public function test_a_request_from_the_financial_approver_skips_financial_review(): void
    {
        ['request' => $request, 'finance' => $finance, 'position' => $position] = $this->workflow();

        // The financial approver raises this one themselves.
        $request->forceFill(['requester_user_id' => $finance->getKey()])->save();
        $position->forceFill(['direct_manager_id' => null])->save();
        $finance->givePermissionTo(Permission::findByName('recruitment-requests.submit-without-manager'));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Wind the request back to the moment before its workflow started, so
        // start() runs the transition it is actually meant to run.
        $request->workflowInstances()->each(fn ($instance) => $instance->steps()->delete());
        $request->workflowInstances()->delete();
        // Written through the query: the fixture's object still holds the
        // status it had before start() moved it, so saving that value over
        // itself would be no change at all.
        \App\Models\RecruitmentRequest::query()->whereKey($request->getKey())
            ->update(['status' => \App\Enums\RecruitmentRequestStatus::Submitted]);

        $version = $request->versions()->latest('version_no')->first();
        $instance = app(RecruitmentWorkflowInstanceService::class)->start($request->fresh(), $version);

        $finance = $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_key', WorkflowStepKey::FinanceApproval))
            ->sole();

        $this->assertNull($finance->decision, 'A skip is not a decision.');
        $this->assertSame('skip:'.WorkflowSkipReason::RequesterIsApprover, $finance->decision_reason);
        $this->assertNotNull($finance->acted_at, 'The step is finished with, even though nobody decided it.');

        // And the workflow waits on a step somebody can actually decide.
        $waiting = $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', $instance->current_step_order))
            ->with('workflowStep')
            ->sole();

        $this->assertNotSame(WorkflowStepKey::FinanceApproval, (int) $waiting->workflowStep->step_key);
    }

    /**
     * Sending a request back tells everybody who already approved it.
     *
     * They approved what it said at the time. Somebody further down has sent
     * it to be rewritten, and finding that out only when it lands in front of
     * them again would mean the record moved under them in silence.
     */
    public function test_earlier_approvers_hear_that_the_request_is_being_rewritten(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager, 'finance' => $finance] = $this->workflow();
        $approvals = app(RecruitmentApprovalService::class);

        $approvals->approve($manager, $this->stepAt($instance, 1), 1);

        // Finance, next in line, sends it back.
        $approvals->requestChanges($finance, $this->stepAt($instance, 2), 1, 'The salary is wrong.', ['salary_amount']);

        // The manager approved already and hears that it is changing.
        Notification::assertSentTo($manager, RecruitmentApprovalUnwound::class);

        // The person who asked already knows; telling them would be noise.
        Notification::assertNotSentTo($finance, RecruitmentApprovalUnwound::class);
    }

    /** Nobody has approved yet, so there is nobody to unwind. */
    public function test_the_first_approver_sending_it_back_tells_no_earlier_approver(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager] = $this->workflow();

        app(RecruitmentApprovalService::class)
            ->requestChanges($manager, $this->stepAt($instance, 1), 1, 'State the hours.', ['weekly_hours']);

        Notification::assertNothingSentTo($manager, RecruitmentApprovalUnwound::class);
    }

    private function stepAt($instance, int $order)
    {
        return $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', $order))
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->sole();
    }
}
