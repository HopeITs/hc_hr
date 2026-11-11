{{-- Sidebar links swap pages through wire:navigate instead of a full reload.
     Current page: ink text, semibold, on the hover fill. No red mark: the
     fill and the weight say it (decided 2026-11-02). `favourite` adds the
     star (favourite-star): ['id', 'starred', 'full', 'max']. --}}
@props(['active' => false, 'icon' => null, 'badge' => null, 'badgeLabel' => null, 'favourite' => null])

<li class="relative w-full group/item">
    {{-- Draggable onto the dashboard (its card): the menu id travels with it. Not wire:navigate:
         Livewire cancels mouse-down on such a link, and a cancelled mouse-down never starts a
         drag. A plain click makes the same in-page visit (Livewire.navigate); ctrl/middle-click
         still opens a new tab. --}}
    <a
        x-on:click="if ($event.button === 0 && ! $event.ctrlKey && ! $event.metaKey && ! $event.shiftKey && ! $event.altKey) { $event.preventDefault(); Livewire.navigate($el.href) }"
        draggable="true"
        x-on:dragstart="$event.dataTransfer.setData('application/x-hc-page', $el.dataset.nav || '')"
        title="{{ trim(strip_tags($slot)) }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->merge([
            'class' => "
                nav-press nav-link group relative flex items-center w-full min-h-11 md:min-h-9 px-3 py-2 md:py-1 rounded-full
                font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus
                " . ($active
                        ? ' text-ink font-semibold bg-hover'
                        : ' text-ink-2 hover:text-ink hover:bg-hover')
        ]) }}
    >

        {{-- Icon --}}
        <span class="flex items-center justify-center size-5 shrink-0 text-inherit group-hover:text-inherit [&_svg]:text-inherit">
            {!! $icon !!}
        </span>

        {{-- Label: fades as the rail narrows; the 44px link clips it (app.css "Collapsing the sidebar"). --}}
        <span
            class="
                nav-fade text-body flex-1 ms-2 text-start whitespace-nowrap
                text-inherit group-hover:text-inherit
            "
        >
            {{ $slot }}
        </span>

        {{-- A count (the work inbox): beside the label, or on the icon when the sidebar is collapsed.
             A red circle with the number in its centre (17px; two digits or more widen it into a capsule). --}}
        @if ($badge !== null)
            <span class="ms-2 inline-flex h-[17px] min-w-[17px] shrink-0 items-center justify-center rounded-full px-[3.4px] text-[10.2px] font-bold leading-none tabular-nums bg-primary text-on-brand"
                  :class="sidebarCollapsed && !open ? 'md:absolute md:top-0 md:end-0 md:ms-0' : ''"
                  aria-label="{{ $badgeLabel ?? $badge }}" data-testid="nav-badge">{{ $badge }}</span>
        @endif

    </a>

    @if ($favourite)
        <x-layouts.app.sidebar.favourite-star :id="$favourite['id']" :label="trim(strip_tags($slot))"
            :starred="$favourite['starred']" :full="$favourite['full']" :max="$favourite['max']" />
    @endif
</li>
