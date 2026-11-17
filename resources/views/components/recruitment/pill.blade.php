@props([
    // Background and text classes from the status enum's classes(), or a
    // tag's classes(). Each hue carries one meaning across recruitment.
    'tone' => 'bg-secondary-soft/60 text-secondary-dark',
])

{{-- A short status or label chip. Layout extras (margin, shrink, wrapping) go in class. --}}
<span {{ $attributes->class(['rounded-control px-2 py-0.5 text-xs font-semibold', $tone]) }}>{{ $slot }}</span>
