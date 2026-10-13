<?php

namespace Tests\Feature\Recruitment;

use App\Enums\WorkflowStepKey;
use App\Livewire\Recruitment\Requests\Edit;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentEditableFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A locked field should look locked.
 *
 * The service refuses an unasked-for change either way, but a form that
 * accepts typing and only then rejects it teaches people to distrust the form.
 * The point of these tests is that the refusal is visible before anybody types.
 */
class RecruitmentLockedFieldsScreenTest extends TestCase
{
    use BuildsRecruitmentRequests, BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** What the approver did not ask about comes back disabled and says so. */
    public function test_the_edit_screen_disables_what_was_not_asked_for(): void
    {
        ['request' => $request, 'instance' => $instance, 'manager' => $manager, 'requester' => $requester]
            = $this->workflow();

        $request->forceFill([
            'draft_payload_json' => ['request_type' => $request->request_type, 'position_id' => $request->target_position_id]
                + $this->recruitmentFormDefaults(),
        ])->save();

        $step = $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', 1))
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->sole();

        app(RecruitmentApprovalService::class)
            ->requestChanges($manager, $step, 1, 'State the hours.', ['weekly_hours']);

        Livewire::actingAs($requester)
            ->test(Edit::class, ['requestId' => $request->getKey()])
            ->assertOk()
            ->assertSee(__('recruitment.changes_requested_edit_title'))
            ->assertSee('State the hours.')
            ->assertSee(__('recruitment.field_change_requested'))
            // The note appears for the fields nobody asked about.
            ->assertSee(__('recruitment.field_locked_no_changes_requested'))
            ->assertSee('cursor-not-allowed', escape: false);
    }

    /** A draft nobody has submitted has nothing locked at all. */
    public function test_a_draft_locks_nothing(): void
    {
        ['request' => $submitted, 'requester' => $requester] = $this->workflow();

        // A genuine draft rather than a submitted request stripped back: its
        // version is referenced by a workflow instance and cannot be removed,
        // and a half-dismantled request would not be the case under test.
        $request = \App\Models\RecruitmentRequest::query()->create([
            'request_number' => 'DR-001',
            'requester_user_id' => $requester->getKey(),
            'request_type' => $submitted->request_type,
            'mode' => $submitted->mode,
            'status' => \App\Enums\RecruitmentRequestStatus::Draft,
            'scope_city_id' => $submitted->scope_city_id,
            'target_position_id' => $submitted->target_position_id,
            'draft_payload_json' => ['request_type' => $submitted->request_type,
                'position_id' => $submitted->target_position_id] + $this->recruitmentFormDefaults(),
        ]);

        Livewire::actingAs($requester)
            ->test(Edit::class, ['requestId' => $request->getKey()])
            ->assertOk()
            ->assertDontSee(__('recruitment.field_locked_no_changes_requested'))
            ->assertDontSee(__('recruitment.field_change_requested'));
    }

    /**
     * Each approver may send back the part they were asked about.
     *
     * Finance is asked whether the organisation can afford this, so money is
     * what they may query. Putting the duties in front of them would be asking
     * them to adjudicate a question nobody put to them.
     */
    public function test_finance_may_only_send_back_the_money(): void
    {
        ['request' => $request] = $this->workflow();

        $request->forceFill([
            'draft_payload_json' => ['request_type' => $request->request_type, 'position_id' => $request->target_position_id]
                + $this->recruitmentFormDefaults(),
        ])->save();

        $fields = app(RecruitmentEditableFields::class)
            ->selectable($request->fresh(), WorkflowStepKey::FinanceApproval);

        // The contract type is HR's, not theirs: what a post costs is a
        // financial question; which contract somebody is on is not.
        $this->assertEqualsCanonicalizing(['salary_amount', 'currency', 'salary_per'], $fields);
        $this->assertNotContains('contract_type_id', $fields);
    }

    /** HR reviews everything except the money. */
    public function test_hr_may_send_back_everything_but_the_money(): void
    {
        ['request' => $request] = $this->workflow();

        $request->forceFill([
            'draft_payload_json' => ['request_type' => $request->request_type, 'position_id' => $request->target_position_id]
                + $this->recruitmentFormDefaults(),
        ])->save();

        $fields = app(RecruitmentEditableFields::class)
            ->selectable($request->fresh(), WorkflowStepKey::GovernorateHrApproval);

        $this->assertContains('weekly_hours', $fields);
        $this->assertContains('position_id', $fields);

        foreach (['salary_amount', 'currency', 'salary_per'] as $money) {
            $this->assertNotContains($money, $fields, "HR should not be querying {$money}.");
        }

        // The contract type is theirs.
        $this->assertContains('contract_type_id', $fields);
    }

    /** The manager approves the request as a whole and may query any of it. */
    public function test_the_manager_may_send_back_anything(): void
    {
        ['request' => $request] = $this->workflow();

        $payload = ['request_type' => $request->request_type, 'position_id' => $request->target_position_id]
            + $this->recruitmentFormDefaults();
        $request->forceFill(['draft_payload_json' => $payload])->save();

        $fields = app(RecruitmentEditableFields::class)
            ->selectable($request->fresh(), WorkflowStepKey::ManagerApproval);

        $this->assertContains('salary_amount', $fields);
        $this->assertContains('weekly_hours', $fields);
        $this->assertNotContains('request_type', $fields, 'The type is fixed once the request exists.');
    }
}
