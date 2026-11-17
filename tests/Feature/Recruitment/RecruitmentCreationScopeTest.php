<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Requests\Create;
use App\Livewire\Recruitment\Requests\Details;
use App\Livewire\Recruitment\Requests\Index;
use App\Livewire\Recruitment\Requests\TeamStaffing;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentCreationScopeTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    private User $manager;

    private Position $root;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $national = $this->makeCity('SYR', '000');
        $this->manager = $this->makeUser($national, 'manager');
        $this->root = Position::query()->create([
            'city_id' => $national->id,
            'program_id' => Program::query()->create(['name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance', 'start_date' => now(), 'is_active' => true])->id,
            'department_id' => Department::query()->create(['name' => 'Finance', 'code' => 'FIN'])->id,
            'job_level_id' => JobLevel::query()->create(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5])->id,
            'title_en' => 'National manager', 'title_ar' => 'مدير وطني',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);
        Contract::query()->create([
            'employee_id' => $this->manager->employee_id, 'position_id' => $this->root->id,
            'contract_type_id' => ContractType::query()->create(['name_en' => 'Annual', 'name_ar' => 'سنوي'])->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);
    }

    public function test_national_manager_can_target_a_descendant_in_another_city_and_program(): void
    {
        $city = $this->makeCity('HMS', '002');
        $program = Program::query()->create(['name' => 'Other', 'code' => 'OTH', 'slug' => 'other', 'start_date' => now(), 'is_active' => true]);
        $child = $this->child($this->root, ['city_id' => $city->id, 'program_id' => $program->id]);

        $this->assertTrue(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $child]));
        $this->assertTrue(Gate::forUser($this->manager)->allows('createUnderPosition', [RecruitmentRequest::class, $child]));
    }

    public function test_creation_reaches_arbitrary_depth_without_widening_one_level_visibility(): void
    {
        $child = $this->child($this->root);
        $grandchild = $this->child($child);
        $leaf = $this->child($grandchild);
        $resolver = app(RecruitmentScopeResolver::class);

        $this->assertTrue(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $leaf]));
        $this->assertSame([$child->id], $resolver->directReportPositionIds($this->manager));
        $this->assertEqualsCanonicalizing([$child->id, $grandchild->id, $leaf->id], $resolver->manageablePositionIds($this->manager));
    }

    public function test_same_city_and_program_do_not_authorize_another_tree_or_technical_reports(): void
    {
        $other = $this->child($this->root, ['direct_manager_id' => null, 'tech_manager_id' => $this->root->id]);
        $leaf = $this->child($other);

        foreach ([$other, $leaf, $this->root] as $target) {
            $this->assertFalse(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $target]));
        }
        $this->assertFalse(Gate::forUser($this->manager)->allows('createUnderPosition', [RecruitmentRequest::class, $leaf]));
    }

    public function test_manager_without_reports_can_create_under_own_position(): void
    {
        $this->assertSame([], app(RecruitmentScopeResolver::class)->manageablePositionIds($this->manager));
        $this->assertTrue(Gate::forUser($this->manager)->allows('createUnderPosition', [RecruitmentRequest::class, $this->root]));
        $this->assertFalse(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $this->root]));
    }

    public function test_team_staffing_renders_only_the_manager_subtree_with_position_actions(): void
    {
        $child = $this->child($this->root, ['title_en' => 'Staffed child', 'max_emp' => 2]);
        $outside = $this->child($this->root, ['direct_manager_id' => null, 'title_en' => 'Outside branch']);

        Livewire::actingAs($this->manager)->test(TeamStaffing::class)
            ->assertSee('Staffed child')
            ->assertDontSee('Outside branch')
            ->assertSee('Fill vacancy')
            ->assertSee('Create position below');
    }

    public function test_team_staffing_is_not_available_to_hr_or_users_without_normal_request_access(): void
    {
        $this->manager->syncRoles('HR-SYR');
        $this->manager->roles()->updateExistingPivot($this->manager->roles()->first()->id, ['is_active' => true]);

        Livewire::actingAs($this->manager->fresh())->test(TeamStaffing::class)->assertForbidden();
    }

    public function test_team_staffing_action_links_prefill_the_existing_request_form(): void
    {
        $child = $this->child($this->root, ['title_en' => 'Prefilled child']);

        $this->actingAs($this->manager);

        Livewire::withQueryParams([
            'type' => RecruitmentRequestType::IncreaseHeadcount,
            'position_id' => $child->id,
        ])->test(Create::class)
            ->assertSet('data.request_type', RecruitmentRequestType::IncreaseHeadcount)
            ->assertSet('data.position_id', $child->id);
    }

    public function test_an_imported_older_contract_does_not_replace_the_current_assignment(): void
    {
        $inactivePosition = $this->root->replicate();
        $inactivePosition->fill([
            'title_en' => 'Inactive historical position',
            'is_active' => false,
        ])->save();

        Contract::query()->create([
            'employee_id' => $this->manager->employee_id,
            'position_id' => $inactivePosition->id,
            'contract_type_id' => ContractType::query()->value('id'),
            'start_date' => now()->subYear(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        $resolver = app(RecruitmentScopeResolver::class);

        $this->assertSame($this->root->id, $resolver->position($this->manager)?->id);
        $eagerLoadedManager = User::query()
            ->with('profile.contracts.position')
            ->findOrFail($this->manager->id);
        $this->assertSame($this->root->id, $resolver->position($eagerLoadedManager)?->id);
        $this->assertTrue(Gate::forUser($this->manager)->allows('create', RecruitmentRequest::class));
        Livewire::actingAs($this->manager)
            ->test(Index::class)
            ->assertSee(route('recruitment.requests.create'), false);
    }

    public function test_cycles_terminate_without_making_the_root_an_existing_target(): void
    {
        $child = $this->child($this->root);
        $leaf = $this->child($child);
        $this->root->update(['direct_manager_id' => $leaf->id]);

        $this->assertEqualsCanonicalizing([$child->id, $leaf->id], app(RecruitmentScopeResolver::class)->manageablePositionIds($this->manager));
    }

    public function test_general_manager_can_create_across_the_organization(): void
    {
        $this->manager->syncRoles('general-manager');
        $this->manager->roles()->updateExistingPivot($this->manager->roles()->first()->id, ['is_active' => true]);
        $this->manager = $this->manager->fresh();
        $outside = $this->child($this->root, ['direct_manager_id' => null]);

        $this->assertTrue(app(RecruitmentScopeResolver::class)->isOrganizationWide($this->manager));
        $this->assertTrue(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $outside]));
    }

    public function test_hr_with_any_eligible_grade_cannot_create_edit_or_submit_normal_requests(): void
    {
        $child = $this->child($this->root);
        foreach (['HR-SYR', 'HR-HMS'] as $role) {
            \App\Models\Role::findOrCreate($role, 'web');
            $this->manager->syncRoles($role);
            $this->manager->roles()->updateExistingPivot($this->manager->roles()->first()->id, ['is_active' => true]);
            $this->makeCityIfMissingHoms();
            foreach ([1, 3, 5] as $grade) {
                $this->root->jobLevel->update(['grade' => $grade]);
                $user = $this->manager->fresh();
                $request = RecruitmentRequest::query()->create([
                    'request_number' => $role.'-'.$grade, 'requester_user_id' => $user->id,
                    'request_type' => RecruitmentRequestType::FillVacancy,
                    'mode' => RecruitmentRequestMode::Normal, 'status' => RecruitmentRequestStatus::Draft,
                    'scope_city_id' => \App\Models\City::where('slug', 'HMS')->value('id'),
                    'target_position_id' => $child->id,
                ]);
                $this->assertTrue(app(RecruitmentScopeResolver::class)->isEligibleRequester($user));
                $this->assertFalse(Gate::forUser($user)->allows('create', RecruitmentRequest::class));
                $this->assertFalse(Gate::forUser($user)->allows('createForPosition', [RecruitmentRequest::class, $child]));
                $this->assertFalse(Gate::forUser($user)->allows('createUnderPosition', [RecruitmentRequest::class, $this->root]));
                $this->assertFalse(Gate::forUser($user)->allows('update', $request));
                $this->assertFalse(Gate::forUser($user)->allows('submit', $request));
                $this->assertTrue(Gate::forUser($user)->allows('directOpen', RecruitmentRequest::class));
            }
        }
    }

    public function test_losing_grade_or_reparenting_removes_creation_access(): void
    {
        $child = $this->child($this->root);
        $this->assertTrue(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $child]));
        $child->update(['direct_manager_id' => null]);
        $this->assertFalse(Gate::forUser($this->manager)->allows('createForPosition', [RecruitmentRequest::class, $child]));
        $this->root->jobLevel->update(['grade' => 2]);
        $this->assertFalse(Gate::forUser($this->manager->fresh())->allows('create', RecruitmentRequest::class));
    }

    public function test_create_screen_filters_by_tree_and_saves_a_draft_without_changing_positions(): void
    {
        $city = $this->makeCity('HMS', '002');
        $child = $this->child($this->child($this->root), ['city_id' => $city->id, 'title_en' => 'Allowed remote position']);
        $this->child($this->root, ['direct_manager_id' => null, 'title_en' => 'Unrelated private position']);
        $before = Position::count();

        $this->screen(RecruitmentRequestType::IncreaseHeadcount)
            ->assertSee('Allowed remote position')->assertDontSee('Unrelated private position')
            ->set('data.position_id', $child->id)->set('data.recruitment_reason', 'operational_need')
            ->set('data.status', RecruitmentRequestStatus::Approved)->set('data.mode', RecruitmentRequestMode::DirectHr)
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $request = RecruitmentRequest::sole();
        $this->assertSame(RecruitmentRequestStatus::Draft, $request->status);
        $this->assertSame(RecruitmentRequestMode::Normal, $request->mode);
        $this->assertEquals($city->id, $request->scope_city_id);
        $this->assertSame($before, Position::count());
        $this->assertSame(0, $request->versions()->count());
        $this->assertSame(1, $request->events()->count());
        $this->assertArrayNotHasKey('status', $request->draft_payload_json);
        Livewire::test(Details::class, ['requestId' => $request->id])->assertSee('Operational need / increased workload');
    }

    public function test_forged_target_and_reparenting_after_loading_are_rejected_on_save(): void
    {
        $child = $this->child($this->root);
        $screen = $this->screen()->set('data.position_id', $child->id);
        $child->update(['direct_manager_id' => null]);
        $screen->call('save')->assertHasErrors('data.position_id');
        $this->assertSame(0, RecruitmentRequest::count());
    }

    public function test_new_position_draft_under_own_root_accepts_other_city_and_program(): void
    {
        $city = $this->makeCity('HMS', '002');
        $program = Program::create(['name' => 'Other', 'code' => 'OTH', 'slug' => 'other', 'start_date' => now(), 'is_active' => true]);
        $this->screen(RecruitmentRequestType::CreatePosition)
            ->set('data.direct_manager_id', $this->root->id)->set('data.city_id', $city->id)
            ->set('data.program_id', $program->id)->set('data.title_ar', 'منصب مقترح')
            ->set('data.center_id', $this->centerIn($city->id))
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $request = RecruitmentRequest::sole();
        $this->assertNull($request->target_position_id);
        $this->assertEquals($this->root->id, $request->draft_payload_json['direct_manager_id']);
        $this->assertEquals($program->id, $request->draft_payload_json['program_id']);
        $this->assertSame(1, Position::count());
    }

    public function test_valid_tree_does_not_bypass_center_city_consistency(): void
    {
        $city = $this->makeCity('HMS', '002');
        $otherCity = $this->makeCity('DMS', '003');
        $center = \App\Models\Center::create(['name_en' => 'Other center', 'name_ar' => 'مركز آخر', 'city_id' => $otherCity->id, 'is_active' => true]);
        $this->screen(RecruitmentRequestType::CreatePosition)
            ->set('data.direct_manager_id', $this->root->id)->set('data.city_id', $city->id)
            ->set('data.center_id', $center->id)->call('save')->assertHasErrors('data.center_id');
        $this->assertSame(0, RecruitmentRequest::count());
    }

    public function test_hr_cannot_open_normal_form_and_does_not_see_create_button(): void
    {
        $this->manager->syncRoles('HR-SYR');
        $this->manager->roles()->updateExistingPivot($this->manager->roles()->first()->id, ['is_active' => true]);
        $user = $this->manager->fresh();
        Livewire::actingAs($user)->test(Index::class)->assertDontSee(route('recruitment.requests.create'), false);
        Livewire::test(Create::class)->assertForbidden();
    }

    public function test_request_visibility_does_not_expose_someone_elses_draft(): void
    {
        $child = $this->child($this->root);
        $request = app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge(
            $this->recruitmentFormDefaults(RecruitmentRequestType::IncreaseHeadcount, [
                'contract_type_id' => $this->recruitmentContractTypeId(),
            ]),
            ['request_type' => RecruitmentRequestType::IncreaseHeadcount, 'position_id' => $child->id],
        ));
        $hr = $this->makeUser($this->root->city, 'HR-SYR');
        $this->assertFalse(RecruitmentRequest::visibleTo($hr)->whereKey($request->id)->exists());
        $request->update(['status' => RecruitmentRequestStatus::UnderReview]);
        $this->assertTrue(RecruitmentRequest::visibleTo($hr)->whereKey($request->id)->exists());
    }

    /** Department managers retain the previous D49 organizational options. */
    public function test_department_manager_can_create_in_a_new_governorate(): void
    {
        $aleppo = $this->makeCity('ALP', '001');

        // ...and a governorate outside that set is still accepted on save (D49).
        app(RecruitmentRequestDraftService::class)->create($this->manager, $this->newPositionPayload([
            'city_id' => $aleppo->id,
            'title_en' => 'First position in a new governorate',
        ]));

        $this->assertSame($aleppo->id, RecruitmentRequest::sole()->scope_city_id);
    }

    /**
     * Only grades junior to the requester's own. The direction is read from the
     * data — a larger grade number is the more junior rank.
     *
     * The org chart has 17 pairs that break this today (three inverted, fourteen
     * level), which is data drift rather than intent: nobody creates a peer or a
     * superior for himself.
     */
    public function test_a_requester_may_only_create_positions_at_a_junior_grade(): void
    {
        $senior = JobLevel::query()->create(['name_en' => 'Senior', 'name_ar' => 'أعلى', 'grade' => 3]);
        $peer = JobLevel::query()->where('grade', 5)->first();
        $junior = JobLevel::query()->create(['name_en' => 'Junior', 'name_ar' => 'أدنى', 'grade' => 6]);

        $allowed = app(RecruitmentScopeResolver::class)->creatableJobLevelIds($this->manager);

        $this->assertContains($junior->id, $allowed);
        $this->assertNotContains($peer->id, $allowed, 'A requester may not create a position at his own grade.');
        $this->assertNotContains($senior->id, $allowed);
    }

    /** The narrowed list is a convenience; the server re-derives the rule. */
    public function test_a_hand_edited_request_cannot_create_a_peer_or_a_superior(): void
    {
        $senior = JobLevel::query()->create(['name_en' => 'Senior', 'name_ar' => 'أعلى', 'grade' => 2]);
        $peer = JobLevel::query()->where('grade', 5)->sole();
        $drafts = app(RecruitmentRequestDraftService::class);

        $base = $this->newPositionPayload(['title_en' => 'New position']);

        foreach ([$senior->id, $peer->id] as $levelId) {
            try {
                $drafts->create($this->manager, array_merge($base, ['job_level_id' => $levelId]));
                $this->fail('A job level that is not junior should have been rejected.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('data.job_level_id', $exception->errors());
            }
        }
    }

    /**
     * A centre is required, except where there is none to name.
     *
     * Remote work sits in no centre, and the programmes that run across many
     * centres rather than out of one would be misrepresented by naming a
     * single one. Both are read from the form on the way in.
     */
    public function test_the_centre_is_required_except_for_remote_work_and_spanning_programmes(): void
    {
        $drafts = app(RecruitmentRequestDraftService::class);
        $payload = $this->newPositionPayload();
        unset($payload['center_id']);

        try {
            $drafts->create($this->manager, $payload);
            $this->fail('A new position normally has to name its centre.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('data.center_id', $exception->errors());
        }

        // Remote work has no centre to name.
        $remote = $drafts->create($this->manager, array_merge($payload, ['volunteer_type' => 'remote']));
        $this->assertNull($remote->draft_payload_json['center_id'] ?? null);

        // ...and neither does a programme that spans several centres.
        $spanning = Program::query()->create([
            'name' => 'Study Zone', 'code' => 'SZ-1',
            'slug' => config('recruitment.request_form.centerless_program_slugs')[0],
            'start_date' => now(), 'is_active' => true,
        ]);

        $across = $drafts->create($this->manager, array_merge($payload, ['program_id' => $spanning->id]));
        $this->assertNull($across->draft_payload_json['center_id'] ?? null);
    }

    /**
     * A complete new-position payload.
     *
     * Every field on the form is required before a draft saves, so the parts a
     * test is not about are answered here. The grade is junior to the
     * requester's own, which D51 requires.
     *
     * @return array<string, mixed>
     */
    private function newPositionPayload(array $overrides = []): array
    {
        $junior = JobLevel::query()->firstOrCreate(
            ['grade' => 7],
            ['name_en' => 'Junior', 'name_ar' => 'أدنى'],
        );

        $values = $this->recruitmentFormDefaults(RecruitmentRequestType::CreatePosition, array_merge([
            'request_type' => RecruitmentRequestType::CreatePosition,
            'direct_manager_id' => $this->root->id,
            'city_id' => $this->root->city_id,
            'program_id' => $this->root->program_id,
            'department_id' => $this->root->department_id,
            'job_level_id' => $junior->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
        ], $overrides));

        // A centre is required unless the work is remote, and it has to sit in
        // the governorate the position is being created in.
        $values['center_id'] ??= \App\Models\Center::query()->firstOrCreate(
            ['city_id' => $values['city_id']],
            ['name_en' => 'Centre '.$values['city_id'], 'name_ar' => 'مركز', 'is_active' => true],
        )->id;

        return $values;
    }

    /** Changing the governorate clears the centre, so tests name a new one. */
    private function centerIn(int $cityId): int
    {
        return (int) \App\Models\Center::query()->firstOrCreate(
            ['city_id' => $cityId],
            ['name_en' => 'Centre '.$cityId, 'name_ar' => 'مركز', 'is_active' => true],
        )->id;
    }

    /**
     * A screen with every required field already answered.
     *
     * The form refuses to save a draft with anything missing, so a test about
     * scope or availability fills the rest once here and overrides only what
     * it is actually exercising.
     */
    private function screen(int $type = RecruitmentRequestType::FillVacancy, array $overrides = [])
    {
        $screen = Livewire::actingAs($this->manager)->test(Create::class);

        if ($type !== RecruitmentRequestType::FillVacancy) {
            $screen->set('data.request_type', $type);
        }

        // A new position also has to say where in the organization it sits.
        if ($type === RecruitmentRequestType::CreatePosition) {
            $overrides += $this->newPositionOrganization();
        }

        return $this->fillRecruitmentForm($screen, $type, $overrides);
    }

    /**
     * The organizational answers a new position needs, none of which these
     * tests are about. The grade is junior to the requester's own (D51).
     *
     * @return array<string, mixed>
     */
    private function newPositionOrganization(): array
    {
        return [
            'direct_manager_id' => $this->root->id,
            'city_id' => $this->root->city_id,
            'program_id' => $this->root->program_id,
            'department_id' => $this->root->department_id,
            'job_level_id' => JobLevel::query()->firstOrCreate(
                ['grade' => 9],
                ['name_en' => 'Junior', 'name_ar' => 'أدنى'],
            )->id,
            'center_id' => \App\Models\Center::query()->firstOrCreate(
                ['city_id' => $this->root->city_id],
                ['name_en' => 'Centre', 'name_ar' => 'مركز', 'is_active' => true],
            )->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
        ];
    }

    private function child(Position $parent, array $attributes = []): Position
    {
        $child = $this->root->replicate();
        $child->fill(array_merge(['direct_manager_id' => $parent->id, 'title_en' => 'Child '.uniqid(), 'exist_emp' => 0], $attributes));
        $child->save();

        return $child;
    }

    private function makeCityIfMissingHoms(): void
    {
        if (! \App\Models\City::where('slug', 'HMS')->exists()) {
            $this->makeCity('HMS', '002');
        }
    }
}
