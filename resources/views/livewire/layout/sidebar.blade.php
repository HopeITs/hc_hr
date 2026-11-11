@php
    // The star for a page that may be starred (Navigation::shortcuts leaves); null for the rest.
    $favouritesFull = count($favouriteIds) >= $maxFavourites;
    $star = fn (string $id): ?array => isset($favouritable[$id])
        ? ['id' => $id, 'starred' => in_array($id, $favouriteIds, true), 'full' => $favouritesFull, 'max' => $maxFavourites]
        : null;
@endphp
<div class="relative" x-data="{
    rtl: {{ app()->getLocale() === 'ar' ? 'true' : 'false' }},
    open: @entangle('open').live,
    {{-- Collapsed areas, per browser (localStorage). The area holding the current page ignores it.
         Until someone chooses, only Settings starts closed. --}}
    closedAreas: (() => { const rare = ['settings']; try { const stored = localStorage.getItem('nav-closed-areas'); return stored ? JSON.parse(stored) : rare; } catch (e) { return rare; } })(),
    isClosed(id) { return this.closedAreas.includes(id); },
    toggleArea(id) {
        this.closedAreas = this.isClosed(id) ? this.closedAreas.filter((area) => area !== id) : [...this.closedAreas, id];
        try { localStorage.setItem('nav-closed-areas', JSON.stringify(this.closedAreas)); } catch (e) {}
    },
}" x-cloak>
    <aside x-cloak data-tour="nav" @click.outside="open = false"
        class="sidebar-material fixed top-0 z-40 h-full pt-4 border-e border-line-soft shadow-e3 transition-[width,transform] duration-(--sidebar-duration) ease-sidebar md:translate-x-0"
        :class="[
            sidebarCollapsed ? 'md:w-20' : 'md:w-64',
            {{-- Drives the rail's own transitions (app.css "Collapsing the sidebar"). --}}
            sidebarCollapsed && !open ? 'sidebar-rail' : '',
            open ? 'w-64 translate-x-0' : 'w-64',
            !open && !rtl ? '-translate-x-full' : '',
            !open && rtl ? 'translate-x-full' : '',
        ]"
    >
        <button type="button"
                x-on:click="$dispatch('sidebar-collapse-toggle')"
                class="nav-press absolute z-50 items-center justify-center hidden bg-card border border-line-soft rounded-full shadow-e2 top-[54px] size-8 text-ink-2 hover:border-line hover:bg-hover hover:text-ink md:flex"
                :class="rtl ? '-left-4' : '-right-4'"
                :title="sidebarCollapsed ? @js(__('general.expand-sidebar')) : @js(__('general.collapse-sidebar'))"
                :aria-label="sidebarCollapsed ? @js(__('general.expand-sidebar')) : @js(__('general.collapse-sidebar'))">
            <svg class="transition-transform duration-(--sidebar-duration) ease-sidebar size-4"
                 :class="sidebarCollapsed ? (rtl ? 'rotate-0' : 'rotate-180') : (rtl ? 'rotate-180' : 'rotate-0')"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/>
            </svg>
        </button>

        <div class="flex flex-col h-full">
            {{-- Fixed header: logo stays put; scrolling begins below the divider --}}
            {{-- The full logo and the rail icon cross-fade in place; the narrowing aside clips the logo. --}}
            <a href="{{ route('dashboard') }}" wire:navigate class="relative flex items-center justify-start overflow-hidden ps-6 h-[70px] -mt-4 shrink-0 border-b border-line-soft">
                <x-app-logo class="nav-fade w-[8.25rem] max-w-none h-auto object-fit" />
                <img src="{{ asset('assets/img/hope-center-icon.svg') }}"
                     alt="" aria-hidden="true"
                     class="nav-fade-in absolute start-6 top-1/2 hidden -translate-y-1/2 object-contain size-8 md:block" />
            </a>

            {{-- C1: which role the menu below belongs to. A user with several roles
                 switches it in the avatar menu; the whole menu follows the active role. --}}
            <div class="nav-fold" data-testid="nav-active-role">
                <div class="whitespace-nowrap px-6 pt-3 text-caption text-ink-2">
                    {{ __('general.nav.acting-as') }}: <span class="font-semibold text-ink">{{ role_label($user->myRole()) }}</span>
                </div>
            </div>

            {{-- Scrollable navigation area. Structure and visibility: App\Support\Navigation (C0 §4). --}}
            <div class="nav-scroll flex-1 min-h-0 px-3 pt-3 pb-4 overflow-x-hidden overflow-y-auto">
                <ul class="space-y-1 font-medium">

                    @if ($favourites !== [])
                        @include('livewire.layout.partials.nav-favourites')
                    @endif

                    @foreach ($nav['areas'] as $area)
                        @php($areaKey = $area['id'])
                        @php($holdsCurrent = $areaKey === $currentArea)
                        {{-- The icon rail keeps My space and favourites; the rest is on the dashboard's groups, or one toggle away. --}}
                        {{-- Areas the rail leaves out fold away (.nav-fold, app.css) rather than vanish, so My space below them glides up. --}}
                        <li @class(['w-full', 'nav-fold' => $areaKey !== 'my-space']) data-tour="nav.area.{{ $areaKey }}" data-nav-area="{{ $areaKey }}">
                          <div>
                            {{-- The heading folds too: My space keeps its icons in the rail, not its title. --}}
                            <div class="nav-fold"><div class="pt-2">
                            @if ($holdsCurrent)
                                {{-- The area holding the current page is always open, so it has no toggle. --}}
                                <span class="block whitespace-nowrap px-3 py-[3px] text-caption font-semibold uppercase tracking-wider text-ink-3">{{ $area['label'] }}</span>
                            @else
                                <button type="button"
                                        x-on:click="toggleArea(@js($areaKey))"
                                        :aria-expanded="(!isClosed(@js($areaKey))).toString()"
                                        aria-controls="nav-area-{{ $areaKey }}"
                                        data-nav-area-toggle="{{ $areaKey }}"
                                        class="flex w-full min-h-11 md:min-h-8 items-center justify-between gap-2 whitespace-nowrap rounded-control px-3 text-caption font-semibold uppercase tracking-wider text-ink-3 hover:text-ink hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                    <span>{{ $area['label'] }}</span>
                                    <svg class="size-3 shrink-0 transition-transform motion-reduce:transition-none" aria-hidden="true"
                                         :class="isClosed(@js($areaKey)) ? 'ltr:-rotate-90 rtl:rotate-90' : ''"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/>
                                    </svg>
                                </button>
                            @endif
                            </div></div>
                            {{-- In the collapsed icon rail there are no headings to reopen an area from, so My space shows every icon.
                                 The other areas are folded away in the rail: a closed one stays closed, or it would
                                 grow just as it starts to fold. --}}
                            <ul id="nav-area-{{ $areaKey }}" class="space-y-1"
                                @unless ($holdsCurrent) x-show="!isClosed(@js($areaKey)){{ $areaKey === 'my-space' ? ' || (sidebarCollapsed && !open)' : '' }}" @endunless>
                                @include('livewire.layout.partials.nav-items', ['items' => $area['items']])
                            </ul>
                          </div>
                        </li>
                    @endforeach

                </ul>
            </div>
        </div>
    </aside>
    {{-- Dim to focus: the drawer is a modal layer on mobile, so the page behind
         it is both dimmed and pushed out of focus. --}}
    <div x-show="open" x-transition.opacity @click="open = false"
        class="fixed inset-0 z-30 bg-backdrop backdrop-blur-sm md:hidden" x-cloak></div>
</div>
