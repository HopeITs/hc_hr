@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-body text-neutral-600']) }}>
        {{ $status }}
    </div>
@endif
