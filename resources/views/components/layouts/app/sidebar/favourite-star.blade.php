{{--
    The star beside a menu page (favourites, NavigationMemory). Shown on hover
    and on keyboard focus, always on touch screens (no hover there), never in
    the collapsed icon rail. When eight are already starred, an unstarred page
    says so instead of starring: aria-disabled keeps it focusable and readable.
--}}
@props(['id', 'label', 'starred' => false, 'full' => false, 'max' => 8])

@php($refused = $full && ! $starred)

<button type="button"
        x-show="!sidebarCollapsed || open"
        @unless ($refused) wire:click="toggleFavourite(@js($id))" @endunless
        aria-pressed="{{ $starred ? 'true' : 'false' }}"
        @if ($refused) aria-disabled="true" @endif
        aria-label="{{ $refused ? trans_choice('navigation.favourites.full', $max, ['count' => $max]) : __($starred ? 'navigation.favourites.remove' : 'navigation.favourites.add', ['page' => $label]) }}"
        title="{{ $refused ? trans_choice('navigation.favourites.full', $max, ['count' => $max]) : __($starred ? 'navigation.favourites.remove' : 'navigation.favourites.add', ['page' => $label]) }}"
        data-favourite-toggle="{{ $id }}"
        class="absolute end-0 top-1/2 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-control
               opacity-0 transition-opacity group-hover/item:opacity-100 group-focus-within/item:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100
               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus
               {{ $refused ? 'cursor-not-allowed text-ink-3' : ($starred ? 'text-ink' : 'text-ink-3 hover:text-ink') }}">
    <x-icons.star :filled="$starred" class="size-4" />
</button>
