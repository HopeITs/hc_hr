<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ApprovalDecision;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\WorkflowInstanceStatus;
use App\Livewire\Recruitment\Requests\Details;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Services\RecruitmentApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The six checks, and what each decision does to the request.
 *
 * The refusals carry as much weight as the approvals: a stale page, a second
 * click and somebody else's step all have to fail without writing a row, or
 * the approval record stops meaning what it says.
 */
class RecruitmentApprovalTest extends TestCase
{
    use BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    private RecruitmentApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->approvals = app(RecruitmentApprovalService::class);
    }

    public function test_the_assigned_approver_can_approve_and_the_workflow_advances(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        $request = $this->approvals->approve($manager, $step, 1);

        $this->assertSame(ApprovalDecision::Approve, (int) $step->fresh()->decision);
        $this->assertSame($manager->id, (int) $step->fresh()->acted_by_user_id);
        $this->assertNotNull($step->fresh()->acted_at);

        // Still under review, now waiting on the second step.
        $this->assertSame(Status::UnderReview, (int) $request->status);
        $this->assertSame(2, (int) $instance->fresh()->current_step_order);

        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::StepApproved,
            'decision' => ApprovalDecision::Approve,
        ]);
    }

    /**
     * The last approval finishes the run and the effect follows immediately.
     *
     * This type carries no effective date, so there is nothing to wait for:
     * the approval commits and the application runs in its own transaction
     * straight after (D44 · D59), taking the request past `approved` to
     * `applied`. A type that is dated would stop at `approved` instead.
     */
    public function test_the_final_approval_completes_the_workflow(): void
    {
        ['instance' => $instance, 'manager' => $manager, 'finance' => $finance, 'hr' => $hr] = $this->workflow();

        $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);
        $this->approvals->approve($finance, $this->stepAt($instance, 2), 1);
        $request = $this->approvals->approve($hr, $this->stepAt($instance, 3), 1);

        $this->assertSame(Status::Applied, (int) $request->status);
        $this->assertNotNull($request->applied_at);
        $this->assertSame(WorkflowInstanceStatus::Completed, (int) $instance->fresh()->status);
        $this->assertNotNull($instance->fresh()->completed_at);
    }

    /** Check 3: the step belongs to one person, whoever else holds the permission. */
    public function test_another_user_cannot_decide_someone_elses_step(): void
    {
        ['instance' => $instance, 'finance' => $finance] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        try {
            // Finance holds every approval permission, and this is not his step.
            $this->approvals->approve($finance, $step, 1);
            $this->fail('Only the assigned approver decides.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertNull($step->fresh()->decision, 'A refused decision writes nothing.');
        }
    }

    /** Check 2: the workflow is waiting on step one, not step two. */
    public function test_a_step_that_is_not_the_active_one_cannot_be_decided(): void
    {
        ['instance' => $instance, 'finance' => $finance] = $this->workflow();
        $second = $this->stepAt($instance, 2);

        try {
            $this->approvals->approve($finance, $second, 1);
            $this->fail('A step out of turn should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
            $this->assertNull($second->fresh()->decision);
        }
    }

    /** A second click finds the step decided and the workflow moved on. */
    public function test_a_step_cannot_be_decided_twice(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        $this->approvals->approve($manager, $step, 1);

        try {
            $this->approvals->approve($manager, $step->fresh(), 1);
            $this->fail('A decided step should not be decided again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
        }

        $this->assertSame(1, RecruitmentWorkflowInstanceStep::query()
            ->whereKey($step->getKey())->whereNotNull('decision')->count());
    }

    /** Check 6: the page was showing a version that has since been replaced. */
    public function test_a_decision_on_a_stale_version_is_refused(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        try {
            $this->approvals->approve($manager, $step, 99);
            $this->fail('A stale version should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
            $this->assertNull($step->fresh()->decision);
        }
    }

    public function test_rejecting_requires_a_reason_and_ends_the_request(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        try {
            $this->approvals->reject($manager, $step, 1, '   ');
            $this->fail('A rejection with no reason should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }

        $request = $this->approvals->reject($manager, $step, 1, 'There is no budget for this.');

        $this->assertSame(Status::Rejected, (int) $request->status);
        $this->assertSame(WorkflowInstanceStatus::Rejected, (int) $instance->fresh()->status);
        $this->assertSame('There is no budget for this.', $step->fresh()->decision_reason);
    }

    /** D60: the comment has to say what needs changing, not merely object. */
    public function test_requesting_changes_requires_the_changes_to_be_stated(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        try {
            $this->approvals->requestChanges($manager, $step, 1, '', ['weekly_hours']);
            $this->fail('A request for changes with no comment should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }
    }

    public function test_requesting_changes_returns_it_to_the_requester(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();
        $step = $this->stepAt($instance, 1);

        $request = $this->approvals->requestChanges($manager, $step, 1, 'State the weekly hours.', ['weekly_hours']);

        $this->assertSame(Status::ChangesRequested, (int) $request->status);
        $this->assertSame(WorkflowInstanceStatus::Cancelled, (int) $instance->fresh()->status);

        $this->assertDatabaseHas('recruitment_request_events', [
            'recruitment_request_id' => $request->id,
            'event_type' => RecruitmentEventType::ChangesRequested,
            'comment' => 'State the weekly hours.',
        ]);
    }

    /**
     * The role at the moment of the decision is stored, not looked up later.
     *
     * Reading an old event through today's roles would misrepresent who decided
     * what, which is the whole reason the column exists.
     */
    public function test_the_decider_role_is_recorded_as_it_was(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();

        $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);

        $event = $instance->request->events()
            ->where('event_type', RecruitmentEventType::StepApproved)
            ->sole();

        $this->assertSame($manager->myRole(), $event->actor_role);
        $this->assertSame($manager->id, (int) $event->actor_user_id);
    }

    /** RV-6 at the last moment: the position went inactive while it waited. */
    public function test_a_final_approval_is_refused_when_the_subject_no_longer_holds(): void
    {
        ['instance' => $instance, 'manager' => $manager, 'finance' => $finance, 'hr' => $hr, 'position' => $position]
            = $this->workflow();

        $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);
        $this->approvals->approve($finance, $this->stepAt($instance, 2), 1);

        $position->forceFill(['is_active' => false])->save();

        $request = $this->approvals->approve($hr, $this->stepAt($instance, 3), 1);

        // Handed back rather than approved: a normal request has a requester
        // who can fix it.
        $this->assertSame(Status::ChangesRequested, (int) $request->status);
        $this->assertNotSame(Status::Approved, (int) $request->status);
    }

    /**
     * Asking for changes is two steps on the screen, one decision underneath.
     *
     * Approving and rejecting are a click; asking for changes is a sentence and
     * a list of fields. The panel shows the three decisions first and only
     * opens the rest once that path is chosen, so it never asks for things the
     * other two buttons will not read.
     */
    public function test_the_change_request_fields_appear_only_after_choosing_that_path(): void
    {
        ['request' => $request, 'manager' => $manager] = $this->workflow();

        $component = Livewire::actingAs($manager)
            ->test(Details::class, ['requestId' => $request->getKey()]);

        // Step one: the decisions, and none of what only one of them needs.
        $component->assertSee(__('recruitment.request_changes'))
            ->assertDontSee(__('recruitment.fields_to_unlock'))
            ->assertDontSee(__('recruitment.send_change_request'));

        // Step two, once that path is chosen.
        $component->call('askForChanges')
            ->assertSet('askingForChanges', true)
            ->assertSee(__('recruitment.fields_to_unlock'))
            ->assertSee(__('recruitment.send_change_request'));

        // And back again, leaving nothing half-typed behind.
        $component->set('decisionComment', 'Half a thought')
            ->call('cancelChangeRequest')
            ->assertSet('askingForChanges', false)
            ->assertSet('decisionComment', '')
            ->assertSet('requestedFields', [])
            ->assertDontSee(__('recruitment.fields_to_unlock'));
    }

    /**
     * A rejection needs a reason, so the screen asks for one before deciding.
     *
     * The service refuses a rejection with none, and the panel used to confirm
     * the decision straight away, leaving the refusal with no field to answer.
     */
    public function test_rejecting_asks_for_its_reason_before_the_decision(): void
    {
        ['request' => $request, 'manager' => $manager] = $this->workflow();

        $component = Livewire::actingAs($manager)
            ->test(Details::class, ['requestId' => $request->getKey()]);

        $component->assertDontSee(__('recruitment.rejection_reason'))
            ->call('askToReject')
            ->assertSet('rejecting', true)
            ->assertSee(__('recruitment.rejection_reason'))
            ->call('decide', 'reject')
            ->assertHasErrors('comment');

        $component->set('decisionComment', 'There is no budget for this.')
            ->call('decide', 'reject')
            ->assertHasNoErrors()
            ->assertSet('rejecting', false);

        $this->assertSame(Status::Rejected, (int) $request->fresh()->status);
    }

    /**
     * HR Syria holds the step of a governorate that has no HR of its own.
     *
     * Aleppo is reviewed by HR Syria, so its requests carry a city-scoped step
     * assigned to that role. The role is organisation-wide and therefore has no
     * list of cities of its own, which used to make the step undecidable by the
     * very person it was assigned to.
     */
    public function test_hr_syria_holds_the_city_scope_of_a_governorate_it_reviews(): void
    {
        $aleppo = $this->makeCity('ALP', (string) random_int(100, 449));
        $latakia = $this->makeCity('TAR', (string) random_int(450, 899));
        $hrSyria = $this->makeUser($aleppo, 'HR-SYR');
        $scopes = app(\App\Services\RecruitmentScopeResolver::class);

        $this->assertTrue($scopes->holdsScope($hrSyria, \App\Enums\RecruitmentScopeType::City, $aleppo->id));
        // Tartous is reviewed by HR Latakia, not by HR Syria.
        $this->assertFalse($scopes->holdsScope($hrSyria, \App\Enums\RecruitmentScopeType::City, $latakia->id));
    }

    private function stepAt(RecruitmentWorkflowInstance $instance, int $order): RecruitmentWorkflowInstanceStep
    {
        return $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', $order))
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->sole();
    }
}
