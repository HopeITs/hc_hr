<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestMode as Mode;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Models\RecruitmentRequest;
use App\Services\RecruitmentRequestStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The transition table, exercised in both directions.
 *
 * The document is explicit that anything it does not name is refused, so the
 * refusals matter as much as the moves — a request that can go from rejected
 * back to under review has lost the point of being rejected.
 */
class RecruitmentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentRequestStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->states = app(RecruitmentRequestStateMachine::class);
    }

    /** Every move the table names, from every state it names it from. */
    public function test_each_named_transition_is_allowed(): void
    {
        $allowed = [
            // trigger, from, to
            ['submit', Status::Draft, Status::Submitted],
            ['resubmit', Status::ChangesRequested, Status::Resubmitted],
            ['workflow_started', Status::Submitted, Status::UnderReview],
            ['workflow_restarted', Status::Resubmitted, Status::UnderReview],
            ['approve_step', Status::UnderReview, Status::UnderReview],
            ['approve_final', Status::UnderReview, Status::Approved],
            ['reject', Status::UnderReview, Status::Rejected],
            ['request_changes', Status::UnderReview, Status::ChangesRequested],
            ['cancel', Status::Draft, Status::Cancelled],
            ['cancel', Status::ChangesRequested, Status::Cancelled],
            ['cancel', Status::UnderReview, Status::Cancelled],
            ['apply_succeeded', Status::Approved, Status::Applied],
            ['apply_failed', Status::Approved, Status::FailedApplication],
            ['apply_succeeded', Status::FailedApplication, Status::Applied],
            ['direct_open_approved', Status::UnderReview, Status::Approved],
            ['direct_open_voided', Status::UnderReview, Status::Rejected],
        ];

        foreach ($allowed as [$trigger, $from, $to]) {
            $request = $this->request($from);

            $this->assertTrue(
                $this->states->can($request, $trigger),
                "{$trigger} should be allowed from status {$from}.",
            );

            $this->assertSame(
                $to,
                (int) $this->states->apply($request, $trigger)->status,
                "{$trigger} from {$from} should land on {$to}.",
            );
        }
    }

    /**
     * The four mistakes the document calls out by name.
     *
     * Each of these is a move somebody will eventually reach for, and each one
     * undoes something the workflow exists to guarantee.
     */
    public function test_the_named_mistakes_are_refused(): void
    {
        $refused = [
            // A decision, once taken, is not quietly reopened.
            'approved cannot go back under review' => [Status::Approved, 'workflow_started'],
            // Returning to the requester goes through changes_requested only.
            'under review cannot drop back to draft' => [Status::UnderReview, 'submit'],
            // Skipping resubmission would skip the new version with it.
            'changes requested cannot be approved directly' => [Status::ChangesRequested, 'approve_final'],
            // Nothing revives a rejected request.
            'rejected cannot be revived' => [Status::Rejected, 'workflow_started'],
        ];

        foreach ($refused as $why => [$from, $trigger]) {
            $request = $this->request($from);

            $this->assertFalse($this->states->can($request, $trigger), $why);

            try {
                $this->states->apply($request, $trigger);
                $this->fail($why);
            } catch (ValidationException) {
                $this->assertSame($from, (int) $request->fresh()->status, 'A refused move must change nothing.');
            }
        }
    }

    /** Rejected, cancelled and applied are the end of the line. */
    public function test_nothing_leaves_a_final_state(): void
    {
        foreach ([Status::Rejected, Status::Cancelled, Status::Applied] as $final) {
            $request = $this->request($final);

            $this->assertSame([], $this->states->available($request), 'A final state offers nothing.');
        }
    }

    /**
     * A direct opening never goes back to its requester for changes (D15).
     *
     * Revalidation failure refuses it instead, which is T22 and the whole point
     * of D39: the short path stays short.
     */
    public function test_a_direct_opening_cannot_be_returned_for_changes(): void
    {
        $request = $this->request(Status::UnderReview, Mode::DirectHr);

        $this->assertFalse($this->states->can($request, 'request_changes'));
        $this->assertTrue($this->states->can($request, 'direct_open_voided'));

        $this->assertSame(Status::Rejected, (int) $this->states->apply($request, 'direct_open_voided')->status);
    }

    /** A retry after a failed application is allowed; a second success is not. */
    public function test_a_failed_application_can_be_retried_but_an_applied_one_cannot(): void
    {
        $failed = $this->request(Status::FailedApplication);
        $this->assertSame(Status::Applied, (int) $this->states->apply($failed, 'apply_succeeded')->status);

        $applied = $this->request(Status::Applied);
        $this->assertFalse($this->states->can($applied, 'apply_succeeded'));
    }

    /** An update leaves the request where it is rather than moving it. */
    public function test_updating_keeps_the_request_in_place(): void
    {
        foreach ([Status::Draft, Status::ChangesRequested] as $editable) {
            $request = $this->request($editable);

            $this->assertTrue($this->states->can($request, 'update'));
            $this->assertSame($editable, (int) $this->states->apply($request, 'update')->status);
        }

        $this->assertFalse($this->states->can($this->request(Status::UnderReview), 'update'));
    }

    public function test_an_unknown_trigger_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->states->apply($this->request(Status::Draft), 'approve_everything');
    }

    private function request(int $status, int $mode = Mode::Normal): RecruitmentRequest
    {
        return RecruitmentRequest::query()->create([
            'request_number' => 'RR-'.uniqid(),
            'requester_user_id' => \App\Models\User::factory()->create()->id,
            'request_type' => Type::FillVacancy,
            'mode' => $mode,
            'status' => $status,
        ]);
    }
}
