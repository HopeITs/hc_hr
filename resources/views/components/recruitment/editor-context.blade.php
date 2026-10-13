@props([
    'campaign',
    'subtitle',
    'versionNumber',
    'versionStatus',
    'publishedVersionNumber' => null,
    'justPublished' => false,
    'next' => null,
])

{{--
    The strip at the top of the job post and application form editors: where
    this sits in the campaign, which version is being edited, and — right
    after a publish — the obvious next step, so HR is never left wondering
    where to go.
--}}
<div class="mb-5 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('recruitment.campaigns.show', ['campaignId' => $campaign->id]) }}" wire:navigate
                class="inline-flex cursor-pointer items-center gap-1.5 text-body font-semibold text-primary underline-offset-4 hover:underline">
                <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7"/></svg>
                {{ __('recruitment.publishing.back') }}
            </a>
            <p class="mt-2 text-body text-secondary-medium">{{ $subtitle }}</p>
            @if ($campaign->vacancy?->createdFromRequest)
                <p class="mt-1 text-small text-secondary-medium">
                    {{ __('recruitment.job_post.approved_request') }}:
                    <bdi class="font-semibold text-secondary-dark">{{ $campaign->vacancy->createdFromRequest->request_number }}</bdi>
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 text-small">
            <span class="rounded-full border border-secondary-soft bg-secondary-soft/30 px-3 py-1 font-semibold text-secondary-dark">
                {{ __('recruitment.job_post.version') }} #{{ $versionNumber }}
                <span aria-hidden="true">·</span>
                {{ $versionStatus === 'published' ? __('recruitment.job_post.status_published') : __('recruitment.job_post.status_draft') }}
            </span>
            @if ($publishedVersionNumber)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3 py-1 font-semibold text-white">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                    {{ __('recruitment.job_post.published_version') }} #{{ $publishedVersionNumber }}
                </span>
            @endif
        </div>
    </div>

    @if ($justPublished && $next)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-surface border border-primary bg-white p-4 shadow-e1" role="status" data-next-step>
            <p class="flex items-center gap-3 text-body font-semibold text-secondary-dark">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-white" aria-hidden="true">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                </span>
                {{ $next['message'] }}
            </p>
            <a href="{{ $next['url'] }}" wire:navigate
                class="inline-flex cursor-pointer items-center gap-2 rounded-control bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary-hover">
                {{ $next['label'] }}
                <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
            </a>
        </div>
    @endif
</div>
