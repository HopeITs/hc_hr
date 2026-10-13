<?php

namespace App\Console\Commands;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentCampaignLinkMode;
use App\Models\ApplicationForm;
use App\Models\ApplicationFormVersion;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\JobPostVersion;
use App\Models\Position;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationFormService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dev-only: builds one open campaign end to end and prints its link on the
 * real application form (/careers/{token}), for clicking through Phase 2
 * intake in a browser without preparing a campaign through the management
 * screens first.
 *
 * Not for production use — it fabricates a RecruitmentRequest/Vacancy chain
 * rather than going through the real approval workflow.
 */
class SeedRecruitmentDemoApplicationLink extends Command
{
    protected $signature = 'recruitment:demo-application-link
        {--invite-only : Create an invitation-only campaign instead of an open one}
        {--invite-email= : Email to pre-invite when --invite-only is used (defaults to demo-applicant@example.test)}';

    protected $description = 'Seed a demo open campaign + published job post/form + public link, and print its /careers/{token} application URL';

    public function handle(): int
    {
        $user = User::query()->first();
        $position = Position::query()->where('is_active', true)->first();

        if ($user === null || $position === null) {
            $this->error('Need at least one user and one active position to seed a demo campaign.');

            return self::FAILURE;
        }

        // 64 hex characters: the only token shape Team 1's /careers/{token} accepts.
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($user, $position, $token) {
            $request = RecruitmentRequest::query()->create([
                'request_number' => 'RR-DEMO-'.Str::ulid(),
                'requester_user_id' => $user->id,
                'request_type' => 1,
                'mode' => 1,
                'status' => 5,
                'target_position_id' => $position->id,
            ]);

            $vacancy = Vacancy::query()->create([
                'position_id' => $position->id,
                'created_from_request_id' => $request->id,
                'status' => 'open',
                'needed_count' => 1,
                'open_at' => now(),
            ]);

            $campaign = HiringCampaign::query()->create([
                'vacancy_id' => $vacancy->id,
                'status' => HiringCampaignStatus::Open,
                'link_mode' => $this->option('invite-only') ? RecruitmentCampaignLinkMode::InvitationOnly : RecruitmentCampaignLinkMode::PublicOpen,
                'created_by_user_id' => $user->id,
            ]);

            $jobPost = JobPost::query()->create([
                'campaign_id' => $campaign->id,
                'status' => 'published',
                'created_by_user_id' => $user->id,
            ]);

            $jobPostVersion = JobPostVersion::query()->create([
                'job_post_id' => $jobPost->id,
                'version_no' => 1,
                'title_en' => 'Demo Field Officer',
                'title_ar' => 'موظف ميداني (تجريبي)',
                'body_en' => 'This is a seeded demo job post for testing the Phase 2 public application flow.',
                'body_ar' => 'هذا إعلان وظيفي تجريبي لاختبار مسار التقديم العام في المرحلة الثانية.',
                'public_payload_json' => [],
                'status' => 'published',
                'published_at' => now(),
            ]);
            $jobPost->update(['current_version_id' => $jobPostVersion->id]);

            $form = ApplicationForm::query()->create([
                'campaign_id' => $campaign->id,
                'status' => 'published',
                'created_by_user_id' => $user->id,
            ]);

            $formVersion = ApplicationFormVersion::query()->create([
                'application_form_id' => $form->id,
                'version_no' => 1,
                // Team 1's standard field set, in their builder's shape. The
                // CV is locked and required on every form (normalizeFields()
                // re-adds it).
                'fields_json' => app(RecruitmentApplicationFormService::class)->defaultFields(),
                'status' => 'published',
                'published_at' => now(),
            ]);
            $form->update(['current_version_id' => $formVersion->id]);

            $link = PublicRecruitmentLink::query()->create([
                'campaign_id' => $campaign->id,
                'job_post_version_id' => $jobPostVersion->id,
                'form_version_id' => $formVersion->id,
                'token_hash' => hash('sha256', $token),
                'status' => PublicRecruitmentLink::STATUS_ACTIVE,
                'expires_at' => now()->addDays(30),
                'created_by_user_id' => $user->id,
            ]);

            if ($this->option('invite-only')) {
                $inviteEmail = $this->option('invite-email') ?: 'demo-applicant@example.test';

                RecruitmentCampaignInvite::query()->create([
                    'campaign_id' => $campaign->id,
                    'full_name' => 'Demo Applicant',
                    'email_normalized' => strtolower(trim($inviteEmail)),
                    'status' => RecruitmentCampaignInvite::STATUS_PENDING,
                    'sent_at' => now(),
                    'created_by_user_id' => $user->id,
                ]);

                $this->line("Invitation-only campaign — pre-invited email: {$inviteEmail}");
            }

            $this->info('Demo campaign created (campaign id '.$campaign->id.', link id '.$link->id.').');
        });

        $this->newLine();
        $base = rtrim(config('app.url'), '/');
        $this->info('Application form:');
        $this->line("{$base}/careers/{$token}");

        return self::SUCCESS;
    }
}
