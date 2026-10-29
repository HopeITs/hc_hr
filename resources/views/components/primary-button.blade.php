@props([
    'href'         => null,
    'full'         => false,           // set to true for w-full
    'type'         => 'submit',        // button type
    'showSpinner'  => true,            // show spinner when wire:loading
    'loadingText'  => null,            // e.g. __('general.loading').'…'
])

{{-- Legacy API, identity look: the same red primary as <x-button> (components.css). New work uses <x-button>. --}}
@php
    $classes = ($full ? 'w-full ' : '') . 'hc-btn hc-btn--primary flex';
@endphp

@if ($href)
    {{-- Internal links swap pages via wire:navigate; pass :wire:navigate="false" to opt out. --}}
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes, 'wire:navigate' => true]) }}>
        {{ $slot }}
    </a>
@else
    <button
        wire:loading.attr="disabled"
        wire:loading.delay.none.attr="aria-busy"
        {{ $attributes->merge(['class' => $classes, 'type' => $type]) }}
    >
        @if($showSpinner)
            <span role="status" class="inline-flex" wire:loading>
                <span class="hc-spin" aria-hidden="true"></span>
                <span class="sr-only">{{ __("general.a11y.loading") }}</span>
            </span>
        @endif

        @if($loadingText)
            <span wire:loading.remove>{{ $slot }}</span>
            <span wire:loading>{{ $loadingText }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
