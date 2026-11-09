<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Livewire\Recruitment\Requests\Create;
use App\Livewire\Recruitment\Requests\Edit;
use App\Models\Contract;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\User;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentRequestSubmissionService;
use Database\Seeders\RecruitmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The request form end to end: save & submit in one step, and values that
 * stop meaning anything once another answer changes are not kept.
 */
class RecruitmentRequestFormFlowTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    private User $manager;

    private Position $target;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $city = $this->makeCity('SYR', '000');
        $this->manager = $this->makeUser($city, 'manager');
        $this->manager->givePermissionTo(Permission::findOrCreate('recruitment-requests.submit-without-manager', 'web'));
        $this->seed(RecruitmentWorkflowSeeder::class);

        $root = Position::query()->create([
            'city_id' => $city->id,
            'program_id' => Program::query()->create(['name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance', 'start_date' => now(), 'is_active' => true])->id,
            'department_id' => Department::query()->create(['name' => 'Finance', 'code' => 'FIN'])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5])->id,
            'title_en' => 'Manager', 'title_ar' => 'مدير',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);

        Contract::query()->create([
            'employee_id' => $this->manager->employee_id, 'position_id' => $root->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        $this->target = $root->replicate();
        $this->target->fill(['direct_manager_id' => $root->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();
    }

    public function test_the_form_offers_save_and_submit_to_a_requester_who_may_submit(): void
    {
        Livewire::actingAs($this->manager)->test(Create::class)->assertViewHas('canSubmit', true);
    }

    public function test_save_and_submit_creates_and_submits_a_valid_fill_request(): void
    {
        $screen = Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.position_id', $this->target->id);

        $this->fillRecruitmentForm($screen)
            ->call('saveAndSubmit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $request = RecruitmentRequest::query()->sole();
        // Submitted, and possibly already picked up by the first approval step.
        $this->assertContains((int) $request->status, [Status::Submitted, Status::UnderReview]);
        $this->assertSame(1, $request->versions()->count());
        $this->assertSame($this->manager->id, (int) $request->requester_user_id);
    }

    public function test_save_and_submit_with_invalid_data_shows_errors_and_writes_nothing(): void
    {
        Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.position_id', $this->target->id)
            ->set('data.recruitment_reason', 'operational_need')
            ->set('data.weekly_hours', 200)
            ->call('saveAndSubmit')
            ->assertHasErrors(['data.priority', 'data.weekly_hours', 'data.salary_amount'])
            ->assertNoRedirect();

        $this->assertSame(0, RecruitmentRequest::query()->count());
    }

    /** A refused submission keeps the draft and says why, as the details screen does. */
    public function test_a_refused_submission_leaves_the_draft_saved_and_reports_the_reason(): void
    {
        $this->partialMock(RecruitmentRequestSubmissionService::class, function ($mock): void {
            $mock->shouldReceive('submit')->andThrow(ValidationException::withMessages([
                'data.position_id' => 'The position moved out of your tree.',
            ]));
        });

        $screen = Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.position_id', $this->target->id);

        $this->fillRecruitmentForm($screen)
            ->call('saveAndSubmit')
            ->assertRedirect();

        $this->assertSame(Status::Draft, (int) RecruitmentRequest::query()->sole()->status);
        $this->assertContains(
            ['type' => 'error', 'message' => 'The position moved out of your tree.', 'undo' => null],
            session('toasts', []),
        );
    }

    /** A missing approval workflow is a set-up fault: the draft survives, nothing throws. */
    public function test_save_and_submit_without_an_active_workflow_keeps_the_draft(): void
    {
        RecruitmentWorkflowDefinition::query()->update(['is_active' => false]);

        $screen = Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.position_id', $this->target->id);

        $this->fillRecruitmentForm($screen)
            ->call('saveAndSubmit')
            ->assertHasNoErrors();

        $request = RecruitmentRequest::query()->sole();
        $screen->assertRedirect(route('recruitment.requests.show', ['requestId' => $request->id]));
        $this->assertSame(Status::Draft, (int) $request->status);
        $this->assertSame(0, $request->versions()->count());
        $this->assertContains(
            ['type' => 'error', 'message' => __('recruitment.workflow_not_configured'), 'undo' => null],
            session('toasts', []),
        );
    }

    /** T11 through the form: a request sent back is saved and resubmitted. */
    public function test_save_and_submit_on_a_returned_request_resubmits_it(): void
    {
        $request = app(RecruitmentRequestDraftService::class)->create($this->manager, $this->payload());
        app(RecruitmentRequestSubmissionService::class)->submit($this->manager, $request->fresh());
        $request->forceFill(['status' => Status::ChangesRequested])->save();
        $request->events()->create([
            'event_type' => RecruitmentEventType::ChangesRequested,
            'from_status' => Status::UnderReview,
            'to_status' => Status::ChangesRequested,
            'actor_user_id' => $this->manager->id,
            'actor_role' => $this->manager->myRole(),
            'comment' => 'Explain it better.',
            'meta' => ['requested_fields' => ['detailed_explanation']],
        ]);

        Livewire::actingAs($this->manager)->test(Edit::class, ['requestId' => $request->id])
            ->assertViewHas('canSubmit', true)
            ->set('data.detailed_explanation', 'Revised')
            ->call('saveAndSubmit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertContains((int) $request->fresh()->status, [Status::Resubmitted, Status::UnderReview]);
        $this->assertSame(2, $request->versions()->count());
    }

    public function test_changing_the_reason_away_from_other_clears_the_other_text(): void
    {
        Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.recruitment_reason', 'other')
            ->set('data.other_reason', 'Something the list lacks')
            ->set('data.recruitment_reason', 'operational_need')
            ->assertSet('data.other_reason', null);
    }

    /** A hand-written payload cannot keep an "other" text either. */
    public function test_the_service_strips_other_text_unless_the_reason_is_other(): void
    {
        $request = app(RecruitmentRequestDraftService::class)->create(
            $this->manager,
            $this->payload(['other_reason' => 'Stale text']),
        );

        $this->assertArrayNotHasKey('other_reason', $request->fresh()->draft_payload_json);
    }

    public function test_switching_the_request_type_drops_the_other_types_fields(): void
    {
        Livewire::actingAs($this->manager)->test(Create::class)
            ->set('data.request_type', Type::CreatePosition)
            ->set('data.title_en', 'Proposed')
            ->set('data.max_emp', 3)
            ->set('data.request_type', Type::FillVacancy)
            ->assertSet('data.title_en', null)
            ->assertSet('data.max_emp', null);

        // And whatever a client sends anyway is not saved with the request.
        $request = app(RecruitmentRequestDraftService::class)->create(
            $this->manager,
            $this->payload(['title_en' => 'Proposed', 'max_emp' => 3, 'reduce_by' => 1, 'reason' => 'x']),
        );

        foreach (['title_en', 'max_emp', 'reduce_by', 'reason'] as $field) {
            $this->assertArrayNotHasKey($field, $request->fresh()->draft_payload_json);
        }
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge(
            ['request_type' => Type::FillVacancy, 'position_id' => $this->target->id],
            $this->recruitmentFormDefaults(Type::FillVacancy),
            $overrides,
        );
    }
}
