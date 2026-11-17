<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ApprovalDecision;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Models\RecruitmentWorkflowInstance;
use App\Notifications\RecruitmentApprovalAwaiting;
use App\Notifications\RecruitmentRequestApprovedForHr;
use App\Notifications\RecruitmentRequestDecided;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Telling people what happened, without ever being what happened.
 *
 * The notices are a courtesy laid over the record. The tests that matter most
 * here are the ones proving that: a dead mail server must not cost an approval,
 * and nothing may be sent describing a transaction that rolled back.
 */
class RecruitmentNotificationTest extends TestCase
{
    use BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    private RecruitmentApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->approvals = app(RecruitmentApprovalService::class);
    }

    /** The first approver learns the request has reached them. */
    public function test_starting_a_workflow_notifies_the_first_approver(): void
    {
        Notification::fake();

        ['request' => $request, 'instance' => $instance, 'manager' => $manager] = $this->workflow();

        app(RecruitmentNotifier::class)->workflowStarted($request, $instance);

        Notification::assertSentTo($manager, RecruitmentApprovalAwaiting::class);
    }

    /** An approval that moves the workflow on tells the next approver, nobody else. */
    public function test_approving_a_step_notifies_the_next_approver(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager, 'finance' => $finance, 'requester' => $requester]
            = $this->workflow();

        $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);

        Notification::assertSentTo($finance, RecruitmentApprovalAwaiting::class);
        Notification::assertNotSentTo($requester, RecruitmentRequestDecided::class);
    }

    /** A rejection is the requester's news, and the approvers' business is over. */
    public function test_rejecting_notifies_the_requester(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager, 'requester' => $requester] = $this->workflow();

        $this->approvals->reject($manager, $this->stepAt($instance, 1), 1, 'No budget.');

        Notification::assertSentTo($requester, RecruitmentRequestDecided::class,
            function (RecruitmentRequestDecided $notification): bool {
                return $notification->decision === ApprovalDecision::Reject;
            });
    }

    public function test_requesting_changes_notifies_the_requester(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager, 'requester' => $requester] = $this->workflow();

        $this->approvals->requestChanges($manager, $this->stepAt($instance, 1), 1, 'Fix the hours.', ['weekly_hours']);

        Notification::assertSentTo($requester, RecruitmentRequestDecided::class);
    }

    /** The last approval concerns the requester and HR both. */
    public function test_the_final_approval_notifies_the_requester_and_hr(): void
    {
        Notification::fake();

        ['instance' => $instance, 'manager' => $manager, 'finance' => $finance,
            'hr' => $hr, 'requester' => $requester] = $this->workflow();

        $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);
        $this->approvals->approve($finance, $this->stepAt($instance, 2), 1);
        $this->approvals->approve($hr, $this->stepAt($instance, 3), 1);

        Notification::assertSentTo($requester, RecruitmentRequestDecided::class);
        Notification::assertSentTo($hr, RecruitmentRequestApprovedForHr::class);
    }

    /**
     * A dead mail server does not cost an approval.
     *
     * The decision has already committed by the time anything is sent, so a
     * failure here has nothing it could usefully undo — and undoing it would
     * ask a real person to decide the same thing twice.
     */
    public function test_a_failing_notification_does_not_break_the_workflow(): void
    {
        ['instance' => $instance, 'manager' => $manager] = $this->workflow();

        // Every send throws, as a broken transport would.
        Notification::swap(new class extends \Illuminate\Notifications\ChannelManager
        {
            public function __construct() {}

            public function send($notifiables, $notification)
            {
                throw new RuntimeException('Mail server unreachable.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('Mail server unreachable.');
            }
        });

        $request = $this->approvals->approve($manager, $this->stepAt($instance, 1), 1);

        $this->assertSame(Status::UnderReview, (int) $request->status);
        $this->assertSame(ApprovalDecision::Approve, (int) $this->stepAt($instance, 1)->fresh()->decision);
        $this->assertSame(2, (int) $instance->fresh()->current_step_order);
    }

    /**
     * Nothing is sent describing work that did not happen.
     *
     * A refused decision rolls back, and a notice about it could not be
     * recalled — so the dispatch sits outside the transaction entirely and
     * never runs when the transaction does not commit.
     */
    public function test_a_refused_decision_notifies_nobody(): void
    {
        Notification::fake();

        ['instance' => $instance, 'finance' => $finance] = $this->workflow();

        try {
            // Not this approver's step: refused at check 3.
            $this->approvals->approve($finance, $this->stepAt($instance, 1), 1);
        } catch (Throwable) {
            // The refusal is tested elsewhere; what matters here is the silence.
        }

        Notification::assertNothingSent();
    }

    /** An unassigned step has nobody to write to, and that is not a failure. */
    public function test_an_unassigned_next_step_notifies_nobody_without_failing(): void
    {
        Notification::fake();

        ['request' => $request, 'instance' => $instance] = $this->workflow();

        $instance->steps()->update(['assigned_user_id' => null]);

        app(RecruitmentNotifier::class)->workflowStarted($request, $instance->fresh());

        Notification::assertNothingSent();
    }

    /**
     * Delivered as they happen, because nothing here processes a queue.
     *
     * A queued notification is written only when a worker runs. There is no
     * worker on this deployment, so queueing these meant nobody ever received
     * one — the jobs table simply grew. Failure is already contained by the
     * notifier, which catches rather than raises, so synchronous delivery
     * cannot cost an approval either.
     */
    public function test_the_notifications_are_delivered_without_a_worker(): void
    {
        foreach ([RecruitmentApprovalAwaiting::class, RecruitmentRequestDecided::class,
            RecruitmentRequestApprovedForHr::class] as $class) {
            $this->assertFalse(
                is_subclass_of($class, ShouldQueue::class),
                "{$class} must not be queued: nothing processes the queue here.",
            );
        }
    }

    /**
     * The mail carries a number and a link, and nothing worth protecting.
     *
     * The reason an approver gave is a judgement about a person or a budget. It
     * reaches the requester through the system, where they are signed in, and
     * not through a mail server somebody else operates.
     */
    public function test_the_mail_body_carries_no_sensitive_detail(): void
    {
        ['request' => $request, 'requester' => $requester] = $this->workflow();

        $notification = new RecruitmentRequestDecided($request, ApprovalDecision::Reject, 'The salary is 900 USD.');
        $mail = $notification->toMail($requester);

        $rendered = json_encode($mail->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('900', $rendered);
        $this->assertStringNotContainsString('salary', strtolower($rendered));
        $this->assertStringContainsString($request->request_number, $rendered);

        // The database copy, read while signed in, may carry it.
        $this->assertSame('The salary is 900 USD.', $notification->toArray($requester)['comment']);
    }

    /** The link goes into the system, never to an action endpoint. */
    public function test_the_notification_links_into_the_system_rather_than_granting_anything(): void
    {
        ['request' => $request, 'manager' => $manager] = $this->workflow();

        $url = (new RecruitmentApprovalAwaiting($request))->toArray($manager)['url'];

        $this->assertStringContainsString('recruitment/requests/'.$request->getKey(), $url);
        $this->assertStringNotContainsString('approve', $url);
        $this->assertStringNotContainsString('token', $url);

        // And it is behind authentication: a stranger holding the link gets
        // nowhere, which is what stops a forwarded mail from being a decision.
        $this->get($url)->assertRedirect();
    }

    private function stepAt(RecruitmentWorkflowInstance $instance, int $order)
    {
        return $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', $order))
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->sole();
    }
}
