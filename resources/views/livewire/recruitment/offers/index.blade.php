<x-slot:title>{{ __('recruitment-applicant.offers.title') }}</x-slot:title>

<div class="space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.offers.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.offers.title')]]" />
    <p class="-mt-4 text-body text-secondary-medium">{{ __('recruitment-applicant.offers.index_hint') }}</p>

    <livewire:recruitment.offers.offer-table />
</div>
