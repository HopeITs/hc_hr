<?php

namespace App\Livewire\Recruitment\JobPosts;

use App\Exceptions\StaleRecruitmentDraftException;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\JobPostVersion;
use App\Models\Program;
use App\Services\RecruitmentJobPostService;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Editor extends Component
{
    use WithFeedback;

    public HiringCampaign $campaign;

    public JobPost $jobPost;

    public string $city = '';

    public string $program = '';

    /** @var array<int, string> */
    public array $cityOptions = [];

    /** @var array<int, string> */
    public array $programOptions = [];

    public string $jobTitle = '';

    public string $titleEn = '';

    public string $titleAr = '';

    public string $bodyEn = '';

    public string $bodyAr = '';

    public int $versionId;

    public string $versionFingerprint = '';

    public int $versionNumber = 1;

    public string $versionStatus = 'draft';

    public ?int $publishedVersionNumber = null;

    public ?int $publishedVersionId = null;

    public ?string $notice = null;

    public string $noticeType = 'success';

    public bool $hasConflict = false;

    /** Set by a successful publish, so the page can point at what comes next. */
    public bool $justPublished = false;

    public function mount(HiringCampaign $campaign, RecruitmentJobPostService $service): void
    {
        $existing = $campaign->jobPost()->first();
        $existing ? Gate::authorize('manage', $existing) : Gate::authorize('create', [JobPost::class, $campaign]);

        try {
            $this->applyState($service->openEditor($campaign, auth()->user()));
        } catch (DomainException $exception) {
            abort(409, $exception->getMessage());
        }
        $this->campaign = $campaign;
        $this->cityOptions = City::query()->where('is_active', true)->orderBy('name_en')->pluck('name_en')
            ->map(fn ($name) => mb_strtoupper(trim((string) $name)))->filter()->unique()->values()->all();
        $this->programOptions = Program::query()->where('is_active', true)->orderBy('name')->pluck('name')
            ->map(fn ($name) => mb_strtoupper(trim((string) $name)))->filter()->unique()->values()->all();
        $this->cityOptions = collect([$this->city, ...$this->cityOptions])->filter()->unique()->values()->all();
        $this->programOptions = collect([$this->program, ...$this->programOptions])->filter()->unique()->values()->all();
    }

    public function saveDraft(RecruitmentJobPostService $service): void
    {
        Gate::authorize('manage', $this->jobPost);
        try {
            $version = $service->saveDraft($this->jobPost, $this->validatedValues(), $this->versionId, $this->versionFingerprint);
            $this->syncVersion($version, $service);
            $this->success($version->status === 'draft' ? __('recruitment.job_post.saved') : __('recruitment.job_post.no_changes'));
        } catch (StaleRecruitmentDraftException) {
            $this->conflict();
        }
    }

    public function updating(string $name): void
    {
        // Editing the content makes the "published" prompt out of date.
        if (in_array($name, ['city', 'program', 'jobTitle', 'bodyEn', 'bodyAr'], true)) {
            $this->justPublished = false;
        }
    }

    public function publish(RecruitmentJobPostService $service): void
    {
        Gate::authorize('publish', $this->jobPost);
        try {
            $version = $service->publish($this->jobPost, $this->validatedValues(), auth()->user(), $this->versionId, $this->versionFingerprint);
            $this->syncVersion($version, $service);
            $this->success(__('recruitment.job_post.published', ['version' => $version->version_no]));
            $this->justPublished = true;
        } catch (StaleRecruitmentDraftException) {
            $this->conflict();
        }
    }

    public function unpublish(RecruitmentJobPostService $service): void
    {
        Gate::authorize('publish', $this->jobPost);
        if (! $this->publishedVersionId) {
            return;
        }

        try {
            $version = $service->unpublish($this->jobPost, auth()->user(), $this->publishedVersionId);
            $this->fillValues($this->valuesFromVersion($version));
            $this->syncVersion($version, $service);
            $this->success(__('recruitment.job_post.unpublished'));
        } catch (StaleRecruitmentDraftException) {
            $this->conflict();
        }
    }

    public function reloadLatest(RecruitmentJobPostService $service): void
    {
        Gate::authorize('manage', $this->jobPost);
        $this->applyState($service->openEditor($this->campaign, auth()->user()));
        $this->hasConflict = false;
        $this->notice = __('recruitment.job_post.reloaded');
        $this->noticeType = 'info';
        $this->toast($this->notice, 'info');
    }

    public function resetFromApprovedRequest(RecruitmentJobPostService $service): void
    {
        Gate::authorize('manage', $this->jobPost);
        $this->fillValues($service->sourceValues($this->campaign));
        $this->resetValidation();
        $this->notice = __('recruitment.job_post.reset_notice');
        $this->noticeType = 'info';
        $this->toast($this->notice, 'info');
    }

    public function updated(): void
    {
        if (! $this->hasConflict) {
            $this->notice = null;
        }
    }

    public function render()
    {
        return view('livewire.recruitment.job-posts.editor', [
            'nextStep' => $this->justPublished ? $this->nextStep() : null,
        ]);
    }

    /** After the post, the form; once both exist, back to the campaign. */
    private function nextStep(): array
    {
        $form = $this->campaign->applicationForm()->with('currentVersion')->first();
        $formPublished = $form?->status === 'published' && $form?->currentVersion?->published_at !== null;

        return $formPublished
            ? [
                'message' => __('recruitment.publishing.next_after_post_done'),
                'label' => __('recruitment.publishing.back'),
                'url' => route('recruitment.campaigns.show', ['campaignId' => $this->campaign->id]).'#step-link',
            ]
            : [
                'message' => __('recruitment.publishing.next_after_post'),
                'label' => __('recruitment.publishing.go_to_form'),
                'url' => route('recruitment.campaigns.application-form.edit', $this->campaign),
            ];
    }

    /** @return array<string, string> */
    private function validatedValues(): array
    {
        $validated = $this->validate([
            'city' => ['required', 'string', 'max:80'], 'program' => ['required', 'string', 'max:100'],
            'jobTitle' => ['required', 'string', 'max:120'],
            'bodyEn' => ['nullable', 'string', 'max:10000'],
            'bodyAr' => ['nullable', 'string', 'max:10000'],
        ]);

        return [
            'city' => $validated['city'], 'program' => $validated['program'], 'job_title' => $validated['jobTitle'],
            // The bilingual headings are part of the public snapshot, but
            // they are a fixed Hope Center format rather than a second pair
            // of fields HR needs to maintain.
            'title_en' => "We're Hiring — {$validated['jobTitle']}",
            'title_ar' => $this->arabicHeading(),
            'body_en' => $validated['bodyEn'] ?? '', 'body_ar' => $validated['bodyAr'] ?? '',
        ];
    }

    /** @param array<string, string> $values */
    private function fillValues(array $values): void
    {
        $this->city = $values['city'];
        $this->program = $values['program'];
        $this->jobTitle = $values['job_title'];
        $this->titleEn = $values['title_en'];
        $this->titleAr = $values['title_ar'];
        $this->bodyEn = $values['body_en'];
        $this->bodyAr = $values['body_ar'];
    }

    /** @param array<string, mixed> $state */
    private function applyState(array $state): void
    {
        $this->jobPost = $state['job_post'];
        $this->fillValues($state['values']);
        $this->versionFingerprint = $state['fingerprint'];
        $this->setVersionState($state['version']);
    }

    private function syncVersion(JobPostVersion $version, RecruitmentJobPostService $service): void
    {
        $this->jobPost->refresh();
        $this->versionFingerprint = $service->fingerprint($version);
        $this->setVersionState($version);
        $this->hasConflict = false;
    }

    private function setVersionState(JobPostVersion $version): void
    {
        $this->versionId = $version->getKey();
        $this->versionNumber = $version->version_no;
        $this->versionStatus = $version->status;
        $published = $this->jobPost->fresh()->currentVersion;
        $this->publishedVersionNumber = $published?->version_no;
        $this->publishedVersionId = $published?->getKey();
    }

    /** @return array<string, string> */
    private function valuesFromVersion(JobPostVersion $version): array
    {
        $payload = $version->public_payload_json;

        return [
            'city' => (string) ($payload['city'] ?? ''), 'program' => (string) ($payload['program'] ?? ''),
            'job_title' => (string) ($payload['job_title'] ?? ''), 'title_en' => (string) $version->title_en,
            'title_ar' => (string) $version->title_ar, 'body_en' => (string) $version->body_en, 'body_ar' => (string) $version->body_ar,
        ];
    }

    private function success(string $message): void
    {
        $this->notice = $message;
        $this->noticeType = 'success';
        $this->hasConflict = false;
        $this->toast($message);
    }

    private function conflict(): void
    {
        $this->notice = __('recruitment.job_post.conflict');
        $this->noticeType = 'error';
        $this->hasConflict = true;
        $this->toast($this->notice, 'error');
    }

    private function arabicHeading(): string
    {
        $title = trim(preg_replace('/^مطلوب\s+متطوع\s+[—-]\s*/u', '', $this->titleAr) ?? '');

        return 'مطلوب متطوع — '.($title !== '' ? $title : $this->jobTitle);
    }
}
