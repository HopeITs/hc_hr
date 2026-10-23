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
    {{ $attributes->class(['flex min-h-72 items-center justify-center rounded-surface border border-dashed border-neutral-300 bg-white p-[clamp(1.5rem,4vw,3rem)] text-center shadow-e1 dark:border-neutral-700 dark:bg-neutral-900']) }}
    role="{{ $isError ? 'alert' : 'status' }}"
    aria-live="{{ $isError ? 'assertive' : 'polite' }}"
    data-state="{{ $variant }}"
>
    <div class="max-w-sm">
        <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-neutral-100 text-primary dark:bg-neutral-800" aria-hidden="true">
            @isset($icon)
                {{ $icon }}
            @else
                <x-icons.users class="size-7" />
            @endisset
        </span>
        <h2 class="mt-4 text-h4 font-bold text-secondary-dark dark:text-white">{{ $title }}</h2>
        <p class="mt-2 text-body leading-relaxed text-secondary-light">{{ $description }}</p>
        @isset($action)
            <div class="mt-5 flex justify-center">{{ $action }}</div>
        @endisset
    </div>
</div>
