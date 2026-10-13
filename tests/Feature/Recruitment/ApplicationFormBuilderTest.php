<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Exceptions\StaleRecruitmentDraftException;
use App\Livewire\Recruitment\ApplicationForms\Editor;
use App\Models\City;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ApplicationFormBuilderTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_it_builds_and_publishes_an_immutable_safe_form_schema(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentApplicationFormService::class);
        $state = $service->openEditor($campaign, $hr);

        $this->assertSame(array_keys(RecruitmentApplicationFormService::FIELD_CATALOG), array_column($state['fields'], 'key'));
        $this->assertSame(array_keys(RecruitmentApplicationFormService::SECTION_CATALOG), array_values(array_unique(array_column($state['fields'], 'section'))));
        $published = $service->publish(
            $state['application_form'], $state['fields'], 'privacy-2026-01', $hr, $state['version']->id, $state['fingerprint'],
        );
        $schema = $service->publicSchema($campaign->fresh());

        $this->assertSame('privacy-2026-01', $schema['privacy_notice_version']);
        $this->assertSame(
            ['key', 'type', 'required', 'section', 'label', 'help', 'options', 'accept', 'multiple', 'max_files'],
            array_keys($schema['fields'][0]),
        );
        $this->assertCount(7, $schema['sections']);
        $this->assertArrayNotHasKey('locked', $schema['fields'][0]);
        $this->assertDatabaseHas('recruitment_request_events', [
            'event_type' => RecruitmentEventType::FormVersionPublished,
            'actor_user_id' => $hr->id,
        ]);

        $changed = $state['fields'];
        $changed[0]['label']['en'] = 'Legal full name';
        $draftState = $service->openEditor($campaign->fresh(), $hr);
        $draft = $service->saveDraft(
            $state['application_form'], $changed, 'privacy-2026-02', $draftState['version']->id, $draftState['fingerprint'],
        );

        $this->assertSame(2, $draft->version_no);
        $this->assertSame('Applying for a vacancy in the city', $published->fresh()->fields_json[0]['label']['en']);
        $this->assertSame('Legal full name', $draft->fields_json[0]['label']['en']);
    }

    public function test_submission_is_validated_against_the_exact_published_version_and_rejects_unknown_fields(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentApplicationFormService::class);
        $state = $service->openEditor($campaign, $hr);
        $minimalFields = collect($state['fields'])->where('locked', true)->values()->all();
        $customField = $service->makeCustomField(
            'radio', 'personal', 'Availability', 'التفرغ', required: true,
            options: [
                ['value' => 'full_time', 'label' => ['en' => 'Full time', 'ar' => 'دوام كامل']],
                ['value' => 'part_time', 'label' => ['en' => 'Part time', 'ar' => 'دوام جزئي']],
            ],
        );
        $minimalFields[] = $customField;
        $version = $service->publish(
            $state['application_form'], $minimalFields, 'v1', $hr, $state['version']->id, $state['fingerprint'],
        );
        $answers = [
            'full_name' => 'Applicant Name', 'email' => 'applicant@example.com', 'phone' => '0944123456',
            'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            'privacy_consent' => 'yes',
            $customField['key'] => 'full_time',
        ];

        $this->assertSame('Applicant Name', $service->validateSubmission($version, $answers)['full_name']);
        $this->assertSame('full_time', $service->validateSubmission($version, $answers)[$customField['key']]);

        $this->expectException(ValidationException::class);
        $service->validateSubmission($version, [...$answers, 'internal_status' => 'accepted']);
    }

    public function test_it_detects_concurrent_edits_and_enforces_city_scope_in_the_editor(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentApplicationFormService::class);
        $first = $service->openEditor($campaign, $hr);
        $second = $service->openEditor($campaign, $hr);
        $fields = $first['fields'];
        $fields[0]['label']['en'] = 'Changed';
        $service->saveDraft($first['application_form'], $fields, 'v1', $first['version']->id, $first['fingerprint']);

        try {
            $service->saveDraft($second['application_form'], $second['fields'], 'v1', $second['version']->id, $second['fingerprint']);
            $this->fail('A stale editor should not overwrite a newer draft.');
        } catch (StaleRecruitmentDraftException) {
            $this->assertTrue(true);
        }

        Livewire::actingAs($hr)
            ->test(Editor::class, ['campaign' => $campaign])
            ->assertSet('versionNumber', 1)
            ->assertSee('data-recruitment-confirmation-modal="application-form-editor"', false)
            ->assertDontSee('wire:confirm', false);
        $otherCity = $this->makeCity('ALP', '001');
        $otherHr = $this->makeUser($otherCity, 'HR-ALP', ['recruitment-forms.manage']);
        Livewire::actingAs($otherHr)->test(Editor::class, ['campaign' => $campaign])->assertForbidden();
    }

    public function test_the_editor_can_restore_the_reference_template_and_add_a_custom_question(): void
    {
        [$campaign, $hr] = $this->makeCampaign();

        $component = Livewire::actingAs($hr)
            ->test(Editor::class, ['campaign' => $campaign])
            ->call('applyReferenceTemplate')
            ->assertSet('previewSection', 'vacancy')
            ->assertSet('expandedSection', 'vacancy')
            ->set('customType', 'select')
            ->set('customSection', 'personal')
            ->set('customLabelEn', 'Preferred contact method')
            ->set('customLabelAr', 'طريقة التواصل المفضلة')
            ->set('customOptionsEn', "Email\nPhone")
            ->set('customOptionsAr', "البريد الإلكتروني\nالهاتف")
            ->call('addCustomField')
            ->assertSet('previewSection', 'personal')
            ->assertSet('customLabelEn', '')
            ->assertSet('customType', 'text')
            ->assertCount('fields', count(RecruitmentApplicationFormService::FIELD_CATALOG) + 1);

        $component
            ->assertSee('الاسم الكامل')
            ->assertSee('إجابة قصيرة')
            ->assertSee('طريقة التواصل المفضلة');

        $version = $campaign->fresh()->applicationForm->versions()->firstOrFail();
        $unsafe = [...$version->fields_json, [
            'key' => 'internal_status', 'type' => 'text', 'section' => 'personal',
            'label' => ['en' => 'Internal status', 'ar' => 'الحالة الداخلية'],
        ]];

        $this->assertNotContains('internal_status', array_column(
            app(RecruitmentApplicationFormService::class)->normalizeFields($unsafe),
            'key',
        ));
    }

    public function test_adding_an_answer_option_keeps_the_question_in_its_active_section(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $component = Livewire::actingAs($hr)->test(Editor::class, ['campaign' => $campaign]);
        $index = collect($component->get('fields'))->search(fn (array $field): bool => $field['key'] === 'education_level');
        $this->assertIsInt($index);
        $beforeCount = count($component->get("fields.$index.options"));

        $component
            ->call('setPreviewSection', 'education')
            ->call('addOption', $index)
            ->assertSet('previewSection', 'education')
            ->assertSet("fields.$index.section", 'education');

        $this->assertCount($beforeCount + 1, $component->get("fields.$index.options"));
    }

    public function test_clicking_an_editor_section_toggles_it_open_and_closed(): void
    {
        [$campaign, $hr] = $this->makeCampaign();

        Livewire::actingAs($hr)
            ->test(Editor::class, ['campaign' => $campaign])
            ->assertSet('expandedSection', 'vacancy')
            ->call('toggleSection', 'personal')
            ->assertSet('expandedSection', 'personal')
            ->assertSet('previewSection', 'personal')
            ->call('toggleSection', 'personal')
            ->assertSet('expandedSection', '')
            ->call('toggleSection', 'not-a-section')
            ->assertSet('expandedSection', '');
    }

    public function test_hr_can_copy_a_published_form_for_the_same_position_from_another_city(): void
    {
        [$sourceCampaign, $sourceHr] = $this->makeCampaign();
        $service = app(RecruitmentApplicationFormService::class);
        $sourceState = $service->openEditor($sourceCampaign, $sourceHr);
        $sourceFields = $sourceState['fields'];
        $sourceFields[0]['label']['en'] = 'Preferred governorate for this role';
        $published = $service->publish(
            $sourceState['application_form'],
            $sourceFields,
            'privacy-reusable-2026',
            $sourceHr,
            $sourceState['version']->id,
            $sourceState['fingerprint'],
        );

        $sourcePosition = $sourceCampaign->vacancy->position;
        $targetCity = $this->makeCity('ALP', '001');
        $targetPosition = Position::query()->forceCreate([
            'program_id' => $sourcePosition->program_id,
            'city_id' => $targetCity->id,
            'job_level_id' => $sourcePosition->job_level_id,
            'department_id' => $sourcePosition->department_id,
            'title_en' => $sourcePosition->title_en,
            'title_ar' => $sourcePosition->title_ar,
            'max_emp' => 2,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
        [$targetCampaign, $targetHr] = $this->makeCampaignForPosition($targetPosition, $targetCity, 'HR-ALP');

        $component = Livewire::actingAs($targetHr)
            ->test(Editor::class, ['campaign' => $targetCampaign])
            ->assertSee('اختصاصي تقنية معلومات')
            ->assertSee('HMS')
            ->set('selectedTemplateVersionId', (string) $published->id)
            ->call('applyPublishedTemplate')
            ->assertSet('fields.0.label.en', 'Preferred governorate for this role')
            ->assertSet('privacyNoticeVersion', 'privacy-reusable-2026')
            ->assertSet('noticeType', 'info');

        $component->set('fields.0.label.en', 'Aleppo-specific wording');

        $this->assertSame(
            'Applying for a vacancy in the city',
            $targetCampaign->fresh()->applicationForm->versions()->firstOrFail()->fields_json[0]['label']['en'],
        );
        $this->assertSame(
            'Preferred governorate for this role',
            $published->fresh()->fields_json[0]['label']['en'],
        );
    }

    /** @return array{HiringCampaign, User} */
    private function makeCampaign(): array
    {
        $city = $this->makeCity('HMS', '002');
        $program = Program::query()->forceCreate(['name' => 'DISC', 'code' => 'D', 'slug' => 'disc-'.uniqid(), 'is_active' => true]);
        $department = Department::query()->forceCreate(['name' => 'Information Technology', 'code' => 'IT-'.uniqid()]);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Specialist', 'name_ar' => 'اختصاصي', 'grade' => 6]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id, 'city_id' => $city->id, 'job_level_id' => $jobLevel->id,
            'department_id' => $department->id, 'title_en' => 'IT Specialist', 'title_ar' => 'اختصاصي تقنية معلومات',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $requester = $this->makeUser($city, 'employee');
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()), 'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition, 'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Applied, 'scope_city_id' => $city->id,
            'target_position_id' => $position->id, 'submitted_at' => now(), 'applied_at' => now(),
        ]);
        RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id, 'version_no' => 1, 'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id, 'submitted_at' => now(),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id, 'created_from_request_id' => $request->id,
            'status' => 'open', 'needed_count' => 1, 'filled_count' => 0, 'open_at' => now(),
        ]);
        $hr = $this->makeUser($city, 'HR-HMS', ['recruitment-forms.manage']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id, 'status' => HiringCampaignStatus::Draft,
            'link_mode' => 'public_open', 'created_by_user_id' => $hr->id,
        ]);

        return [$campaign, $hr];
    }

    /** @return array{HiringCampaign, User} */
    private function makeCampaignForPosition(Position $position, City $city, string $role): array
    {
        $requester = $this->makeUser($city, 'employee');
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()),
            'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition,
            'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Applied,
            'scope_city_id' => $city->id,
            'target_position_id' => $position->id,
            'submitted_at' => now(),
            'applied_at' => now(),
        ]);
        RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id,
            'submitted_at' => now(),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => 'open',
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now(),
        ]);
        $hr = $this->makeUser($city, $role, ['recruitment-forms.manage']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id,
            'status' => HiringCampaignStatus::Draft,
            'link_mode' => 'public_open',
            'created_by_user_id' => $hr->id,
        ]);

        return [$campaign, $hr];
    }
}
