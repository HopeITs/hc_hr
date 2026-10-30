@props([
    'status',
])

@if ($status)
    {{-- A confirmation: ink text with a check (no green; the identity has no success hue). --}}
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-2 font-semibold text-body text-ink']) }} role="status">
        <x-ic name="check-circle" class="size-5" />
        <span>{{ $status }}</span>
    </div>
@endif
