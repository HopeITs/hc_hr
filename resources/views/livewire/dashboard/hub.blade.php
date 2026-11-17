{{--
    The dashboard's six groups (Dashboard\Hub, HubGroups). One click filters the grid
    to the group's own cards (DashboardLayout::groupTiles, home-screen.js `group`) and
    opens a strip under the groups with its figures on one side and its pages on the
    other; the strip pushes the grid down rather than covering it. The same group
    again, or "Show all cards", shows every card. The strip's button opens the page,
    and so does a double-click on a computer. The links are drawn at once; only the
    figures wait on the server, when a strip first opens. Only the view changes.
--}}
<nav class="mb-3" aria-label="{{ __('dashboard.hub.label') }}" data-testid="dashboard-hub"
     x-data="{
        current: [],
        {{-- The Categories menu's state lives here, on the component's root: a nested x-data is
             re-made by each Livewire update, which closed the menu while picking. --}}
        open: false,
        labels: @js(array_column($groups, 'label', 'id')),
        on(id) {
            return this.current.includes(id);
        },
        toggle(id) {
            this.current = this.on(id) ? this.current.filter((g) => g !== id) : [...this.current, id];
            if (this.on(id)) this.$wire.load(id);
            this.tell();
        },
        clear() {
            this.current = [];
            this.tell();
        },
        tell() {
            window.dispatchEvent(new CustomEvent('hc-hub-filter', { detail: { groups: [...this.current] } }));
        },
        go(href) {
            Livewire.navigate(href);
        },
     }"
     x-on:hc-hub-cleared.window="current = []">
    <div class="flex flex-wrap items-stretch gap-2">
        {{-- My Layout: the viewer's own cards, unfiltered. Pressed while no category filters the grid;
             a click clears the ones that do. --}}
        <button type="button" data-hub="my-layout"
                x-on:click="clear()"
                :aria-pressed="(current.length === 0).toString()"
                :class="current.length === 0 ? 'border-ink bg-hover' : 'border-line-soft bg-card hover:bg-hover'"
                class="flex h-8 items-center gap-2 rounded-full border px-3 text-start font-semibold text-ink">
            <x-icons.dashboard class="size-5 shrink-0 text-ink-2" />
            <span class="truncate text-body">{{ __('dashboard.hub.my-layout') }}</span>
        </button>

        {{-- Categories: the groups in one menu, several at once. The grid shows the cards of any
             category picked, each opens its strip, and the button names one or counts several.
             The menu stays open while picking. --}}
        @if ($groups !== [])
            <div class="relative"
                 x-on:click.outside="open = false"
                 x-on:keydown.escape.stop="open = false; $refs.categories.focus()">
                <button type="button" x-ref="categories" data-testid="dashboard-categories"
                        x-on:click="open = ! open" aria-haspopup="menu" aria-controls="hc-categories-menu"
                        :aria-expanded="open.toString()"
                        :class="current.length ? 'border-ink bg-hover' : 'border-line-soft bg-card hover:bg-hover'"
                        class="flex h-8 items-center gap-2 rounded-full border px-3 text-start font-semibold text-ink">
                    <span class="truncate text-body">
                        {{ __('dashboard.hub.categories') }}<span x-show="current.length" x-cloak x-text="current.length === 1 ? ': ' + labels[current[0]] : ' (' + current.length + ')'"></span>
                    </span>
                    <x-ic name="chevron" class="size-4 shrink-0 text-ink-2 transition-transform" x-bind:class="open ? '-rotate-90' : 'rotate-90'" />
                </button>

                <div id="hc-categories-menu" role="menu" x-show="open" x-cloak
                     aria-label="{{ __('dashboard.hub.categories') }}"
                     class="absolute start-0 top-full z-30 mt-2 w-[min(16rem,calc(100vw-2rem))] rounded-surface border border-line bg-card p-1.5 shadow-e3">
                    @foreach ($groups as $group)
                        <button type="button" role="menuitemcheckbox" data-hub="{{ $group['id'] }}"
                                x-on:click="toggle(@js($group['id']))"
                                x-on:dblclick.prevent="go(@js($group['href']))"
                                :aria-checked="on(@js($group['id'])).toString()"
                                aria-controls="hub-card-{{ $group['id'] }}"
                                :class="on(@js($group['id'])) && 'bg-hover'"
                                class="flex min-h-11 w-full items-center gap-2 rounded-control px-3 text-start text-small font-semibold text-ink hover:bg-hover">
                            <x-dynamic-component :component="'icons.'.$group['icon']" class="size-5 shrink-0 text-ink-2" />
                            <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                            <span class="flex shrink-0" x-show="on(@js($group['id']))" x-cloak><x-ic name="check" class="size-4 text-ink" /></span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @foreach ($groups as $group)
        <section id="hub-card-{{ $group['id'] }}" wire:key="hub-card-{{ $group['id'] }}" data-hub-card="{{ $group['id'] }}"
                 x-show="on(@js($group['id']))" x-cloak
                 aria-label="{{ $group['label'] }}"
                 class="mt-2 rounded-surface border border-line-soft bg-card p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                {{-- The preview: the group's own figures. --}}
                <div class="min-w-0">
                    <h2 class="mb-2 text-body font-bold text-ink">{{ $group['label'] }}</h2>
                    @if ($group['id'] === 'settings')
                        <p class="text-small text-ink-2">{{ __('dashboard.hub.no-figures') }}</p>
                    @elseif (array_key_exists($group['id'], $figures))
                        @if ($figures[$group['id']] === null)
                            <p class="text-small text-ink-2" role="alert">{{ __('dashboard.hub.failed') }}</p>
                        @elseif ($figures[$group['id']] === [])
                            <p class="text-small text-ink-2">{{ __('dashboard.hub.no-figures') }}</p>
                        @else
                            <dl class="grid grid-cols-2 gap-2">
                                @foreach ($figures[$group['id']] as $figure)
                                    <div class="rounded-control bg-soft px-3 py-2">
                                        <dd class="num text-h4 font-extrabold leading-tight text-ink">{{ $figure['value'] }}</dd>
                                        <dt class="text-caption text-ink-2">{{ __('dashboard.hub.figures.'.$figure['label']) }}</dt>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    @else
                        <div class="grid grid-cols-2 gap-2" aria-busy="true">
                            <div class="h-14 animate-pulse rounded-control bg-soft"></div>
                            <div class="h-14 animate-pulse rounded-control bg-soft"></div>
                        </div>
                    @endif
                </div>

                {{-- The pages. --}}
                <div class="min-w-0 sm:border-s sm:border-line-soft sm:ps-4">
                    <h3 class="mb-1 text-caption font-semibold text-ink-3">{{ __('dashboard.hub.pages') }}</h3>
                    <ul class="max-h-56 space-y-0.5 overflow-y-auto" role="list">
                        @foreach ($group['pages'] as $page)
                            <li>
                                <a href="{{ $page['href'] }}" wire:navigate data-hub-page="{{ $page['id'] }}"
                                   class="flex min-h-9 items-center rounded-control px-2 text-small font-medium text-ink hover:bg-hover">{{ $page['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-end gap-2 border-t border-line-soft pt-3">
                <x-button variant="quiet" size="sm" icon="x" :loading="false" data-hub-clear
                          x-on:click="clear()">{{ __('dashboard.hub.show-all') }}</x-button>
                <x-button size="sm" :href="$group['href']" :loading="false">{{ __('dashboard.hub.open-page', ['page' => $group['page']]) }}</x-button>
            </div>
        </section>
    @endforeach
</nav>
