<x-slot:title>
    {{ __('general.edit') }} {{ trans_choice('general.contracts', 1) }}
</x-slot:title>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <livewire:layout.breadcrumbs :title="__('general.edit')" :crumbs="[
        ['label' => trans_choice('general.contracts', 2), 'url' => route('contracts.index')],
        ['label' => __('general.edit')],
    ]"/>

    {{--
        No banner above the composer: whose contract this is, and the term it
        currently runs for, both already sit at the top of the contract card —
        saying it twice only pushed the form down the page.
    --}}
    @include('livewire.contract.composer._screen', [
        'mode' => 'edit',
        'cancelUrl' => route('contracts.show', $contract->id),
        'currentPeriod' => $contract->start_date?->format('d-m-Y').' — '.$contract->end_date?->format('d-m-Y'),
    ])
</div>
