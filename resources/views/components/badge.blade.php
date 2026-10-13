{{-- Brand tones only. The enums pass their own `color` value here, so every
     accepted name resolves to a brand colour; anything else falls back to the
     dark grey. Whole class names, so Tailwind's scanner finds them. --}}
@props(['color' => 'secondary-dark'])
@php
    $tone = match ($color) {
        'primary' => 'bg-neutral-100 text-primary',
        'secondary-medium', 'secondary-light' => 'bg-neutral-100 text-secondary-medium',
        default => 'bg-neutral-100 text-secondary-dark',
    };
@endphp
<span {{ $attributes->class([$tone, 'text-small font-medium me-2 px-2 py-1 rounded-inset']) }}>{{ $slot }}</span>
