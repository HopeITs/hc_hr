<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Campaigns\PublishingHub;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class CampaignPublishingHubTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_scoped_hr_can_open_both_publishing_tools_from_the_hub(): void
    {
        [$campaign, $hr] = $this->makeCampaign();

        Livewire::actingAs($hr)
            ->test(PublishingHub::class, ['campaign' => $campaign])
            ->assertSee(__('recruitment.publishing.title'))
            ->assertSee(route('recruitment.campaigns.job-post.edit', $campaign), false)
            ->assertSee(route('recruitment.campaigns.application-form.edit', $campaign), false);
    }

    public function test_hr_outside_the_campaign_city_cannot_open_the_hub(): void
    {
        [$campaign] = $this->makeCampaign();
        $otherCity = $this->makeCity('ALP', '001');
        $otherHr = $this->makeUser($otherCity, 'HR-ALP', ['recruitment-campaigns.view']);

        Livewire::actingAs($otherHr)
            ->test(PublishingHub::class, ['campaign' => $campaign])
            ->assertForbidden();
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
        $hr = $this->makeUser($city, 'HR-HMS', [
            'recruitment-campaigns.view', 'recruitment-job-posts.manage', 'recruitment-forms.manage',
        ]);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id, 'status' => HiringCampaignStatus::Draft,
            'link_mode' => 'public_open', 'created_by_user_id' => $hr->id,
        ]);

        return [$campaign, $hr];
    }
}
