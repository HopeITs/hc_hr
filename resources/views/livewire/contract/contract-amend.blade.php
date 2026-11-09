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

    @if($previous)
        {{-- Said before anything is filled in: the contract was already amended,
             perhaps days ago, and this amendment starts from what that one left. --}}
        <div class="mb-4 flex gap-3 rounded-control border border-line border-s-4 border-s-ink bg-card px-4 py-3 text-small text-ink shadow-e1"
             role="note" data-testid="amend-previous" data-tour="amend.previous">
            <x-ic :name="$previous['recent'] ? 'alert' : 'info'" class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-semibold">
                    {{ trans_choice('contracts.amendment.previous-title', $previous['count'], ['count' => $previous['count']]) }}
                </p>
                <p class="num">
                    @lang('contracts.amendment.previous-last', ['number' => $previous['number'], 'date' => $previous['date'], 'ago' => $previous['ago']])
                    @if(filled($previous['summary']))
                        <span class="text-ink-2">— {{ $previous['summary'] }}</span>
                    @endif
                </p>
                <p class="text-ink-2">@lang('contracts.amendment.previous-hint', ['date' => $previous['date']])</p>
            </div>
            {{-- Same tab: a new one opened the installed app window instead. The
                 composer's unsaved-changes guard asks first if anything was typed. --}}
            <a class="hc-btn hc-btn--quiet hc-btn--sm shrink-0 self-start"
               href="{{ route('contracts.show', $contract->id) }}#amendments">
                @lang('contracts.amendment.previous-view')
            </a>
        </div>
    @endif

    @include('livewire.contract.composer._screen', [
        'mode' => 'amend',
        'cancelUrl' => route('contracts.show', $contract->id),
        'currentPeriod' => $contract->start_date?->format('d-m-Y').' — '.$contract->end_date?->format('d-m-Y'),
    ])
</div>
