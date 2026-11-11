<x-slot:title>{{ __('recruitment-campaign.campaigns') }}</x-slot:title>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <livewire:layout.breadcrumbs :title="__('recruitment-campaign.campaigns')" :crumbs="[['label' => __('recruitment-campaign.campaigns')]]" />
        {{-- The permission alone, since the policy's create needs a vacancy and
             none is chosen yet. Each seat is authorized again on the next screen. --}}
        @if (auth()->user()?->hasActivePermission('recruitment-campaigns.manage'))
            <a href="{{ route('recruitment.campaigns.create') }}" wire:navigate class="hc-btn hc-btn--add hc-btn--reveal">
                <x-ic name="plus" /><span class="hc-reveal-label">{{ __('recruitment-campaign.new_campaign') }}</span>
            </a>
        @endif
    </div>
    <p class="-mt-4 text-body text-secondary-medium">{{ __('recruitment-campaign.campaigns_hint') }}</p>

    <livewire:recruitment.campaigns.campaign-table />
</div>
