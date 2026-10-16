{{-- Sidebar links swap pages through wire:navigate instead of a full reload. --}}
@props(['active' => false, 'icon' => null])

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
        :class="sidebarCollapsed && !open ? 'md:justify-center md:size-11 md:px-0 md:mx-auto' : ''"
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

    </a>
</li>
