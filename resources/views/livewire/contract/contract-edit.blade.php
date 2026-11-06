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
    @if($termsLocked)
        {{-- Said up front: the two closed sections below are not broken, they
             are changed through an amendment now (ContractEdit::submit). --}}
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-control border border-line-soft border-s-4 border-s-ink bg-soft px-3 py-2 text-small text-ink"
             data-tour="contract.amend-locked">
            <x-ic name="info" class="size-4 shrink-0" />
            <p class="min-w-0 flex-1 leading-relaxed">@lang('contracts.amendment.edit-locked')</p>
            @if($canAmend)
                <x-button size="sm" variant="outline" :href="route('contracts.amend', $contract->id)" wire:navigate>
                    @lang('contracts.amendment.add')
                </x-button>
            @endif
        </div>
    @endif

    @include('livewire.contract.composer._screen', [
        'mode' => 'edit',
        'cancelUrl' => route('contracts.show', $contract->id),
        'currentPeriod' => $contract->start_date?->format('d-m-Y').' — '.$contract->end_date?->format('d-m-Y'),
    ])
</div>
