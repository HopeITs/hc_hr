{{--
    Empty / filtered-empty / error panel. Existing callers pass only title + description.

    variant: empty (default) | filtered | error
    title / description: default to the variant's shared copy when omitted
    slots: icon (replaces the default icon), action (button/link under the text)
--}}
@props(['title' => null, 'description' => null, 'variant' => 'empty'])

@php
    $variant = in_array($variant, ['empty', 'filtered', 'error'], true) ? $variant : 'empty';
    $title ??= __("general.state-{$variant}-title");
    $description ??= __("general.state-{$variant}-help");
    $isError = $variant === 'error';
@endphp

<div
    {{ $attributes->class(['flex min-h-72 items-center justify-center rounded-surface border-[1.5px] border-dashed border-line bg-card p-[clamp(1.5rem,4vw,3rem)] text-center']) }}
    role="{{ $isError ? 'alert' : 'status' }}"
    aria-live="{{ $isError ? 'assertive' : 'polite' }}"
    data-state="{{ $variant }}"
>
    <div class="max-w-sm">
        {{-- Identity empty state: dashed line border, ink-3 icon, one bold line, one action. --}}
        <span class="mx-auto flex size-14 items-center justify-center text-ink-3" aria-hidden="true">
            @isset($icon)
                {{ $icon }}
            @else
                <x-icons.users class="size-9" />
            @endisset
        </span>
        <h2 class="mt-2 text-h4 font-bold text-ink">{{ $title }}</h2>
        <p class="mt-1.5 text-body text-ink-2">{{ $description }}</p>
        @isset($action)
            <div class="mt-5 flex justify-center">{{ $action }}</div>
        @endisset
    </div>
</div>
