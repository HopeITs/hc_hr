@props(['href' => null])

{{-- Legacy API, identity look: the quiet (surface) button of <x-button variant="quiet">. --}}
@php
    $baseClasses = 'hc-btn hc-btn--quiet flex';
@endphp

@if ($href)
    {{-- Internal links swap pages via wire:navigate; pass :wire:navigate="false" to opt out. --}}
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $baseClasses, 'wire:navigate' => true]) }}>
        {{ $slot }}
    </a>
@else
    <button wire:loading.attr="disabled"
        {{ $attributes->merge([
            'class' => $baseClasses,
            'type'  => 'button',
        ]) }}>
        {{ $slot }}
    </button>
@endif
