@php
    $heading = $renewalSource ? __('general.renew-contract') : __('general.add-new');
@endphp

<x-slot:title>
    {{ $heading }}
</x-slot:title>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <livewire:layout.breadcrumbs :title="$heading" :crumbs="[
        ['label' => trans_choice('general.contracts', 2), 'url' => route('contracts.index')],
        ['label' => $heading],
    ]"/>

    @if($renewalSource)
        {{-- Grey surface with a solid red edge. Never `bg-primary/5` here: a
             low-opacity brand red reads as pink, which app.css disallows. --}}
        <div class="mt-2 flex items-center gap-4 rounded-control border border-neutral-200 border-s-4 border-s-primary
                    bg-neutral-50 px-4 py-3">
            <img
                src="{{ $renewalSource->employee?->avatar_url ?: asset('images/default-avatar.png') }}"
                alt=""
                loading="lazy"
                class="size-11 shrink-0 rounded-full object-cover bg-neutral-100 ring-1 ring-neutral-300"
            />
            <div class="min-w-0">
                {{-- Escaped output, not @lang: the translator substitutes :name raw,
                     so @lang would inject the stored employee name as markup. --}}
                <p class="truncate text-body font-semibold text-neutral-900">
                    {{ __('general.renewing-contract-for', ['name' => $renewalSource->employee?->name ?? __('general.na')]) }}
                </p>
                <p class="mt-0.5 text-caption tabular-nums text-neutral-600">
                    <span class="font-medium">@lang('general.current-period'):</span>
                    {{ $renewalSource->start_date?->format('d-m-Y') }} — {{ $renewalSource->end_date?->format('d-m-Y') }}
                </p>
            </div>
        </div>
    @endif

    {{-- C2: Cancel returns to the page New was pressed on, when SafeReturn allows it. --}}
    @include('livewire.contract.composer._screen', ['mode' => 'create', 'cancelUrl' => $returnTo ?? route('contracts.index')])
</div>
