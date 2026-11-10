<x-slot:title>{{ __('recruitment-campaign.campaigns') }}</x-slot:title>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <livewire:layout.breadcrumbs :title="__('recruitment-campaign.campaigns')" :crumbs="[['label' => __('recruitment-campaign.campaigns')]]" />
        {{-- The permission alone, since the policy's create needs a vacancy and
             none is chosen yet. Each seat is authorized again on the next screen. --}}
        @if (auth()->user()?->hasActivePermission('recruitment-campaigns.manage'))
            <a href="{{ route('recruitment.campaigns.create') }}" wire:navigate
                class="inline-flex cursor-pointer items-center gap-2 rounded-control bg-primary px-4 py-2 text-body font-semibold text-white shadow-e1 transition hover:bg-primary-hover">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                {{ __('recruitment-campaign.new_campaign') }}
            </a>
        @endif
    </div>
    <p class="-mt-4 text-body text-secondary-medium">{{ __('recruitment-campaign.campaigns_hint') }}</p>

    <livewire:recruitment.campaigns.campaign-table />
</div>
