<?php

namespace Tests\Concerns;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentCampaignLinkMode;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Models\ApplicationForm;
use App\Models\City;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPost;
use App\Models\Position;
use App\Models\Program;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;

/**
 * A campaign in a given governorate, with everything an application needs
 * to point at. Scope flows from the request's scope_city_id, so the city a
 * campaign is built in decides which HR role sees its applications.
 *
 * Requires CreatesHrFixtures.
 */
trait BuildsRecruitmentApplications
{
    protected function makeCampaignIn(City $city, int $status = HiringCampaignStatus::Open, string $title = 'IT Specialist'): HiringCampaign
    {
        $program = Program::query()->forceCreate([
            'name' => 'DISC', 'code' => 'D', 'slug' => 'disc-'.uniqid(), 'is_active' => true,
        ]);
        $department = Department::query()->forceCreate(['name' => 'IT', 'code' => 'IT-'.uniqid()]);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Specialist', 'name_ar' => 'اختصاصي', 'grade' => 6]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $jobLevel->id,
            'department_id' => $department->id,
            'title_en' => $title,
            'title_ar' => $title,
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
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id,
            'created_from_request_id' => $request->id,
            'status' => 'open',
            'needed_count' => 1,
            'filled_count' => 0,
            'open_at' => now(),
        ]);

        return HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id,
            'status' => $status,
            'link_mode' => RecruitmentCampaignLinkMode::PublicOpen,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(14),
            'created_by_user_id' => $requester->id,
        ]);
    }

    protected function makeApplicant(string $name = 'Applicant One', ?City $city = null, string $source = 'public_link'): RecruitmentApplicant
    {
        return RecruitmentApplicant::query()->forceCreate([
            'full_name' => $name,
            'email_normalized' => str($name)->slug().'-'.uniqid().'@example.com',
            'city_id' => $city?->id,
            'source' => $source,
        ]);
    }

    protected function applyTo(HiringCampaign $campaign, RecruitmentApplicant $applicant, array $overrides = []): RecruitmentApplication
    {
        $actor = User::query()->findOrFail($campaign->created_by_user_id);

        $jobPost = $campaign->jobPost ?? JobPost::query()->forceCreate([
            'campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $actor->id,
        ]);
        $jobVersion = $jobPost->versions()->firstOrCreate(['version_no' => 1], [
            'title_en' => 'Job', 'title_ar' => 'وظيفة', 'public_payload_json' => [],
            'status' => 'published', 'published_at' => now(),
        ]);
        $form = $campaign->applicationForm ?? ApplicationForm::query()->forceCreate([
            'campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $actor->id,
        ]);
        $formVersion = $form->versions()->firstOrCreate(['version_no' => 1], [
            'fields_json' => [['key' => 'cv']], 'privacy_notice_version' => 'v1',
            'status' => 'published', 'published_at' => now(),
        ]);
        $link = PublicRecruitmentLink::query()->where('campaign_id', $campaign->id)->first()
            ?? PublicRecruitmentLink::query()->forceCreate([
                'campaign_id' => $campaign->id,
                'job_post_version_id' => $jobVersion->id,
                'form_version_id' => $formVersion->id,
                'token_hash' => hash('sha256', uniqid('token', true)),
                'status' => PublicRecruitmentLink::STATUS_ACTIVE,
                'expires_at' => now()->addWeek(),
                'created_by_user_id' => $actor->id,
            ]);
        $campaign->unsetRelation('jobPost')->unsetRelation('applicationForm');

        return RecruitmentApplication::query()->forceCreate(array_merge([
            'applicant_id' => $applicant->id,
            'campaign_id' => $campaign->id,
            'vacancy_id' => $campaign->vacancy_id,
            'form_version_id' => $formVersion->id,
            'public_link_id' => $link->id,
            'email_normalized' => $applicant->email_normalized,
            'status' => 'submitted',
            'privacy_notice_version' => 'v1',
            'privacy_accepted_at' => now(),
            'submitted_at' => now(),
        ], $overrides));
    }
}
