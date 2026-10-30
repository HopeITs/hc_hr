@props([
    'icon' => 'export_excel.svg',
])

@php
    $base = 'py-2 px-3 inline-flex items-center justify-center 
             rounded-control border-[1.5px] border-line hover:border-ink-2
             bg-card hover:bg-hover shadow-e1 cursor-pointer
             transition duration-150 ease-in-out';
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

