<?php

namespace Tests\Concerns;

use App\Models\ApplicationForm;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\PublicRecruitmentLink;
use App\Models\User;

trait BuildsPublishedCampaignAssets
{
    protected function prepareCampaignForOpening(HiringCampaign $campaign, User $actor): HiringCampaign
    {
        $jobPost = JobPost::query()->create([
            'campaign_id' => $campaign->getKey(),
            'status' => 'published',
            'created_by_user_id' => $actor->getKey(),
        ]);
        $jobVersion = $jobPost->versions()->create([
            'version_no' => 1,
            'title_en' => 'Recruitment test post',
            'title_ar' => 'إعلان توظيف تجريبي',
            'body_en' => 'Recruitment test content.',
            'body_ar' => 'محتوى توظيف تجريبي.',
            'public_payload_json' => ['job_title' => 'Recruitment test post'],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $jobPost->forceFill(['current_version_id' => $jobVersion->getKey()])->save();

        $form = ApplicationForm::query()->create([
            'campaign_id' => $campaign->getKey(),
            'status' => 'published',
            'created_by_user_id' => $actor->getKey(),
        ]);
        $formVersion = $form->versions()->create([
            'version_no' => 1,
            'fields_json' => [['key' => 'full_name', 'type' => 'text', 'required' => true]],
            'privacy_notice_version' => 'v1',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $form->forceFill(['current_version_id' => $formVersion->getKey()])->save();

        PublicRecruitmentLink::query()->create([
            'campaign_id' => $campaign->getKey(),
            'job_post_version_id' => $jobVersion->getKey(),
            'form_version_id' => $formVersion->getKey(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'status' => PublicRecruitmentLink::STATUS_ACTIVE,
            'expires_at' => $campaign->ends_at?->isBefore(now()->addDays(3))
                ? $campaign->ends_at
                : now()->addDays(3),
            'created_by_user_id' => $actor->getKey(),
        ]);

        return $campaign->fresh();
    }
}
