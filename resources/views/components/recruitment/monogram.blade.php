@props([
    'name' => '',
    // 'candidate' is a quiet well; 'staff' is solid ink, so a panel reads apart from the person interviewed.
    'tone' => 'staff',
    // The viewer's own monogram carries a ring, so "am I on this panel?" answers itself.
    'self' => false,
    'size' => 'sm',
])

@php
    $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = collect(array_slice($words, 0, 2))->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

{{-- Initials, not photos: staff photos are city-scoped and heavy on a weak line. The name itself is always given alongside. --}}
<span aria-hidden="true" {{ $attributes->class([
    'inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold leading-none',
    'size-7 text-caption' => $size === 'sm',
    'size-10 text-body' => $size === 'md',
    'bg-neutral-100 text-secondary-dark' => $tone === 'candidate',
    'bg-neutral-800 text-white' => $tone === 'staff' && ! $self,
    'bg-white text-neutral-900 ring-2 ring-neutral-900' => $self,
]) }}>{{ $initials !== '' ? $initials : '?' }}</span>
