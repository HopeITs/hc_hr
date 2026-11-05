@php($pageTitle = $editingNumber ? __('contracts.amendment.edit-title', ['number' => $editingNumber]) : __('contracts.amendment.title'))

<x-slot:title>
    {{ $pageTitle }}
</x-slot:title>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <livewire:layout.breadcrumbs :title="$pageTitle" :crumbs="[
        ['label' => trans_choice('general.contracts', 2), 'url' => route('contracts.index')],
        ['label' => $contract->employee?->name ?? __('general.na'), 'url' => route('contracts.show', $contract->id)],
        ['label' => $pageTitle],
    ]"/>

    @include('livewire.contract.composer._screen', [
        'mode' => 'amend',
        'cancelUrl' => route('contracts.show', $contract->id),
        'currentPeriod' => $contract->start_date?->format('d-m-Y').' — '.$contract->end_date?->format('d-m-Y'),
    ])
</div>
