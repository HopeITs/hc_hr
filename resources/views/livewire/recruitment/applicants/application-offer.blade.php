@php use App\Enums\RecruitmentJobOfferStatus; @endphp
<div>
    @if ($visible && ($offer || $canCreate))
        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-control border border-neutral-200 p-3 text-sm">
            <h4 class="font-semibold">{{ __('recruitment-applicant.offers.job_offer') }}</h4>
            @if ($offer)
                <a href="{{ route('recruitment.offers.show', ['offerId' => $offer->id]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
                    <span class="text-secondary-medium">v{{ $offer->currentVersion?->version_no }}</span>
                    @php $offerChip = \App\Support\Status\RecruitmentStatus::offer($offer->status); @endphp
                    <x-status class="recruitment-badge" :tone="$offerChip['tone']" :icon="$offerChip['icon']" :label="$offerChip['label']" />
                    @if ($offer->hiringConversion?->isConverted())
                        <x-status class="recruitment-badge" tone="done" icon="check-circle" :label="__('recruitment-applicant.statuses.hired')" />
                    @endif
                </a>
            @else
                <a href="{{ route('recruitment.offers.create', ['applicationId' => $application->id]) }}" wire:navigate
                   class="rounded-control border border-primary px-3 py-1 text-sm font-semibold text-primary hover:bg-selection">{{ __('recruitment-applicant.offers.create') }}</a>
            @endif
        </div>
    @endif
</div>
