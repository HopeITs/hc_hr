<x-slot:title>{{ __('recruitment.publishing.title') }}</x-slot:title>

<div>
    <livewire:layout.breadcrumbs
        :title="__('recruitment.publishing.title')"
        :crumbs="[
            ['label' => __('recruitment.campaign.title'), 'href' => route('recruitment.campaigns.index')],
            ['label' => __('recruitment.job_post.campaign').' #'.$campaign->id],
            ['label' => __('recruitment.publishing.title')],
        ]"
    />


    <section class="mb-5 rounded-card border border-secondary-soft bg-white p-5 shadow-e1">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-small font-semibold uppercase tracking-wide text-primary">{{ __('recruitment.job_post.campaign') }} #{{ $campaign->id }}</p>
                <h1 class="mt-1 text-title font-bold text-secondary-dark">{{ $campaign->vacancy->position->title ?? $campaign->vacancy->position->title_en ?? __('recruitment.publishing.title') }}</h1>
                <p class="mt-2 text-body text-secondary-medium">{{ __('recruitment.job_post.approved_request') }}: {{ $campaign->vacancy->createdFromRequest->request_number }}</p>
            </div>
            <span class="rounded-full bg-secondary-soft/60 px-3 py-1 text-small font-semibold text-secondary-dark">{{ $campaignStatus }}</span>
        </div>
    </section>

    <div class="mb-5 rounded-control border border-secondary-soft bg-secondary-soft/30 p-4 text-body text-secondary-dark">
        {{ __('recruitment.publishing.help') }}
    </div>

    <x-recruitment.campaign-publishing-actions :campaign="$campaign" />

    @if (auth()->user()?->hasActivePermission('recruitment-links.view') || auth()->user()?->hasActivePermission('recruitment-links.manage'))
        <livewire:recruitment.public-links.manager :campaign="$campaign" />
    @endif
</div>
