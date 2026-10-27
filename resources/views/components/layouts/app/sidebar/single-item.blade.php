{{-- Sidebar links swap pages through wire:navigate instead of a full reload. --}}
@props(['active' => false, 'icon' => null, 'badge' => null, 'badgeLabel' => null])

<li class="w-full">
    <a
        wire:navigate
        title="{{ trim(strip_tags($slot)) }}"
        {{ $attributes->merge([
            'class' => "
                nav-press group flex items-center w-full px-3 py-2 rounded-control
                font-medium
                " . ($active
                        ? ' text-white font-semibold bg-primary'
                        : ' text-secondary-light hover:text-secondary-dark hover:bg-black/[0.03]')
        ]) }}
        :class="sidebarCollapsed && !open ? 'md:justify-center md:size-11 md:px-0 md:mx-auto md:relative' : ''"
    >

        {{-- Icon --}}
        <span class="flex items-center justify-center size-5 shrink-0 text-inherit group-hover:text-inherit [&_svg]:text-inherit">
            {!! $icon !!}
        </span>

        {{-- Label --}}
        <span
            class="
                text-body flex-1 ms-2 rtl:me-2 text-left rtl:text-right whitespace-nowrap
                text-inherit group-hover:text-inherit
            "
            x-show="!sidebarCollapsed || open"
        >
            {{ $slot }}
        </span>

        {{-- A count (the work inbox): beside the label, or on the icon when the sidebar is collapsed. --}}
        @if ($badge !== null)
            <span class="ms-2 min-w-6 rounded-full px-1.5 py-0.5 text-center text-caption font-bold leading-none {{ $active ? 'bg-white text-primary' : 'bg-primary text-white' }}"
                  :class="sidebarCollapsed && !open ? 'md:absolute md:top-0 md:end-0 md:ms-0' : ''"
                  aria-label="{{ $badgeLabel ?? $badge }}" data-testid="nav-badge">{{ $badge }}</span>
        @endif

    </a>
</li>
