<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentRequestSubmissionService;
use Database\Seeders\RecruitmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Draft → submitted: completeness, versioning and the duplicate rule.
 */
class RecruitmentSubmissionTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    private User $manager;

    private Position $root;

    private Position $target;

    private ContractType $contractType;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $city = $this->makeCity('SYR', '000');
        $this->manager = $this->makeUser($city, 'manager');
        $this->manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(
            'recruitment-requests.submit-without-manager',
            'web',
        ));
        $this->seed(RecruitmentWorkflowSeeder::class);
        $this->contractType = ContractType::query()->create([
            'name_en' => 'Trial Volunteer Contract',
            'name_ar' => 'عقد تطوّع تجريبي',
        ]);

        $this->root = Position::query()->create([
            'city_id' => $city->id,
            'program_id' => Program::query()->create(['name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance', 'start_date' => now(), 'is_active' => true])->id,
            'department_id' => Department::query()->create(['name' => 'Finance', 'code' => 'FIN'])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5])->id,
            'title_en' => 'Manager', 'title_ar' => 'مدير',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);

        Contract::query()->create([
            'employee_id' => $this->manager->employee_id, 'position_id' => $this->root->id,
            'contract_type_id' => $this->contractType->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        $this->target = $this->root->replicate();
        $this->target->fill(['direct_manager_id' => $this->root->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();
        $origin = RecruitmentRequest::create([
            'request_number' => 'ORIGIN', 'requester_user_id' => $this->manager->id,
            'request_type' => Type::IncreaseHeadcount, 'status' => Status::Applied,
            'target_position_id' => $this->target->id, 'scope_city_id' => $city->id,
        ]);
        Vacancy::create([
            'position_id' => $this->target->id, 'created_from_request_id' => $origin->id,
            'status' => 'open', 'needed_count' => 2, 'filled_count' => 0, 'open_at' => now()->subDay(),
        ]);
    }

    /**
     * Every field on the form is answered before anything is saved.
     *
     * A draft used to be allowed to be half-finished. It is not any more, so
     * the completeness check now fires on the way in rather than on the way
     * out, and names everything that is missing at once.
     */
    public function test_an_incomplete_draft_names_every_missing_field(): void
    {
        try {
            $this->draft([]);
            $this->fail('An incomplete draft should not save.');
        } catch (ValidationException $exception) {
            foreach (['recruitment_reason', 'priority', 'volunteer_type', 'weekly_hours', 'needed_by_date',
                'salary_amount', 'currency', 'salary_per', 'contract_type_id'] as $field) {
                $this->assertArrayHasKey('data.'.$field, $exception->errors(), "{$field} should be reported as missing.");
            }
        }

        // setUp seeds one applied request to hang the vacancy off; nothing new was written.
        $this->assertSame(0, RecruitmentRequest::query()->where('status', Status::Draft)->count());
    }

    public function test_a_complete_draft_submits_and_freezes_version_one(): void
    {
        $request = $this->submit($this->draft($this->complete()));

        $this->assertSame(Status::Submitted, (int) $request->status);
        $this->assertNotNull($request->submitted_at);

        $version = $request->versions()->sole();
        $this->assertSame(1, (int) $version->version_no);
        $this->assertSame($this->manager->id, (int) $version->created_by_user_id);

        // The details row carries the column names RV-1 compares, not the
        // draft's own field names.
        $detail = $version->positionDetail;
        $this->assertSame('operational_need', $detail->recruitment_reason);
        $this->assertSame('normal', $detail->priority);
        $this->assertSame('onsite', $detail->volunteer_type);
        $this->assertSame(40, (int) $detail->weekly_hours);
        $this->assertSame('2026-12-01', $detail->expected_start_date->format('Y-m-d'));
        $this->assertSame($this->target->id, (int) $detail->position_id);
        $this->assertSame(5, (int) $detail->current_headcount);
    }

    public function test_the_submit_button_disappears_after_a_draft_is_submitted(): void
    {
        $request = $this->draft($this->complete());

        \Livewire\Livewire::actingAs($this->manager)
            ->test(\App\Livewire\Recruitment\Requests\Details::class, ['requestId' => $request->id])
            ->assertSee('wire:click="submit"', false)
            ->call('submit')
            ->assertDontSee('wire:click="submit"', false)
            ->call('submit')
            ->assertForbidden();

        $this->assertNotSame(Status::Draft, (int) $request->fresh()->status);
    }

    /**
     * Idempotent without a stored key: the row is locked and the status read
     * again, so a second click returns the same request rather than a version 2.
     */
    public function test_submitting_twice_produces_one_version(): void
    {
        $request = $this->draft($this->complete());

        $first = $this->submit($request);
        $second = $this->submit($request);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $request->versions()->count());
        $this->assertSame(Status::UnderReview, (int) $second->status);
    }

    /** Same position, same type, while the first is still being processed. */
    public function test_a_second_active_request_of_the_same_type_on_one_position_is_refused(): void
    {
        $this->submit($this->draft($this->complete()));
        $second = $this->draft($this->complete());

        try {
            $this->submit($second);
            $this->fail('A duplicate active request should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.vacancy_id', $exception->errors());
        }
    }

    /** A different type on the same position is a different, legitimate ask. */
    public function test_a_different_type_on_the_same_position_is_allowed(): void
    {
        $this->submit($this->draft($this->complete()));

        $increase = $this->draft(array_merge($this->complete(), ['additional_count' => 2]), Type::IncreaseHeadcount);

        $this->assertSame(Status::Submitted, (int) $this->submit($increase)->status);
    }

    /** Rejected and cancelled are final, so they release the position at once. */
    public function test_a_rejected_request_frees_the_position_immediately(): void
    {
        $first = $this->submit($this->draft($this->complete()));
        $first->forceFill(['status' => Status::Rejected])->save();

        $second = $this->submit($this->draft($this->complete()));

        $this->assertSame(Status::Submitted, (int) $second->status);
    }

    /** Filling and removing an empty approved seat are contradictory intentions. */
    public function test_fill_and_close_cannot_both_be_active_on_one_position(): void
    {
        $vacancy = Vacancy::query()->sole();

        $fill = $this->draft(array_merge($this->complete(), ['vacancy_id' => $vacancy->id]));
        $this->submit($fill);

        $close = $this->draft([
            'reason' => 'No longer needed',
            'effective_date' => '2026-12-01',
        ], Type::CloseVacancy);

        try {
            $this->submit($close);
            $this->fail('Removing an approved seat that is being filled should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.position_id', $exception->errors());
        }
    }

    /** The establishment may not fall below the people already in it. */
    public function test_a_reduction_below_the_existing_employees_is_refused(): void
    {
        $this->target->forceFill(['exist_emp' => 4, 'max_emp' => 5])->save();

        try {
            $request = $this->draft([
                'reduce_by' => 3,
                'reason' => 'Restructure',
                'effective_date' => '2026-12-01',
            ], Type::DecreaseHeadcount);
            $this->submit($request);
            $this->fail('Reducing below the existing employees should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.reduce_by', $exception->errors());
        }
    }

    /** Editing a draft rewrites it in place; no version exists until submit. */
    public function test_a_draft_can_be_edited_before_submitting(): void
    {
        $request = $this->draft($this->complete());

        app(RecruitmentRequestDraftService::class)->update(
            $this->manager,
            $request,
            $this->payload(['detailed_explanation' => 'A better reason']),
        );

        $this->assertSame('A better reason', $request->fresh()->draft_payload_json['detailed_explanation']);
        $this->assertSame(0, $request->versions()->count());
    }

    /** A submitted request is no longer the requester's private working copy. */
    public function test_a_submitted_request_can_no_longer_be_edited(): void
    {
        $request = $this->submit($this->draft($this->complete()));

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(RecruitmentRequestDraftService::class)->update($this->manager, $request, $this->payload());
    }

    /** Resubmitting after changes were requested freezes version 2 (T11). */
    public function test_resubmitting_after_changes_creates_the_next_version(): void
    {
        $request = $this->submit($this->draft($this->complete()));
        $request->forceFill(['status' => Status::ChangesRequested])->save();

        // A request is locked once submitted, so the edit below is only
        // possible because an approver asked for this field by name.
        $request->events()->create([
            'event_type' => \App\Enums\RecruitmentEventType::ChangesRequested,
            'from_status' => Status::UnderReview,
            'to_status' => Status::ChangesRequested,
            'actor_user_id' => $this->manager->id,
            'actor_role' => $this->manager->myRole(),
            'comment' => 'Explain it better.',
            'meta' => ['requested_fields' => ['detailed_explanation']],
        ]);

        app(RecruitmentRequestDraftService::class)->update(
            $this->manager,
            $request,
            $this->payload(['detailed_explanation' => 'Revised']),
        );

        $resubmitted = $this->submit($request);

        $this->assertSame(Status::Resubmitted, (int) $resubmitted->status);
        $this->assertSame([1, 2], $request->versions()->orderBy('version_no')->pluck('version_no')->map(fn ($n) => (int) $n)->all());
        $this->assertSame('Revised', $request->versions()->where('version_no', 2)->sole()->positionDetail->detailed_explanation);
    }

    public function test_a_draft_can_be_cancelled_and_cancelling_twice_is_harmless(): void
    {
        $request = $this->draft($this->complete());
        $submissions = app(RecruitmentRequestSubmissionService::class);

        $submissions->cancel($this->manager, $request);
        $this->assertSame(Status::Cancelled, (int) $request->fresh()->status);

        $submissions->cancel($this->manager, $request->fresh());
        $this->assertSame(Status::Cancelled, (int) $request->fresh()->status);
    }

    /** @return array<string, mixed> */
    public function test_different_open_vacancies_on_the_same_position_can_have_separate_requests(): void
    {
        $firstVacancy = Vacancy::sole();
        $secondVacancy = Vacancy::create([
            'position_id' => $this->target->id,
            'created_from_request_id' => $this->draft($this->complete(Type::IncreaseHeadcount), Type::IncreaseHeadcount)->id,
            'status' => 'open', 'needed_count' => 1, 'filled_count' => 0, 'open_at' => now(),
        ]);
        foreach ([$firstVacancy, $secondVacancy] as $vacancy) {
            $request = $this->draft(array_merge($this->complete(), ['vacancy_id' => $vacancy->id]));
            $this->assertSame(Status::Submitted, $this->submit($request)->status);
        }
    }

    public function test_a_vacancy_filled_after_draft_creation_cannot_be_submitted(): void
    {
        $request = $this->draft($this->complete());
        Vacancy::sole()->update(['filled_count' => 2]);
        try {
            $this->submit($request);
            $this->fail('Availability must be checked again on submit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.vacancy_id', $exception->errors());
        }
        $this->assertSame(0, $request->versions()->count());
        $this->assertSame(Status::Draft, $request->fresh()->status);
    }

    /**
     * The three fixed lists accept their own values and nothing else.
     *
     * They are stored as slugs in the existing text columns rather than as
     * numeric enums, so the guard is the validation rule, not the column type.
     */
    public function test_the_fixed_lists_reject_a_value_that_is_not_on_them(): void
    {
        foreach ([
            'recruitment_reason' => 'made_up_reason',
            'priority' => 'whenever',
            'volunteer_type' => 'hybrid',
        ] as $field => $value) {
            try {
                $this->draft([$field => $value]);
                $this->fail("{$field} should only accept its configured values.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.'.$field, $exception->errors());
            }
        }
    }

    /** Choosing "other" without saying what it is answers nothing. */
    public function test_other_as_a_reason_must_say_what_it_is(): void
    {
        try {
            $this->draft(array_merge($this->complete(), ['recruitment_reason' => 'other']));
            $this->fail('"Other" with no text should not save.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.other_reason', $exception->errors());
        }

        $request = $this->draft(array_merge($this->complete(), [
            'recruitment_reason' => 'other',
            'other_reason' => 'A reason the list does not cover',
        ]));

        $submitted = $this->submit($request);
        $detail = $submitted->versions()->sole()->positionDetail;

        $this->assertSame('other', $detail->recruitment_reason);
        $this->assertSame('A reason the list does not cover', $detail->other_reason);
    }

    /** The agreement period lands in the columns the migrations already have. */
    public function test_the_agreement_period_is_stored_on_the_version(): void
    {
        $request = $this->draft(array_merge($this->complete(), [
            'volunteer_period_start_date' => '2026-12-01',
            'volunteer_period_end_date' => '2027-06-01',
        ]));

        $detail = $this->submit($request)->versions()->sole()->positionDetail;

        $this->assertSame('2026-12-01', $detail->volunteer_period_start_date->format('Y-m-d'));
        $this->assertSame('2027-06-01', $detail->volunteer_period_end_date->format('Y-m-d'));
    }

    /**
     * The approver is told when the same position was refused before (D61).
     *
     * Rejection releases the position at once, so the same ask can come back
     * with nobody the wiser. Shown, not blocked.
     */
    public function test_a_previous_rejection_on_the_position_is_shown(): void
    {
        $earlier = $this->submit($this->draft($this->complete()));
        $earlier->forceFill(['status' => Status::Rejected])->save();
        $earlier->events()->create([
            'event_type' => \App\Enums\RecruitmentEventType::StepRejected,
            'actor_user_id' => $this->manager->id,
            'actor_role' => $this->manager->myRole(),
            'from_status' => Status::UnderReview,
            'to_status' => Status::Rejected,
            'comment' => 'The budget for this does not exist yet.',
        ]);

        $current = $this->draft($this->complete());

        \Livewire\Livewire::actingAs($this->manager)
            ->test(\App\Livewire\Recruitment\Requests\Details::class, ['requestId' => $current->id])
            ->assertSee($earlier->request_number)
            ->assertSee('The budget for this does not exist yet.');
    }

    /** A first request on a position has no history to show. */
    public function test_a_position_with_no_history_shows_no_panel(): void
    {
        $request = $this->draft($this->complete());

        \Livewire\Livewire::actingAs($this->manager)
            ->test(\App\Livewire\Recruitment\Requests\Details::class, ['requestId' => $request->id])
            ->assertDontSee(__('recruitment.previous_rejections'));
    }

    /** @return array<string, mixed> */
    private function complete(int $type = Type::FillVacancy): array
    {
        return $this->recruitmentFormDefaults($type, ['contract_type_id' => $this->contractType->id]);
    }

    /** Exactly what is passed, so a test can deliberately leave fields out. */
    /** Exactly what is passed, so a test can deliberately leave fields out. */
    private function draft(array $payload, int $type = Type::FillVacancy): RecruitmentRequest
    {
        return app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge([
            'request_type' => $type,
            'position_id' => $this->target->id,
        ], $payload));
    }

    /** A full, valid payload — update() re-validates everything, not a diff. */
    private function payload(array $overrides = [], int $type = Type::FillVacancy): array
    {
        return array_merge([
            'request_type' => $type,
            'position_id' => $this->target->id,
        ], $this->complete($type), $overrides);
    }

    private function submit(RecruitmentRequest $request): RecruitmentRequest
    {
        return app(RecruitmentRequestSubmissionService::class)->submit($this->manager, $request->fresh());
    }
}
