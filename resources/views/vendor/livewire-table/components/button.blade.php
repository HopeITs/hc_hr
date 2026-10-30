@props(['size' => 'md', 'active' => false])

<button
    {{
        $attributes->class([
            'flex items-center gap-1 relative bg-white border transition ease-in-out rounded-control shadow-e1 h-full text-body' => true,
            'active:bg-neutral-100' => true,
            'border-neutral-200 text-neutral-800 hover:text-neutral-500 focus:border-secondary-medium active:text-neutral-800' => ! $active,
            'border-secondary-medium text-primary' => $active,
            'px-3 py-2' => $size === 'md',
            'px-2 py-1' => $size === 'sm',
        ])
    }}
>
    {{ $slot }}
    @if ($active)
        <span class="absolute -right-1 -top-1 rounded-full shadow-xs bg-primary block size-2.5"></span>
    @endif
</button>
