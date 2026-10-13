<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Exceptions\StaleRecruitmentDraftException;
use App\Livewire\Recruitment\JobPosts\Editor;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPostVersion;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationFormService;
use App\Services\RecruitmentJobPostService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class JobPostGeneratorTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_it_prefills_the_generator_from_the_approved_request_without_exposing_internal_data(): void
    {
        [$campaign, $hr, $request, $detail] = $this->makeCampaign();

        $state = app(RecruitmentJobPostService::class)->openEditor($campaign, $hr);
        $payload = $state['version']->public_payload_json;

        $this->assertSame([
            'city' => 'HOMS',
            'program' => 'DISC',
            'job_title' => 'IT Specialist',
        ], array_intersect_key($state['values'], array_flip(['city', 'program', 'job_title'])));
        $this->assertSame("We're Hiring — IT Specialist", $state['values']['title_en']);
        $this->assertSame('مطلوب متطوع — اختصاصي تقنية معلومات', $state['values']['title_ar']);
        $this->assertStringNotContainsString('Lead IT operations', $state['values']['body_en']);
        $this->assertStringNotContainsString('Degree in information technology', $state['values']['body_en']);
        $context = app(RecruitmentApplicationFormService::class)->formContext($campaign);
        $this->assertSame('Lead IT operations, support users, and maintain systems and infrastructure.', $context['responsibilities']['en']);
        $this->assertSame('المهام: تشغيل أنظمة تقنية المعلومات ودعم المستخدمين.', $context['responsibilities']['ar']);
        $this->assertSame('Degree in information technology or a related field.', $context['qualifications']['en']);
        $this->assertSame('شهادة في تكنولوجيا المعلومات أو مجال ذي صلة.', $context['qualifications']['ar']);
        $this->assertSame(RecruitmentJobPostService::TEMPLATE, $payload['template']);
        $this->assertSame(2160, $payload['outputs']['story']['width']);
        $this->assertSame(3840, $payload['outputs']['story']['height']);
        $this->assertSame(2160, $payload['outputs']['post']['height']);
        $this->assertArrayNotHasKey('request_id', $payload);
        $this->assertArrayNotHasKey('salary_amount', $payload);
        $this->assertArrayNotHasKey('requester_user_id', $payload);

        app(RecruitmentJobPostService::class)->saveDraft($state['job_post'], [
            'city' => 'Aleppo',
            'program' => 'Study Zone',
            'job_title' => 'Project Assistant',
        ]);

        $this->assertSame('HOMS', $request->fresh()->scopeCity->name_en);
        $this->assertSame('IT Specialist', $detail->fresh()->role_title_en);
        $this->assertSame('ALEPPO', $state['job_post']->versions()->first()->public_payload_json['city']);
    }

    public function test_published_versions_are_immutable_and_republishing_without_changes_is_idempotent(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentJobPostService::class);
        $state = $service->openEditor($campaign, $hr);

        $first = $service->publish($state['job_post'], [
            'city' => 'HOMS',
            'program' => 'DISC',
            'job_title' => 'IT Specialist',
        ], $hr);
        $same = $service->publish($state['job_post'], [
            'city' => 'HOMS',
            'program' => 'DISC',
            'job_title' => 'IT Specialist',
        ], $hr);

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('job_post_versions', 1);
        $this->assertDatabaseCount('recruitment_request_events', 1);
        $this->assertDatabaseHas('recruitment_request_events', [
            'event_type' => RecruitmentEventType::JobPostPublished,
            'actor_user_id' => $hr->id,
        ]);

        $draft = $service->saveDraft($state['job_post'], [
            'city' => 'ALEPPO',
            'program' => 'STUDY ZONE',
            'job_title' => 'Project Assistant',
        ]);

        $this->assertSame(2, $draft->version_no);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('IT Specialist', $first->fresh()->public_payload_json['job_title']);
        $this->assertSame($first->id, $state['job_post']->fresh()->current_version_id);

        $second = $service->publish($state['job_post'], [
            'city' => 'ALEPPO',
            'program' => 'STUDY ZONE',
            'job_title' => 'Project Assistant',
        ], $hr);

        $this->assertSame(2, $second->version_no);
        $this->assertSame('published', $second->status);
        $this->assertSame($second->id, $state['job_post']->fresh()->current_version_id);
        $this->assertSame(2, JobPostVersion::query()->where('status', 'published')->count());
    }

    public function test_the_livewire_editor_is_available_only_to_hr_inside_the_request_scope(): void
    {
        [$campaign, $hr] = $this->makeCampaign();

        Livewire::actingAs($hr)
            ->test(Editor::class, ['campaign' => $campaign])
            ->assertSet('city', 'HOMS')
            ->assertSet('program', 'DISC')
            ->assertSet('jobTitle', 'IT Specialist')
            ->assertSee('2160 × 3840')
            ->assertSee('data-recruitment-confirmation-modal="job-post-editor"', false)
            ->assertDontSee('wire:confirm', false)
            ->assertDontSee('@js(', false);

        $otherCity = $this->makeCity('ALP', '001');
        $otherHr = $this->makeUser($otherCity, 'HR-ALP', ['recruitment-job-posts.manage']);

        Livewire::actingAs($otherHr)
            ->test(Editor::class, ['campaign' => $campaign])
            ->assertForbidden();
    }

    public function test_a_non_approved_request_cannot_generate_a_job_post(): void
    {
        [$campaign, $hr, $request] = $this->makeCampaign();
        $request->forceFill(['status' => RecruitmentRequestStatus::UnderReview])->save();

        $this->expectException(DomainException::class);

        app(RecruitmentJobPostService::class)->openEditor($campaign, $hr);
    }

    public function test_it_rejects_a_stale_hr_editor_and_exposes_only_a_safe_public_snapshot(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentJobPostService::class);
        $firstEditor = $service->openEditor($campaign, $hr);
        $secondEditor = $service->openEditor($campaign, $hr);
        $values = $firstEditor['values'];
        $values['job_title'] = 'Updated title';

        $service->saveDraft($firstEditor['job_post'], $values, $firstEditor['version']->id, $firstEditor['fingerprint']);

        $this->expectException(StaleRecruitmentDraftException::class);
        $service->saveDraft($secondEditor['job_post'], $secondEditor['values'], $secondEditor['version']->id, $secondEditor['fingerprint']);
    }

    public function test_unpublishing_preserves_the_published_snapshot_and_creates_an_editable_draft(): void
    {
        [$campaign, $hr] = $this->makeCampaign();
        $service = app(RecruitmentJobPostService::class);
        $state = $service->openEditor($campaign, $hr);
        $published = $service->publish(
            $state['job_post'],
            $state['values'],
            $hr,
            $state['version']->id,
            $state['fingerprint'],
        );

        $snapshot = $service->publicSnapshot($campaign->fresh());
        $this->assertSame(['en', 'ar'], array_keys($snapshot['title']));
        $this->assertArrayNotHasKey('request_id', $snapshot);

        $draft = $service->unpublish($state['job_post'], $hr, $published->id);

        $this->assertSame('published', $published->fresh()->status);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('unpublished', $state['job_post']->fresh()->status);
        $this->assertNull($state['job_post']->fresh()->current_version_id);
    }

    /** @return array{HiringCampaign, User, RecruitmentRequest, RecruitmentRequestPositionDetail} */
    private function makeCampaign(): array
    {
        $city = $this->makeCity('HMS', '002');
        $city->forceFill(['name_en' => 'HOMS', 'name_ar' => 'حمص'])->save();
        $program = Program::query()->forceCreate([
            'name' => 'DISC',
            'code' => 'D',
            'slug' => 'disc-'.uniqid(),
            'is_active' => true,
        ]);
        $department = Department::query()->forceCreate([
            'name' => 'Information Technology',
            'code' => 'IT-'.uniqid(),
        ]);
        $jobLevel = JobLevel::query()->forceCreate([
            'name_en' => 'Specialist',
            'name_ar' => 'اختصاصي',
            'grade' => 6,
        ]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $jobLevel->id,
            'department_id' => $department->id,
            'title_en' => 'IT Specialist',
            'title_ar' => 'اختصاصي تقنية معلومات',
            'max_emp' => 2,
            'exist_emp' => 0,
            'is_active' => true,
        ]);
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
        $requestVersion = RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id,
            'submitted_at' => now(),
        ]);
        $detail = RecruitmentRequestPositionDetail::query()->forceCreate([
            'request_version_id' => $requestVersion->id,
            'position_id' => $position->id,
            'program_id' => $program->id,
            'role_title_en' => 'IT Specialist',
            'role_title_ar' => 'اختصاصي تقنية معلومات',
            'requested_headcount' => 1,
            'main_duties' => json_encode([
                'en' => 'Lead IT operations, support users, and maintain systems and infrastructure.',
                'ar' => 'المهام: تشغيل أنظمة تقنية المعلومات ودعم المستخدمين.',
            ], JSON_UNESCAPED_UNICODE),
            'required_qualifications' => json_encode([
                'en' => 'Degree in information technology or a related field.',
                'ar' => 'شهادة في تكنولوجيا المعلومات أو مجال ذي صلة.',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => 'open',
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now(),
        ]);
        $hr = $this->makeUser($city, 'HR-HMS', ['recruitment-job-posts.manage']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id,
            'status' => HiringCampaignStatus::Draft,
            'link_mode' => 'public_open',
            'created_by_user_id' => $hr->id,
        ]);

        return [$campaign, $hr, $request, $detail];
    }
}
