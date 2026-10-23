{{--
    Loading placeholder. Decorative (aria-hidden): pair it with aria-busy and an sr-only
    "Loading" on the container; never rely on the skeleton itself for announcement.

    variant: block (default, sized by classes) | line (text rows, `lines` of them) | card
    Pulse is motion-safe only; reduced-motion users get a static placeholder.
--}}
@props(['variant' => 'block', 'lines' => 3])

@php $bar = 'rounded bg-neutral-200 motion-safe:animate-pulse dark:bg-neutral-700'; @endphp

@if ($variant === 'line')
    <div {{ $attributes->class(['space-y-3']) }} aria-hidden="true">
        @for ($i = 0; $i < max(1, (int) $lines); $i++)
            <div class="{{ $bar }} h-4 {{ $i === (int) $lines - 1 && $lines > 1 ? 'w-2/3' : 'w-full' }}"></div>
        @endfor
    </div>
@elseif ($variant === 'card')
    <div {{ $attributes->class(['rounded-surface border border-neutral-200 bg-white p-5 shadow-e1 dark:border-neutral-700 dark:bg-neutral-900']) }} aria-hidden="true">
        <div class="flex items-center gap-3">
            <div class="{{ $bar }} size-10 shrink-0 rounded-full"></div>
            <div class="{{ $bar }} h-4 w-1/2"></div>
        </div>
        <div class="mt-5 space-y-3">
            <div class="{{ $bar }} h-4 w-full"></div>
            <div class="{{ $bar }} h-4 w-5/6"></div>
            <div class="{{ $bar }} h-4 w-2/3"></div>
        </div>
    </div>
@else
    <div {{ $attributes->class([$bar, 'h-24 w-full']) }} aria-hidden="true"></div>
@endif
