@props(['href' => null])

@php
    $baseClasses = 'py-2 px-4 text-body font-semibold text-neutral-800 bg-neutral-200 hover:bg-neutral-300
                    cursor-pointer rounded-control shadow-e1 transition duration-150 ease-in-out
                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary
                    flex justify-center items-center disabled:opacity-60 disabled:cursor-not-allowed';
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
