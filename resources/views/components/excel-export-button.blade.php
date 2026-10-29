{{-- @props([
    'wireClick' => null,
    'icon'      => 'export_excel.svg',
])

@php
    $base = 'py-2 px-3 inline-flex items-center justify-center 
             rounded-control border border-neutral-300
             bg-white hover:bg-neutral-50 shadow-e1 cursor-pointer
             transition duration-150 ease-in-out
             disabled:opacity-60 disabled:cursor-not-allowed
             focus:outline-none focus:ring-0 active:ring-0';
@endphp

<button
    type="button"
    wire:loading.attr="disabled"
    {{ $attributes->merge(['class' => $base]) }}
    @if($wireClick) wire:click="{{ $wireClick }}" @endif
    title="{{ __('general.export-excel') }}"
>
    
    <span role="status" class="me-2" wire:loading wire:target="{{ $wireClick }}">
        <svg aria-hidden="true" class="inline size-5 animate-spin text-ink-2" viewBox="0 0 100 101" xmlns="http://www.w3.org/2000/svg">
            <path d="M100 50.59c0 27.61-22.39 49.99-50 49.99S0 78.2 0 50.59C0 22.98 22.39.59 50 .59s50 22.39 50 50Z" fill="currentColor" opacity=".2"/>
            <path d="M93.97 39.04c2.43-.64 3.9-3.13 3.04-5.49-1.72-4.73-4.14-9.17-7.2-13.19-3.97-5.23-8.94-9.63-14.67-12.94C69.54 4.1 63.28 1.94 56.77 1.05 51.77.37 46.7.45 41.73 1.28c-2.47.41-3.92 2.9-3.28 5.34.64 2.44 3.11 3.9 5.55 3.26 4.93-1.14 10.1-1.07 15 .19 5.38 1.39 10.33 4 14.48 7.62 3.74 3.2 6.76 7.12 8.88 11.5.99 2.03 3.32 3.05 5.61 2.35Z" fill="currentColor"/>
        </svg>
        <span class="sr-only">{{ __('general.loading') }}</span>
    </span>

    
    <img
        wire:loading.remove
        wire:target="{{ $wireClick }}"
        src="{{ asset('assets/img/' . $icon) }}"
        alt="{{ __('general.export-excel') }}"
        class="h-5 w-auto"
    />
</button>
 --}}

@props([
    'icon' => 'export_excel.svg',
])

@php
    $base = 'py-2 px-3 inline-flex items-center justify-center 
             rounded-control border border-neutral-300
             bg-white hover:bg-neutral-50 shadow-e1 cursor-pointer
             transition duration-150 ease-in-out
             focus:outline-none focus:ring-0 active:ring-0';
@endphp

<button
    type="button"
    {{ $attributes->merge(['class' => $base]) }}
    title="{{ __('general.export-excel') }}"
>
    <img
        src="{{ asset('assets/img/' . $icon) }}"
        alt="{{ __('general.export-excel') }}"
        class="h-5 w-auto"
    />
</button>

