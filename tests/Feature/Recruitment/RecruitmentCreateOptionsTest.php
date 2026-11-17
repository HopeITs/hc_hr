<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Requests\Create;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentRequestDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentCreateOptionsTest extends TestCase
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

    public function test_searchable_selects_keep_livewire_state_when_the_request_type_changes(): void
    {
        $screen = Livewire::actingAs($this->manager)->test(Create::class);

        $initialData = $screen->get('data');
        $screen->set('data.request_type', RecruitmentRequestType::CreatePosition);
        $newPositionData = $screen->get('data');

        foreach ([
            'position_id', 'direct_manager_id', 'vacancy_id', 'city_id',
            'program_id', 'center_id', 'department_id', 'job_level_id',
            'recruitment_reason', 'priority', 'volunteer_type', 'currency',
            'salary_per', 'contract_type_id',
        ] as $field) {
            $this->assertArrayHasKey($field, $initialData);
            $this->assertArrayHasKey($field, $newPositionData);
        }
    }

    public function test_fill_filters_unavailable_positions_and_auto_selects_the_only_vacancy(): void
    {
        foreach ([1, 3] as $grade) {
            $this->root->jobLevel->update(['grade' => $grade]);
            $this->manager = $this->manager->fresh();
            $target = $this->child($this->child($this->root), ['title_en' => 'Available '.$grade]);
            $empty = $this->child($this->root, ['title_en' => 'No vacancy '.$grade]);
            $vacancy = $this->vacancy($target);
            // A seat with no vacancies row yet is still a seat to fill: nothing
            // opens one until a request is approved and applied.
            $screen = $this->screen()
                ->assertSee($target->title_en)->assertSee($empty->title_en)
                ->set('data.position_id', $target->id)
                ->assertSet('data.vacancy_id', $vacancy->id)
                ->assertDontSee('id="vacancy_id"', false)
                ->call('save')->assertHasNoErrors();
            $saved = RecruitmentRequest::where('status', RecruitmentRequestStatus::Draft)->latest('id')->firstOrFail();
            $this->assertEquals($vacancy->id, $saved->target_vacancy_id);
        }
    }

    /**
     * An empty seat with no vacancies row is fillable.
     *
     * Nothing opens a vacancies row until a request is approved and applied, so
     * requiring one before a request may be raised would make the very first
     * fill-vacancy request impossible on a live position.
     */
    public function test_a_position_with_room_but_no_vacancy_row_can_still_be_filled(): void
    {
        $target = $this->child($this->root, ['title_en' => 'Never advertised']);

        $this->assertSame(0, $target->vacancies()->count());

        $this->screen()
            ->assertSee($target->title_en)
            ->set('data.position_id', $target->id)
            ->assertSet('data.vacancy_id', null)
            ->call('save')->assertHasNoErrors();

        $saved = RecruitmentRequest::where('status', RecruitmentRequestStatus::Draft)->sole();
        $this->assertSame($target->id, (int) $saved->target_position_id);
        $this->assertNull($saved->target_vacancy_id);
    }

    /** A position whose establishment is full has no seat to fill. */
    public function test_a_full_position_is_still_refused(): void
    {
        $full = $this->child($this->root, ['title_en' => 'Fully staffed', 'max_emp' => 1, 'exist_emp' => 1]);

        $this->screen()
            ->assertDontSee($full->title_en)
            ->set('data.position_id', $full->id)
            ->call('save')->assertHasErrors('data.position_id');
    }

    public function test_multiple_vacancies_require_an_explicit_choice_and_keep_it(): void
    {
        $target = $this->child($this->root);
        $one = $this->vacancy($target);
        $two = $this->vacancy($target);
        $screen = $this->screen()
            ->set('data.position_id', $target->id)
            ->assertSee('id="vacancy_id"', false)
            ->call('save')->assertHasErrors('data.vacancy_id')
            ->set('data.vacancy_id', $two->id)->call('save')->assertHasNoErrors();
        $saved = RecruitmentRequest::where('status', RecruitmentRequestStatus::Draft)->sole();
        $this->assertEquals($two->id, $saved->target_vacancy_id);
        $this->assertEquals($two->id, $saved->draft_payload_json['vacancy_id']);
        $other = $this->child($this->root);
        $third = $this->vacancy($other);
        $screen->set('data.position_id', $other->id)->assertSet('data.vacancy_id', $third->id);
        $screen->set('data.position_id', $target->id)->assertSet('data.vacancy_id', null);
    }

    public function test_closed_filled_cancelled_future_and_foreign_vacancies_are_rejected(): void
    {
        foreach ([['status' => 'closed'], ['status' => 'cancelled'], ['filled_count' => 2],
            ['closed_at' => now()], ['open_at' => now()->addDay()]] as $index => $attributes) {
            $target = $this->child($this->root, ['title_en' => 'Unavailable '.$index]);
            $vacancy = $this->vacancy($target, $attributes);
            // The position has room so it is offered; naming a vacancy that is
            // closed, full, cancelled or not yet open is what gets refused.
            $this->screen()->assertSee($target->title_en)
                ->set('data.position_id', $target->id)->set('data.vacancy_id', $vacancy->id)
                ->call('save')->assertHasErrors('data.vacancy_id');
        }
        $target = $this->child($this->root);
        $this->vacancy($target);
        $outside = $this->child($this->root, ['direct_manager_id' => null]);
        $foreign = $this->vacancy($outside);
        $this->screen()
            ->set('data.position_id', $target->id)->set('data.vacancy_id', $foreign->id)
            ->call('save')->assertHasErrors('data.vacancy_id');
    }

    public function test_requested_volunteers_cannot_exceed_the_selected_vacancy_capacity(): void
    {
        $target = $this->child($this->root);
        $vacancy = $this->vacancy($target, ['needed_count' => 2]);

        $this->screen()
            ->set('data.position_id', $target->id)
            ->set('data.vacancy_id', $vacancy->id)
            ->set('data.volunteers_required', 3)
            ->call('save')
            ->assertHasErrors('data.volunteers_required');

        $this->screen()
            ->set('data.position_id', $target->id)
            ->set('data.vacancy_id', $vacancy->id)
            ->set('data.volunteers_required', 2)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_availability_is_rechecked_on_save_and_edit(): void
    {
        $target = $this->child($this->root);
        $vacancy = $this->vacancy($target);
        $screen = $this->screen()->set('data.position_id', $target->id);
        $draft = app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge(
            $this->recruitmentFormDefaults(RecruitmentRequestType::FillVacancy, [
                'contract_type_id' => $this->recruitmentContractTypeId(),
            ]),
            ['request_type' => RecruitmentRequestType::FillVacancy, 'position_id' => $target->id],
        ));
        $vacancy->update(['filled_count' => 2]);
        $screen->call('save')->assertHasErrors('data.vacancy_id');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RecruitmentRequestDraftService::class)->update($this->manager, $draft, $draft->draft_payload_json);
    }

    public function test_proposed_managers_are_only_qualified_descendants_and_self(): void
    {
        $workerLevel = JobLevel::create(['name_en' => 'Worker', 'name_ar' => 'عامل', 'grade' => 9]);
        $worker = $this->child($this->root, ['job_level_id' => $workerLevel->id, 'title_en' => 'Center Keeper']);
        $teamLeader = $this->child($this->root, ['job_level_id' => $workerLevel->id, 'title_en' => 'Actual team leader']);
        $this->child($teamLeader, ['job_level_id' => $workerLevel->id]);
        $manager = $this->child($this->root, ['title_en' => 'Child manager']);
        $higher = $this->child($this->root, ['direct_manager_id' => null, 'title_en' => 'Higher manager']);
        $this->root->update(['direct_manager_id' => $higher->id]);
        foreach ([1, 3] as $grade) {
            $this->root->jobLevel->update(['grade' => $grade]);
            $this->manager = $this->manager->fresh();
            $screen = $this->screen(RecruitmentRequestType::CreatePosition)
                ->assertSee($this->root->title_en)->assertSee($manager->title_en)
                ->assertSee($teamLeader->title_en);
            $screen->set('data.direct_manager_id', $teamLeader->id)->call('save')->assertHasNoErrors();
        }
    }

    public function test_program_and_project_managers_use_program_locations_centers_and_job_levels(): void
    {
        $local = $this->makeCity('HMS', '002');
        $remote = $this->makeCity('ALP', '001');
        // This scenario exercises a governorate-scoped project manager rather
        // than the national fixture used by the other option tests.
        $this->root->update(['city_id' => $local->id]);
        $otherProgram = Program::create(['name' => 'Other program', 'code' => 'OTH', 'slug' => 'other', 'start_date' => now(), 'is_active' => true]);
        $center = \App\Models\Center::create(['name_en' => 'Allowed center', 'name_ar' => 'مركز', 'city_id' => $local->id, 'is_active' => true]);
        $foreign = \App\Models\Center::create(['name_en' => 'Foreign center', 'name_ar' => 'آخر', 'city_id' => $local->id, 'is_active' => true]);
        $elsewhere = \App\Models\Center::create(['name_en' => 'Centre elsewhere', 'name_ar' => 'مركز بعيد', 'city_id' => $remote->id, 'is_active' => true]);
        $this->child($this->root, ['center_id' => $center->id, 'city_id' => $this->root->city_id]);
        $this->child($this->root, ['center_id' => $foreign->id, 'city_id' => $remote->id, 'program_id' => $otherProgram->id]);
        $departmentManager = JobLevel::create(['name_en' => 'Department Manager', 'name_ar' => 'مدير قسم', 'grade' => 5]);
        $junior = JobLevel::create(['name_en' => 'Officer', 'name_ar' => 'موظف', 'grade' => 9]);
        foreach ([1, 3] as $grade) {
            $this->root->jobLevel->update(['grade' => $grade]);
            $this->manager = $this->manager->fresh();
            $screen = $this->screen(RecruitmentRequestType::CreatePosition)
                ->assertSet('data.program_id', $this->root->program_id)
                ->assertDontSee('Department Manager')->assertDontSee('Other program')
                ->set('data.direct_manager_id', $this->root->id)->set('data.city_id', $local->id)
                // Both centres sit in the chosen governorate, and the programme
                // no longer narrows the list — only the governorate does.
                ->assertSee('Allowed center')->assertSee('Foreign center')
                ->assertViewHas('cities', fn ($cities) => $grade === 3
                    ? $cities->contains('id', $local->id) && ! $cities->contains('id', $remote->id)
                    : $cities->contains('id', $local->id) && $cities->contains('id', $remote->id));
            $base = $this->recruitmentFormDefaults(RecruitmentRequestType::CreatePosition, [
                'request_type' => RecruitmentRequestType::CreatePosition,
                'direct_manager_id' => $this->root->id, 'program_id' => $this->root->program_id,
                'city_id' => $local->id, 'center_id' => $center->id, 'job_level_id' => $junior->id,
                'department_id' => $this->root->department_id,
                'contract_type_id' => $this->recruitmentContractTypeId(),
            ]);
            $invalid = [
                'program_id' => $otherProgram->id,
                'center_id' => $elsewhere->id,
                'job_level_id' => $departmentManager->id,
            ];
            if ($grade === 3) {
                $invalid['city_id'] = $remote->id;
            }
            foreach ($invalid as $field => $value) {
                try {
                    app(RecruitmentRequestDraftService::class)->create($this->manager, array_replace($base, [$field => $value]));
                    $this->fail('Forged '.$field.' should be rejected.');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $this->assertArrayHasKey('data.'.$field, $e->errors());
                }
            }
            $screen->set('data.center_id', $center->id)->set('data.job_level_id', $junior->id)
                ->call('save')->assertHasNoErrors();
            // The governorate still clears the centre, because a centre belongs
            // to one. The programme does not: it no longer narrows the list.
            $screen->set('data.city_id', $this->root->city_id)->assertSet('data.center_id', null);
            $screen->set('data.center_id', $center->id)->set('data.program_id', $otherProgram->id)
                ->assertSet('data.center_id', $center->id);
        }
    }

    public function test_full_or_inactive_positions_cannot_be_used_even_with_open_vacancies(): void
    {
        foreach ([['exist_emp' => 5], ['is_active' => false]] as $attributes) {
            $target = $this->child($this->root, $attributes);
            $this->vacancy($target);
            $this->screen()->assertDontSee($target->title_en)
                ->set('data.position_id', $target->id)->call('save')->assertHasErrors('data.position_id');
        }
    }

    public function test_project_manager_cannot_target_a_position_in_another_governorate(): void
    {
        $local = $this->makeCity('HMS', '002');
        $remote = $this->makeCity('ALP', '001');
        $projectLevel = JobLevel::query()->firstOrCreate(
            ['grade' => 3],
            ['name_en' => 'Project Manager', 'name_ar' => 'مدير مشروع'],
        );
        $this->root->update(['city_id' => $local->id, 'job_level_id' => $projectLevel->id]);
        $remoteTarget = $this->child($this->root, [
            'city_id' => $remote->id,
            'program_id' => $this->root->program_id,
            'title_en' => 'Remote project position',
        ]);

        $this->screen()
            ->assertDontSee($remoteTarget->title_en);

        try {
            app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge(
                $this->recruitmentFormDefaults(RecruitmentRequestType::FillVacancy),
                ['request_type' => RecruitmentRequestType::FillVacancy, 'position_id' => $remoteTarget->id],
            ));
            $this->fail('A project manager should not create a request outside their governorate.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('data.position_id', $exception->errors());
        }
    }

    public function test_project_manager_can_target_a_vacant_position_in_another_program_in_their_governorate(): void
    {
        $local = $this->makeCity('HMS', '002');
        $otherProgram = Program::create([
            'name' => 'Other programme', 'code' => 'OTH', 'slug' => 'other-programme',
            'start_date' => now(), 'is_active' => true,
        ]);
        $projectLevel = JobLevel::query()->firstOrCreate(
            ['grade' => 3],
            ['name_en' => 'Project Manager', 'name_ar' => 'مدير مشروع'],
        );
        $this->root->update(['city_id' => $local->id, 'job_level_id' => $projectLevel->id]);
        $target = $this->child($this->root, [
            'city_id' => $local->id,
            'program_id' => $otherProgram->id,
            'title_en' => 'Vacant position in another programme',
        ]);

        $this->screen()
            ->assertSee($target->title_en)
            ->set('data.position_id', $target->id)
            ->call('save')->assertHasNoErrors();
    }

    public function test_program_manager_can_target_a_position_in_their_program_outside_their_reporting_tree(): void
    {
        $remote = $this->makeCity('ALP', '001');
        $programLevel = JobLevel::query()->firstOrCreate(
            ['grade' => 1],
            ['name_en' => 'Program Manager', 'name_ar' => 'مدير برنامج'],
        );
        $this->root->update(['job_level_id' => $programLevel->id]);
        $target = $this->root->replicate();
        $target->fill([
            'direct_manager_id' => null,
            'city_id' => $remote->id,
            'title_en' => 'Programme-wide vacant position',
            'exist_emp' => 0,
        ]);
        $target->save();

        $this->screen()
            ->assertSee($target->title_en)
            ->set('data.position_id', $target->id)
            ->call('save')->assertHasNoErrors();
    }

    public function test_close_position_only_lists_empty_positions_without_active_contracts(): void
    {
        $occupied = $this->child($this->root, ['title_en' => 'Occupied position', 'max_emp' => 1, 'exist_emp' => 1]);
        $empty = $this->child($this->root, ['title_en' => 'Empty position', 'max_emp' => 1, 'exist_emp' => 0]);
        $manySeats = $this->child($this->root, ['title_en' => 'Many seat position', 'max_emp' => 3, 'exist_emp' => 0]);
        $occupant = $this->makeUser($this->root->city, 'employee');

        Contract::query()->create([
            'employee_id' => $occupant->employee_id,
            'position_id' => $occupied->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        $this->screen(RecruitmentRequestType::ClosePosition)
            ->assertDontSee($occupied->title_en)
            // A post with several seats gives them up one at a time.
            ->assertDontSee($manySeats->title_en)
            ->assertSee($empty->title_en);
    }

    public function test_close_vacancy_only_lists_positions_with_an_empty_approved_seat(): void
    {
        $vacant = $this->child($this->root, [
            'title_en' => 'Vacant approved seat',
            'max_emp' => 2,
            'exist_emp' => 1,
        ]);
        $full = $this->child($this->root, [
            'title_en' => 'Fully occupied position',
            'max_emp' => 1,
            'exist_emp' => 1,
        ]);
        $single = $this->child($this->root, [
            'title_en' => 'Single empty seat',
            'max_emp' => 1,
            'exist_emp' => 0,
        ]);

        $this->screen(RecruitmentRequestType::CloseVacancy)
            ->assertSee($vacant->title_en)
            ->assertDontSee($full->title_en)
            // The only seat of a post is closed by closing the post.
            ->assertDontSee($single->title_en)
            ->assertDontSee('id="vacancy_id"', false);
    }

    public function test_the_form_offers_one_close_vacancy_choice(): void
    {
        $screen = Livewire::actingAs($this->manager)->test(Create::class);

        $screen
            ->assertSee('Close vacancy')
            ->assertDontSee('Reduce approved headcount')
            ->assertDontSee('Close recruitment opening');
    }

    public function test_only_the_two_recruitment_contract_types_are_offered(): void
    {
        ContractType::query()->create([
            'name_en' => 'Volunteer Contract',
            'name_ar' => 'عقد تطوّع',
        ]);
        ContractType::query()->create([
            'name_en' => 'Specific Voluntary Service Contract',
            'name_ar' => 'عقد تقديم خدمة تطوعية محددة',
        ]);

        $this->screen()
            ->assertViewHas('contractTypes', fn ($types): bool => $types
                ->pluck('name_en')
                ->sort()
                ->values()
                ->all() === [
                    'Specific Voluntary Service Contract',
                    'Trial Volunteer Contract',
                ]);
    }

    public function test_general_management_retains_its_existing_organization_options(): void
    {
        $this->root->jobLevel->update(['grade' => 1]);
        $this->manager->syncRoles('general-manager');
        $this->manager->roles()->updateExistingPivot($this->manager->roles()->first()->id, ['is_active' => true]);
        $this->manager = $this->manager->fresh();
        $city = $this->makeCity('ALP', '001');
        $program = Program::create(['name' => 'Unrelated program', 'code' => 'UNK', 'slug' => 'unknown', 'start_date' => now(), 'is_active' => true]);
        $this->screen(RecruitmentRequestType::CreatePosition)
            ->assertSee('Unrelated program')->set('data.direct_manager_id', $this->root->id)
            ->set('data.city_id', $city->id)->set('data.program_id', $program->id)
            ->set('data.center_id', $this->centerIn($city->id))
            ->call('save')->assertHasNoErrors();
    }

    /**
     * A governorate administered as one reaches all of it.
     *
     * Homs Rural is a row in `cities` but not a governorate of its own, so a
     * project manager seated in Homs manages posts in both. Narrowing him to
     * the city of his own chair cost him every report in the countryside — the
     * half of his team he was asking about.
     *
     * The narrowing itself stays: Aleppo is a governorate of its own and is
     * still refused in the same breath, so this widens one boundary rather
     * than removing it.
     */
    public function test_project_manager_reaches_a_city_administered_as_part_of_his_governorate(): void
    {
        $homs = $this->makeCity('HMS', '002');
        $countryside = $this->makeCity('RHO', '015');
        $aleppo = $this->makeCity('ALP', '001');

        $projectLevel = JobLevel::query()->firstOrCreate(
            ['grade' => 3],
            ['name_en' => 'Project Manager', 'name_ar' => 'مدير مشروع'],
        );
        $this->root->update(['city_id' => $homs->id, 'job_level_id' => $projectLevel->id]);

        $inCountryside = $this->child($this->root, [
            'city_id' => $countryside->id,
            'program_id' => $this->root->program_id,
            'title_en' => 'Countryside position',
        ]);
        $inAleppo = $this->child($this->root, [
            'city_id' => $aleppo->id,
            'program_id' => $this->root->program_id,
            'title_en' => 'Aleppo position',
        ]);

        $this->screen()
            ->assertSee($inCountryside->title_en)
            ->assertDontSee($inAleppo->title_en)
            // Both halves of his own governorate are offered as places to
            // establish a post; another governorate is not.
            ->assertViewHas('cities', fn ($cities): bool => $cities->contains('id', $homs->id)
                && $cities->contains('id', $countryside->id)
                && ! $cities->contains('id', $aleppo->id));

        // And the validation agrees with the list it was built from.
        $draft = app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge(
            $this->recruitmentFormDefaults(RecruitmentRequestType::FillVacancy),
            ['request_type' => RecruitmentRequestType::FillVacancy, 'position_id' => $inCountryside->id],
        ));

        $this->assertSame($inCountryside->id, (int) $draft->target_position_id);

        try {
            app(RecruitmentRequestDraftService::class)->create($this->manager, array_merge(
                $this->recruitmentFormDefaults(RecruitmentRequestType::FillVacancy),
                ['request_type' => RecruitmentRequestType::FillVacancy, 'position_id' => $inAleppo->id],
            ));
            $this->fail('Another governorate should still be refused.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('data.position_id', $exception->errors());
        }
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

    private function vacancy(Position $position, array $attributes = []): \App\Models\Vacancy
    {
        $origin = RecruitmentRequest::create([
            'request_number' => 'ORIGIN-'.uniqid(), 'requester_user_id' => $this->manager->id,
            'request_type' => RecruitmentRequestType::IncreaseHeadcount, 'status' => RecruitmentRequestStatus::Applied,
            'target_position_id' => $position->id, 'scope_city_id' => $this->root->city_id,
        ]);

        return $position->vacancies()->create(array_merge([
            'created_from_request_id' => $origin->id, 'status' => 'open', 'needed_count' => 2,
            'filled_count' => 0, 'open_at' => now()->subDay(),
        ], $attributes));
    }

    private function child(Position $parent, array $attributes = []): Position
    {
        $child = $this->root->replicate();
        $child->fill(array_merge(['direct_manager_id' => $parent->id, 'title_en' => 'Child '.uniqid(), 'exist_emp' => 0], $attributes));
        $child->save();

        return $child;
    }
}
