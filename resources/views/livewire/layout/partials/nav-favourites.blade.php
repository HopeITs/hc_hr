{{--
    "My favourites" (NavigationMemory): the pages this person starred, already
    filtered through the active role's menu, in their order.

    Reordering is by index only, top to bottom, so it reads the same in
    Arabic and English: drag an item onto another to take its place, or focus
    the handle and press the up / down arrow keys. Left and right are not
    used, so nothing flips in RTL. In the collapsed rail the list is icons,
    with the page name as the tooltip and the accessible name.
--}}
<li class="w-full pb-2" data-nav-area="favourites" data-tour="nav.favourites"
    x-data="{
        dragging: null,
        draggingLabel: '',
        announcement: '',
        {{-- Places are read from the list as it is now, so a list Livewire just re-rendered is never stale. --}}
        items() { return [...this.$root.querySelectorAll('[data-favourite]')]; },
        placeOf(el) { return this.items().indexOf(el.closest('[data-favourite]')); },
        move(id, to, label) {
            const count = this.items().length;
            if (to < 0 || to >= count) return;
            this.$wire.moveFavourite(id, to).then(() => {
                this.announcement = @js(__('navigation.favourites.moved'))
                    .replace(':page', label).replace(':position', to + 1).replace(':count', count);
                this.$nextTick(() => this.$root.querySelector(`[data-favourite-handle='${id}']`)?.focus());
            });
        },
    }">
    <span x-show="!sidebarCollapsed || open" id="nav-favourites-title" class="block px-3 py-[3px] text-caption font-semibold uppercase tracking-wider text-ink-3">
        {{ __('navigation.favourites.title') }}
    </span>
    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

    <ul class="space-y-1" aria-labelledby="nav-favourites-title" :class="sidebarCollapsed && !open ? 'md:flex md:flex-col md:items-center' : ''">
        @foreach ($favourites as $favourite)
            @php($active = $favourite['id'] === $currentPage)
            @php($icon = match (true) {
                $favourite['icon'] === 'inbox' => 'notification',
                $favourite['icon'] !== null && view()->exists('components.icons.'.$favourite['icon']) => $favourite['icon'],
                default => 'layers',
            })
            <li wire:key="favourite-{{ $favourite['id'] }}" class="relative w-full group/item"
                :draggable="(!sidebarCollapsed || open).toString()"
                :class="dragging === @js($favourite['id']) ? 'opacity-60' : ''"
                x-on:dragstart="dragging = @js($favourite['id']); draggingLabel = @js($favourite['label']); $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', dragging)"
                x-on:dragend="dragging = null"
                x-on:dragover.prevent
                x-on:drop.prevent="if (dragging && dragging !== @js($favourite['id'])) move(dragging, placeOf($el), draggingLabel); dragging = null"
                data-favourite="{{ $favourite['id'] }}">
                <a href="{{ route($favourite['route']) }}" wire:navigate draggable="false"
                   title="{{ $favourite['label'] }}" aria-label="{{ $favourite['label'] }}"
                   @if ($active) aria-current="page" @endif
                   data-nav-favourite="{{ $favourite['id'] }}"
                   class="nav-press group relative flex items-center w-full min-h-11 ps-3 pe-3 group-hover/item:pe-24 group-focus-within/item:pe-24 [@media(hover:none)]:pe-24 py-2 rounded-control font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus
                          {{ $active ? 'text-ink font-semibold bg-hover before:absolute before:inset-y-2 before:start-0 before:w-[3px] before:rounded-full before:bg-primary' : 'text-ink-2 hover:text-ink hover:bg-hover' }}"
                   :class="sidebarCollapsed && !open ? 'md:justify-center md:size-11 md:!px-0 md:mx-auto' : ''">
                    <span class="flex items-center justify-center size-5 shrink-0" aria-hidden="true">
                        <x-dynamic-component :component="'icons.'.$icon" class="size-4" />
                    </span>
                    <span class="text-body flex-1 ms-2 text-start whitespace-nowrap overflow-hidden text-ellipsis" x-show="!sidebarCollapsed || open">{{ $favourite['label'] }}</span>
                </a>

                {{-- Keyboard reorder: up / down arrows move the page one place. --}}
                <button type="button" x-show="!sidebarCollapsed || open"
                        data-favourite-handle="{{ $favourite['id'] }}"
                        aria-label="{{ __('navigation.favourites.move', ['page' => $favourite['label']]) }}"
                        aria-describedby="nav-favourites-title"
                        x-on:keydown.arrow-up.prevent="move(@js($favourite['id']), placeOf($el) - 1, @js($favourite['label']))"
                        x-on:keydown.arrow-down.prevent="move(@js($favourite['id']), placeOf($el) + 1, @js($favourite['label']))"
                        class="absolute end-11 top-1/2 z-10 grid size-11 -translate-y-1/2 cursor-grab place-items-center rounded-control text-ink-3 hover:text-ink
                               opacity-0 transition-opacity group-hover/item:opacity-100 group-focus-within/item:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <x-icons.grip class="size-4" />
                </button>

                <x-layouts.app.sidebar.favourite-star :id="$favourite['id']" :label="$favourite['label']" :starred="true" :max="$maxFavourites" />
            </li>
        @endforeach
    </ul>
</li>
