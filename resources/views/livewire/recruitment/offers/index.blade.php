<x-slot:title>{{ __('recruitment-applicant.offers.title') }}</x-slot:title>

<div class="space-y-6">
    <div>
        <livewire:layout.breadcrumbs :title="__('recruitment-applicant.offers.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.offers.title')]]" />
        <p class="mt-1 max-w-2xl text-small text-ink-2">{{ __('recruitment-applicant.offers.intro') }}</p>
    </div>

    <livewire:recruitment.offers.offer-table />
</div>
