@props(['campaign'])

@php
    $campaign->loadMissing(['jobPost.currentVersion', 'applicationForm.currentVersion']);
    $jobPost = $campaign->jobPost;
    $applicationForm = $campaign->applicationForm;
    $canManageJobPost = $jobPost
        ? Gate::allows('manage', $jobPost)
        : Gate::allows('create', [App\Models\JobPost::class, $campaign]);
    $canManageForm = $applicationForm
        ? Gate::allows('manage', $applicationForm)
        : Gate::allows('create', [App\Models\ApplicationForm::class, $campaign]);
@endphp

<div {{ $attributes->class(['grid gap-4 md:grid-cols-2']) }}>
    <article class="rounded-card border border-secondary-soft bg-white p-5 shadow-e1">
        <div class="flex items-start justify-between gap-3">
            <div class="flex gap-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-small font-bold text-white">1</span>
                <div>
                <h3 class="font-bold text-secondary-dark">{{ __('recruitment.publishing.job_post') }}</h3>
                <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.publishing.job_post_help') }}</p>
                </div>
            </div>
            <span class="rounded-full border px-3 py-1 text-small font-semibold {{ $jobPost?->status === 'published' ? 'border-primary/30 bg-primary/10 text-primary' : 'border-secondary-soft bg-secondary-soft/30 text-secondary-medium' }}">
                {{ $jobPost?->status === 'published' ? __('recruitment.job_post.status_published') : __('recruitment.job_post.status_draft') }}
            </span>
        </div>
        @if ($jobPost?->currentVersion)
            <p class="mt-3 text-small text-secondary-medium">{{ __('recruitment.job_post.published_version') }} #{{ $jobPost->currentVersion->version_no }}</p>
        @endif
        @if ($canManageJobPost)
            <a href="{{ route('recruitment.campaigns.job-post.edit', $campaign) }}" wire:navigate class="mt-5 inline-flex cursor-pointer rounded-control bg-primary px-4 py-2 text-body font-semibold text-white hover:bg-primary-hover">
                {{ $jobPost ? __('recruitment.publishing.edit_job_post') : __('recruitment.publishing.create_job_post') }}
            </a>
        @endif
    </article>

    <article class="rounded-card border border-secondary-soft bg-white p-5 shadow-e1">
        <div class="flex items-start justify-between gap-3">
            <div class="flex gap-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-small font-bold text-white">2</span>
                <div>
                <h3 class="font-bold text-secondary-dark">{{ __('recruitment.publishing.application_form') }}</h3>
                <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.publishing.application_form_help') }}</p>
                </div>
            </div>
            <span class="rounded-full border px-3 py-1 text-small font-semibold {{ $applicationForm?->status === 'published' ? 'border-primary/30 bg-primary/10 text-primary' : 'border-secondary-soft bg-secondary-soft/30 text-secondary-medium' }}">
                {{ $applicationForm?->status === 'published' ? __('recruitment.job_post.status_published') : __('recruitment.job_post.status_draft') }}
            </span>
        </div>
        @if ($applicationForm?->currentVersion)
            <p class="mt-3 text-small text-secondary-medium">{{ __('recruitment.job_post.published_version') }} #{{ $applicationForm->currentVersion->version_no }}</p>
        @endif
        @if ($canManageForm)
            <a href="{{ route('recruitment.campaigns.application-form.edit', $campaign) }}" wire:navigate class="mt-5 inline-flex cursor-pointer rounded-control bg-primary px-4 py-2 text-body font-semibold text-white hover:bg-primary-hover">
                {{ $applicationForm ? __('recruitment.publishing.edit_application_form') : __('recruitment.publishing.create_application_form') }}
            </a>
        @endif
    </article>
</div>
