<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestType as Type;
use App\Models\Contract;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\User;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentEditableFields;
use App\Services\RecruitmentRequestChangeClassifier as Classifier;
use App\Services\RecruitmentRequestDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A submitted request is a fixed document.
 *
 * The approvers are deciding on what they were given. A requester who can
 * reword it underneath them makes every approval mean something other than
 * what it said, so once submitted the request is locked, and the only way back
 * in is an approver naming the fields they want changed.
 */
class RecruitmentFieldLockTest extends TestCase
{
    use BuildsRecruitmentRequests, BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** Before the first submission it is still the requester's own document. */
    public function test_a_draft_that_was_never_submitted_is_fully_editable(): void
    {
        [$requester, $payload] = $this->draftFixture();
        $request = app(RecruitmentRequestDraftService::class)->create($requester, $payload);

        $this->assertSame(
            RecruitmentEditableFields::ALL,
            app(RecruitmentEditableFields::class)->for($request),
        );

        $updated = app(RecruitmentRequestDraftService::class)
            ->update($requester, $request->fresh(), ['priority' => 'urgent', 'salary_amount' => 999] + $payload);

        $this->assertSame('urgent', $updated->draft_payload_json['priority']);
        $this->assertSame(999, $updated->draft_payload_json['salary_amount']);
    }

    /** Once submitted, nothing is editable until somebody asks. */
    public function test_a_submitted_request_is_locked_outright(): void
    {
        ['request' => $request] = $this->submitted();

        $this->assertSame([], app(RecruitmentEditableFields::class)->for($request));
        $this->assertFalse(app(RecruitmentEditableFields::class)->anyEditable($request));
    }

    /** An ask that names no field would hand back a document nobody can change. */
    public function test_requesting_changes_without_naming_fields_is_refused(): void
    {
        ['step' => $step, 'manager' => $manager] = $this->submitted();

        try {
            app(RecruitmentApprovalService::class)
                ->requestChanges($manager, $step, 1, 'Fix the hours.', []);
            $this->fail('An ask with no fields should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fields', $exception->errors());
            $this->assertNull($step->fresh()->decision, 'A refused decision writes nothing.');
        }
    }

    /** The ask is recorded on the decision that made it. */
    public function test_the_requested_fields_are_recorded_on_the_event(): void
    {
        ['request' => $request, 'step' => $step, 'manager' => $manager] = $this->submitted();

        app(RecruitmentApprovalService::class)
            ->requestChanges($manager, $step, 1, 'The hours are wrong.', ['weekly_hours', 'priority']);

        $event = $request->events()
            ->where('event_type', RecruitmentEventType::ChangesRequested)
            ->latest('id')->first();

        $this->assertSame(['weekly_hours', 'priority'], $event->meta['requested_fields']);
        $this->assertSame('The hours are wrong.', $event->comment);
        $this->assertSame($manager->id, (int) $event->actor_user_id);
        $this->assertSame($manager->myRole(), $event->actor_role);

        $this->assertSame(
            ['weekly_hours', 'priority'],
            app(RecruitmentEditableFields::class)->for($request->fresh()),
        );
    }

    /** What was asked for may change; everything else is refused by the server. */
    public function test_only_the_requested_fields_may_be_edited(): void
    {
        ['request' => $request, 'step' => $step, 'manager' => $manager,
            'requester' => $requester, 'payload' => $payload] = $this->submitted();

        app(RecruitmentApprovalService::class)
            ->requestChanges($manager, $step, 1, 'The hours are wrong.', ['weekly_hours']);

        // The permitted one goes through.
        $updated = app(RecruitmentRequestDraftService::class)
            ->update($requester, $request->fresh(), ['weekly_hours' => 12] + $payload);
        $this->assertSame(12, $updated->draft_payload_json['weekly_hours']);

        // Anything else is refused, and named.
        try {
            app(RecruitmentRequestDraftService::class)->update(
                $requester,
                $request->fresh(),
                ['weekly_hours' => 12, 'salary_amount' => 9999] + $payload,
            );
            $this->fail('A locked field should not be editable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.salary_amount', $exception->errors());
            $this->assertArrayNotHasKey('data.weekly_hours', $exception->errors());
        }

        // And the refusal changed nothing.
        $this->assertSame($payload['salary_amount'], $request->fresh()->draft_payload_json['salary_amount']);
    }

    /**
     * The lock is the server's, not the screen's.
     *
     * Disabling an input is a courtesy to whoever is looking at it and no
     * obstacle at all to a call aimed straight at the service, so the refusal
     * has to come from the service itself. A request still under review is
     * refused earlier and more bluntly, by the policy; this is the narrower
     * case, where editing is allowed but only of what was asked for.
     */
    public function test_the_lock_holds_against_a_call_that_never_saw_the_screen(): void
    {
        ['request' => $request, 'step' => $step, 'manager' => $manager,
            'requester' => $requester, 'payload' => $payload] = $this->submitted();

        app(RecruitmentApprovalService::class)
            ->requestChanges($manager, $step, 1, 'Only the hours.', ['weekly_hours']);

        try {
            app(RecruitmentRequestDraftService::class)
                ->update($requester, $request->fresh(), ['priority' => 'urgent'] + $payload);
            $this->fail('A field nobody asked about should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.priority', $exception->errors());
        }
    }

    /** A request under review is refused outright, before any field is looked at. */
    public function test_a_request_under_review_cannot_be_edited_at_all(): void
    {
        ['request' => $request, 'requester' => $requester, 'payload' => $payload] = $this->submitted();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(RecruitmentRequestDraftService::class)
            ->update($requester, $request->fresh(), ['priority' => 'urgent'] + $payload);
    }

    /**
     * Changing the proposed manager does not send the workflow back.
     *
     * It names the parent proposed for the new post. The manager who approves
     * is found from the requester's own chair, so this field routes nothing and
     * invalidates nobody's approval.
     */
    public function test_changing_the_proposed_manager_does_not_restart_the_workflow(): void
    {
        $classifier = app(Classifier::class);

        $category = $classifier->classifyPayload(
            ['direct_manager_id' => 1],
            ['direct_manager_id' => 2],
        );

        $this->assertSame(Classifier::DESCRIPTIVE, $category);
        $this->assertNull($classifier->restartsFrom($category), 'Descriptive changes resume where they stopped.');
    }

    /**
     * Moving the effective date does not send the workflow back either.
     *
     * It decides when the change takes effect, never whether it should. An
     * approver who agreed to close a post still agrees to close it a month
     * later, so nobody's judgement is invalidated by the date moving.
     */
    public function test_moving_the_effective_date_does_not_restart_the_workflow(): void
    {
        $classifier = app(Classifier::class);

        $category = $classifier->classifyPayload(
            ['effective_date' => '2026-10-01'],
            ['effective_date' => '2026-12-01'],
        );

        $this->assertSame(Classifier::DESCRIPTIVE, $category);
        $this->assertNull($classifier->restartsFrom($category));
    }

    /** The picker offers the request's own fields, never the type fixed at creation. */
    public function test_the_selectable_fields_come_from_the_request_and_exclude_its_type(): void
    {
        ['request' => $request, 'payload' => $payload] = $this->submitted();

        $selectable = app(RecruitmentEditableFields::class)->selectable($request);

        $this->assertContains('weekly_hours', $selectable);
        $this->assertContains('salary_amount', $selectable);
        $this->assertNotContains('request_type', $selectable);
        $this->assertEqualsCanonicalizing(
            array_diff(array_keys($payload), ['request_type']),
            $selectable,
        );
    }

    /**
     * A real submitted request, waiting on a real first approver.
     *
     * Built rather than hand-written: the edit path re-checks that the target
     * is inside the requester's tree, so the chart has to be a genuine one —
     * a manager above, the requester, and the post being recruited for below.
     *
     * @return array{request: RecruitmentRequest, step: RecruitmentWorkflowInstanceStep,
     *               manager: User, requester: User, payload: array<string, mixed>}
     */
    private function submitted(): array
    {
        [$requester, $payload, $manager] = $this->chain();

        $request = app(RecruitmentRequestDraftService::class)->create($requester, $payload);
        $request = app(\App\Services\RecruitmentRequestSubmissionService::class)
            ->submit($requester, $request->fresh());

        $step = $request->fresh()->workflowInstances()->latest('id')->first()
            ->steps()->whereNull('decision')
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->orderBy('id')->first();

        return ['request' => $request->fresh(), 'step' => $step,
            'manager' => $manager, 'requester' => $requester, 'payload' => $payload];
    }

    /**
     * A manager, a requester beneath them, and a post beneath that.
     *
     * @return array{0: User, 1: array<string, mixed>, 2: User}
     */
    private function chain(): array
    {
        $city = $this->makeCity('SYR', '000');
        $manager = $this->makeUser($city, 'manager');
        $requester = $this->makeUser($city, 'manager');
        $this->seed(\Database\Seeders\RecruitmentWorkflowSeeder::class);

        $program = Program::query()->create([
            'name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance',
            'start_date' => now(), 'is_active' => true,
        ]);
        $department = Department::query()->create(['name' => 'Finance', 'code' => 'FIN']);
        $level = JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5]);

        $top = Position::query()->create([
            'city_id' => $city->id, 'program_id' => $program->id,
            'department_id' => $department->id, 'job_level_id' => $level->id,
            'title_en' => 'Head', 'title_ar' => 'رئيس',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);
        $middle = $top->replicate();
        $middle->fill(['direct_manager_id' => $top->id, 'title_en' => 'Manager', 'exist_emp' => 0])->save();
        $target = $top->replicate();
        $target->fill(['direct_manager_id' => $middle->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();

        foreach ([[$manager, $top], [$requester, $middle]] as [$user, $position]) {
            Contract::query()->create([
                'employee_id' => $user->employee_id, 'position_id' => $position->id,
                'contract_type_id' => $this->recruitmentContractTypeId(),
                'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
                'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
            ]);
        }

        return [
            $requester,
            ['request_type' => Type::FillVacancy, 'position_id' => $target->id] + $this->recruitmentFormDefaults(),
            $manager,
        ];
    }

    /**
     * A requester with a post beneath them, and a full payload aimed at it.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function draftFixture(): array
    {
        $city = $this->makeCity('SYR', '000');
        $requester = $this->makeUser($city, 'manager');
        $requester->givePermissionTo(Permission::findOrCreate('recruitment-requests.submit-without-manager', 'web'));

        $root = Position::query()->create([
            'city_id' => $city->id,
            'program_id' => Program::query()->create([
                'name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance',
                'start_date' => now(), 'is_active' => true,
            ])->id,
            'department_id' => Department::query()->create(['name' => 'Finance', 'code' => 'FIN'])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5])->id,
            'title_en' => 'Manager', 'title_ar' => 'مدير',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);

        Contract::query()->create([
            'employee_id' => $requester->employee_id, 'position_id' => $root->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        $target = $root->replicate();
        $target->fill(['direct_manager_id' => $root->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();

        return [$requester, ['request_type' => Type::FillVacancy, 'position_id' => $target->id]
            + $this->recruitmentFormDefaults()];
    }
}
