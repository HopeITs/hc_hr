{{-- Sidebar links swap pages through wire:navigate instead of a full reload.
     Current page (identity rule): ink text on the hover fill, with the brand
     red as a bar on the start edge, never a red fill. `favourite` adds the
     star (favourite-star): ['id', 'starred', 'full', 'max']. --}}
@props(['active' => false, 'icon' => null, 'badge' => null, 'badgeLabel' => null, 'favourite' => null])

<li class="relative w-full group/item">
    <a
        wire:navigate
        title="{{ trim(strip_tags($slot)) }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->merge([
            'class' => "
                nav-press group relative flex items-center w-full min-h-11 px-3 py-2 rounded-control
                font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus
                " . ($active
                        ? ' text-ink font-semibold bg-hover before:absolute before:inset-y-2 before:start-0 before:w-[3px] before:rounded-full before:bg-primary'
                        : ' text-ink-2 hover:text-ink hover:bg-hover')
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
                text-body flex-1 ms-2 text-start whitespace-nowrap
                text-inherit group-hover:text-inherit
            "
            x-show="!sidebarCollapsed || open"
        >
            {{ $slot }}
        </span>

        {{-- A count (the work inbox): beside the label, or on the icon when the sidebar is collapsed. --}}
        @if ($badge !== null)
            <span class="ms-2 min-w-6 rounded-full px-1.5 py-0.5 text-center text-caption font-bold leading-none bg-primary text-on-brand"
                  :class="sidebarCollapsed && !open ? 'md:absolute md:top-0 md:end-0 md:ms-0' : ''"
                  aria-label="{{ $badgeLabel ?? $badge }}" data-testid="nav-badge">{{ $badge }}</span>
        @endif

    </a>

    @if ($favourite)
        <x-layouts.app.sidebar.favourite-star :id="$favourite['id']" :label="trim(strip_tags($slot))"
            :starred="$favourite['starred']" :full="$favourite['full']" :max="$favourite['max']" />
    @endif
</li>
