<?php

namespace Database\Seeders;

use App\Models\HiringCampaign;
use App\Models\JobPostVersion;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\HiringCampaignService;
use App\Services\Policies\PolicyFolder;
use App\Services\PolicyAcknowledgements;
use App\Services\PublicRecruitmentLinkService;
use App\Services\RecruitmentApplicationFormService;
use App\Services\RecruitmentJobPostService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Never in production: what the live scenario runs on hr_db need before
 * hiring (1), onboarding (4) and policies (13) can be walked.
 *
 *   php artisan db:seed --class=ScenarioDemoSeeder
 *
 *  - the recruitment approval workflows, through RecruitmentWorkflowSeeder
 *    (it adds only the request types that have no active one);
 *  - one campaign taking applications now, on a free vacancy, made by HR Syria
 *    through the same services as the Campaigns page (post, form, link, launch);
 *  - two policies in the Policies folder, both required for onboarding: one
 *    to agree to (policy), one only to review (review). They are text files,
 *    so they are read at once with no Gemini key.
 *
 * Each part is skipped when already there, so it can run again.
 * Docs: docs/scenario-demo-data.md.
 */
class ScenarioDemoSeeder extends Seeder
{
    public const CAMPAIGN_TITLE = 'Demo campaign (scenario runs)';

    /** @var array<string, string> file in database/data/demo-policies => kind */
    private const POLICIES = [
        'code-of-conduct.txt' => PolicyDocument::KIND_POLICY,
        'media-guideline.txt' => PolicyDocument::KIND_REVIEW,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Scenario demo data is never seeded in production.');
        }

        // The policies are read in the job the upload dispatches; run it now.
        config(['queue.default' => 'sync']);
        Mail::fake();

        $this->call(RecruitmentWorkflowSeeder::class);

        $this->asHrSyria(fn (User $hr) => $this->campaign($hr));
        $this->policies();
    }

    /**
     * Acts as the first holder of HR-SYR, which is often not the role they
     * sign in with: it is made active for the call and the one they had is
     * given back.
     */
    private function asHrSyria(callable $work): void
    {
        $hr = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'HR-SYR'))->orderBy('id')->first()
            ?? throw new RuntimeException('Nobody holds HR-SYR.');

        $previous = $hr->activeRole()?->id;
        $hr->setActiveRole($hr->roles->firstWhere('name', 'HR-SYR')->id);

        try {
            $work($hr->fresh());
        } finally {
            if ($previous !== null) {
                $hr->fresh()->setActiveRole($previous);
            }
        }
    }

    private function campaign(User $hr): void
    {
        $existing = JobPostVersion::query()->where('title_en', self::CAMPAIGN_TITLE)->first();

        if ($existing !== null) {
            $this->command?->info('Demo campaign already there: #'.$existing->jobPost->campaign_id.'.');

            return;
        }

        $vacancy = Vacancy::query()
            ->available()
            ->whereDoesntHave('campaigns', fn ($q) => $q->ongoing())
            ->latest('id')
            ->first();

        if ($vacancy === null) {
            $this->command?->warn('No free vacancy (every open one has a campaign in progress): demo campaign skipped.');

            return;
        }

        $campaigns = app(HiringCampaignService::class);
        $posts = app(RecruitmentJobPostService::class);
        $forms = app(RecruitmentApplicationFormService::class);

        $campaign = $campaigns->create($hr, $vacancy, [
            'link_mode' => HiringCampaign::PUBLIC_OPEN,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ]);

        $post = $posts->openEditor($campaign, $hr);
        $posts->publish($post['job_post'], ['title_en' => self::CAMPAIGN_TITLE] + $post['values'], $hr);

        $form = $forms->openEditor($campaign, $hr);
        $forms->publish($form['application_form'], $form['fields'], $form['privacy_notice_version'], $hr, $form['version']->id, $form['fingerprint']);

        $link = app(PublicRecruitmentLinkService::class)->generate($campaign->fresh(), $hr);
        $campaigns->launch($hr, $campaign->fresh());

        $this->command?->info("Demo campaign #{$campaign->id} on vacancy #{$vacancy->id}, open until ".$campaign->ends_at->toDateString().'.');
        $this->command?->info('Public link: '.$link['url']);
    }

    private function policies(): void
    {
        $library = app(PolicyFolder::class)->library();
        $acknowledgements = app(PolicyAcknowledgements::class);

        foreach (self::POLICIES as $file => $kind) {
            $title = pathinfo($file, PATHINFO_FILENAME);
            $document = PolicyDocument::query()->where('title', $title)->first();

            if ($document === null) {
                $library->addMedia(database_path('data/demo-policies/'.$file))
                    ->preservingOriginal()
                    ->usingName($title)
                    ->withCustomProperties(['validated_upload' => true])
                    ->toMediaCollection('archived_files', 'local');

                $document = PolicyDocument::query()->where('title', $title)->firstOrFail()->refresh();
            }

            $acknowledgements->setRequired($document, true);
            $acknowledgements->setKind($document, $kind);

            $this->command?->info("Policy #{$document->id} \"{$title}\": {$kind}, required for onboarding, {$document->read_status}.");
        }
    }
}
