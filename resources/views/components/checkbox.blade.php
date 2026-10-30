@props(['model'])

<label {{ $attributes->merge(['class' => 'flex items-center']) }}>
    <input
        type="checkbox"
        class="size-4 bg-card border-[1.5px] border-line rounded-inset text-ink me-1"
    />
    {{ $slot }}
</label>
