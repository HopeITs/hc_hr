{{--
    The one button.

      <x-button wire:click="save">{{ __('general.save') }}</x-button>
      <x-button variant="outline" size="sm" :href="route('…')">…</x-button>

    variant: primary (red fill — the one main action) | outline (card + ink border)
             | quiet (surface fill) | link (underlined text)
    size:    sm | md (default) | lg — 44px minimum on touch screens at every size
    href:    renders a link (wire:navigate by default; pass :wire:navigate="false" to opt out)
    type:    button (default) | submit | reset
    icon:    optional leading <x-ic> name
    full:    true for full width
    loading: true (default) — while Livewire works on this button's action the
             button is disabled and aria-busy, and a spinner replaces the icon.
             target: the wire:target to watch; defaults to the method in wire:click,
             otherwise any request of the component (as the old primary-button did).
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'full' => false,
    'loading' => true,
    'target' => null,
])

@php
    $variant = in_array($variant, ['primary', 'outline', 'quiet', 'link'], true) ? $variant : 'primary';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    $classes = collect(['hc-btn', 'hc-btn--'.$variant])
        ->when($size !== 'md', fn ($c) => $c->push('hc-btn--'.$size))
        ->when($full, fn ($c) => $c->push('w-full'))
        ->implode(' ');

    $click = $attributes->wire('click')->value();
    $target ??= $click ? (string) str($click)->before('(')->trim() : null;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes, 'wire:navigate' => true]) }}>
        @if ($icon)<x-ic :name="$icon" />@endif
        {{ $slot }}
    </a>
@else
    <button
        @if ($loading)
            wire:loading.attr="disabled"
            wire:loading.delay.none.attr="aria-busy"
            @if ($target) wire:target="{{ $target }}" @endif
        @endif
        {{ $attributes->merge(['class' => $classes, 'type' => $type]) }}
    >
        @if ($loading)
            <span class="hc-spin" wire:loading @if ($target) wire:target="{{ $target }}" @endif aria-hidden="true"></span>
            <span class="sr-only" wire:loading @if ($target) wire:target="{{ $target }}" @endif role="status">{{ __('general.a11y.loading') }}</span>
            @if ($icon)<x-ic :name="$icon" wire:loading.remove :wire:target="$target" />@endif
        @elseif ($icon)
            <x-ic :name="$icon" />
        @endif
        {{ $slot }}
    </button>
@endif
