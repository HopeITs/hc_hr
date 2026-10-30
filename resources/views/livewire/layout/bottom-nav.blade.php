{{--
    Phone bottom navigation (D2), app style (the media-request bar, 5814c31):

      [ place 1 ][ place 2 ]( + )[ place 3 ][ place 4 ][ More ]

    The four places are the person's shortcuts (App\Support\BottomBar), two on
    each side of the raised +. + opens the New sheet (Navigation::newActions:
    only what the role may start); More opens the More sheet (the role's whole
    menu). Both sheets are dialogs: aria-modal, focus trapped and given back
    (x-trap), Escape, backdrop, swipe down on the handle. Red only for its jobs:
    the + (the primary action), the current page (aria-current, ink text and a
    red bar, as in the sidebar) and the inbox count (what waits on the viewer). Flex follows
    the document direction, so the bar and the sheets mirror in Arabic.
    Hidden from md up, behind dialogs (z-30 < z-50), and, by app.css, while a
    field has focus or a page shows its own sticky save bar.
    Behaviour: App\Livewire\Layout\BottomNav.
--}}
@php
    $place = 'relative flex min-h-14 w-full flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-caption font-semibold leading-tight focus-visible:outline-2';
    $tile = 'flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-body font-semibold focus-visible:outline-2';
@endphp
<nav aria-label="{{ __('general.nav.bottom') }}" data-testid="bottom-nav"
     {{-- Also by focus events, not only the CSS :focus rule: the keyboard can
          be up while the document reports no focus (embedded views, iOS). --}}
     x-data="{
         typing: false,
         sheet: null,
         startY: null,
         isField: (el) => el?.matches?.('input:not([type=checkbox],[type=radio],[type=file],[type=button],[type=submit]), textarea, select, [contenteditable=true]'),
         open(name) { this.sheet = name },
         close() { this.sheet = null; this.startY = null },
         swipeStart(event) { this.startY = event.touches[0].clientY },
         swipeEnd(event) { if (this.startY !== null && event.changedTouches[0].clientY - this.startY > 70) this.close(); this.startY = null },
         {{-- As the header's New menu: a screen that sends Cancel back gets ?from=<this page>. --}}
         go(event) {
             const url = new URL(event.currentTarget.href);
             if (event.currentTarget.dataset.returns !== undefined) {
                 const here = new URLSearchParams(location.search);
                 here.delete('from');
                 const query = here.toString();
                 url.searchParams.set('from', location.pathname + (query ? '?' + query : ''));
             }
             this.close();
             Livewire.navigate(url.toString());
         },
     }"
     x-on:focusin.window="typing = sheet === null && isField($event.target)"
     x-on:focusout.window="typing = false"
     x-bind:data-typing="typing"
     class="app-bottom-nav fixed inset-x-0 bottom-0 z-30 border-t border-line-soft bg-base/95 backdrop-blur md:hidden"
     style="padding-bottom: env(safe-area-inset-bottom, 0px)">
    <ul class="mx-auto flex max-w-lg items-stretch justify-around">
        @foreach ([$before, $after] as $side => $places)
            @foreach ($places as $item)
                @php($active = in_array($currentRoute, $item['active'], true))
                <li class="min-w-0 flex-1" wire:key="bottom-{{ $item['key'] }}">
                    <a href="{{ $item['href'] }}" wire:navigate data-testid="bottom-nav-{{ $item['key'] }}"
                       @if ($active) aria-current="page" @endif
                       @class([
                           $place,
                           'text-ink' => $active,
                           'text-ink-2 hover:text-ink' => ! $active,
                       ])>
                        @if ($active)
                            <span class="absolute inset-x-4 top-0 h-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                        @endif
                        <span class="relative">
                            <x-dynamic-component :component="'icons.'.$item['icon']" class="size-6" aria-hidden="true" />
                            @if (($item['count'] ?? 0) > 0)
                                <span class="num absolute -end-2.5 -top-1.5 min-w-4 rounded-full bg-primary px-1 text-center text-caption leading-4 text-on-brand" data-testid="bottom-nav-count" aria-hidden="true">
                                    {{ $item['count'] > 99 ? '99+' : $item['count'] }}
                                </span>
                            @endif
                        </span>
                        <span class="max-w-full truncate">{{ $item['label'] }}</span>
                        @if (($item['count'] ?? 0) > 0)
                            <span class="sr-only">{{ __('general.nav.work-inbox-count', ['count' => $item['count']]) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach

            {{-- The raised + sits between the two halves (only when the role may start something). --}}
            @if ($side === 0 && $actions !== [])
                <li class="flex shrink-0 items-start justify-center px-1" wire:key="bottom-new">
                    <button type="button" data-testid="bottom-nav-new"
                            x-on:click="open('new')"
                            aria-haspopup="dialog" aria-controls="bottom-sheet-new" x-bind:aria-expanded="(sheet === 'new').toString()"
                            aria-label="{{ __('mobile.bar.new-label') }}"
                            class="-mt-5 grid size-14 place-items-center rounded-full bg-primary text-on-brand shadow-e3 ring-4 ring-base transition-transform hover:bg-primary-hover active:scale-95 focus-visible:outline-2 focus-visible:outline-offset-2">
                        <x-icons.plus class="size-7" stroke-width="2.25" aria-hidden="true" />
                    </button>
                </li>
            @endif
        @endforeach

        {{-- Always the last place: More, the role's whole menu in a sheet. --}}
        <li class="min-w-0 flex-1" wire:key="bottom-more">
            <button type="button" data-testid="bottom-nav-more"
                    x-on:click="open('more')"
                    aria-haspopup="dialog" aria-controls="bottom-sheet-more" x-bind:aria-expanded="(sheet === 'more').toString()"
                    aria-label="{{ __('mobile.bar.more-label') }}"
                    class="{{ $place }} text-ink-2 hover:text-ink">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <path d="M4 7h16M4 12h16M4 17h16" />
                </svg>
                <span class="max-w-full truncate">{{ __('mobile.bar.more') }}</span>
            </button>
        </li>
    </ul>

    {{-- The New sheet: the header's New menu, for a thumb. --}}
    @if ($actions !== [])
        <template x-teleport="body">
            <div x-show="sheet === 'new'" x-cloak
                 x-trap.inert.noscroll="sheet === 'new'"
                 x-on:keydown.escape.prevent.stop="close()"
                 class="fixed inset-0 z-50 flex items-end md:hidden" data-testid="bottom-sheet-new-root">
                <div class="absolute inset-0 bg-backdrop" x-on:click="close()" aria-hidden="true"
                     x-show="sheet === 'new'" x-transition.opacity></div>
                <div id="bottom-sheet-new" role="dialog" aria-modal="true" aria-labelledby="bottom-sheet-new-title"
                     data-testid="bottom-sheet-new"
                     x-show="sheet === 'new'"
                     x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                     x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                     class="relative max-h-[85dvh] w-full overflow-y-auto rounded-t-[22px] bg-card px-4 pt-2 shadow-e3"
                     style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))">
                    <div class="flex justify-center py-2" x-on:touchstart.passive="swipeStart($event)" x-on:touchend="swipeEnd($event)" aria-hidden="true">
                        <span class="h-1.5 w-10 rounded-full bg-line"></span>
                    </div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h2 id="bottom-sheet-new-title" class="text-h4 font-extrabold text-ink">{{ __('mobile.bar.new-title') }}</h2>
                        <button type="button" x-on:click="close()" aria-label="{{ __('mobile.bar.close') }}"
                                class="grid size-11 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2">
                            <x-icons.x-mark class="size-5" aria-hidden="true" />
                        </button>
                    </div>
                    <ul class="grid gap-1">
                        @foreach ($actions as $action)
                            <li wire:key="bottom-new-{{ $action['id'] }}">
                                <a href="{{ route($action['route'], $action['params']) }}" data-new="{{ $action['id'] }}" @if ($action['returns']) data-returns @endif
                                   x-on:click.prevent="go($event)"
                                   class="{{ $tile }} min-h-12 text-ink hover:bg-hover">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-control border border-line-soft bg-soft text-ink" aria-hidden="true">
                                        <x-icons.plus class="size-5" />
                                    </span>
                                    <span>{{ $action['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </template>
    @endif

    {{-- The More sheet: everything the role's menu has, by area. --}}
    <template x-teleport="body">
        <div x-show="sheet === 'more'" x-cloak
             x-trap.inert.noscroll="sheet === 'more'"
             x-on:keydown.escape.prevent.stop="close()"
             class="fixed inset-0 z-50 flex items-end md:hidden" data-testid="bottom-sheet-more-root">
            <div class="absolute inset-0 bg-backdrop" x-on:click="close()" aria-hidden="true"
                 x-show="sheet === 'more'" x-transition.opacity></div>
            <div id="bottom-sheet-more" role="dialog" aria-modal="true" aria-labelledby="bottom-sheet-more-title"
                 data-testid="bottom-sheet-more"
                 x-show="sheet === 'more'"
                 x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 class="relative max-h-[85dvh] w-full overflow-y-auto rounded-t-[22px] bg-card px-4 pt-2 shadow-e3"
                 style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))">
                <div class="flex justify-center py-2" x-on:touchstart.passive="swipeStart($event)" x-on:touchend="swipeEnd($event)" aria-hidden="true">
                    <span class="h-1.5 w-10 rounded-full bg-line"></span>
                </div>
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 id="bottom-sheet-more-title" class="text-h4 font-extrabold text-ink">{{ __('mobile.bar.more-title') }}</h2>
                    <button type="button" x-on:click="close()" aria-label="{{ __('mobile.bar.close') }}"
                            class="grid size-11 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2">
                        <x-icons.x-mark class="size-5" aria-hidden="true" />
                    </button>
                </div>

                @foreach ($sections as $section)
                    <section wire:key="bottom-more-{{ $section['id'] }}" data-section="{{ $section['id'] }}"
                             class="border-b border-line-soft py-2 last:border-b-0"
                             @if ($section['label']) aria-labelledby="bottom-more-{{ $section['id'] }}" @endif>
                        @if ($section['label'])
                            <h3 id="bottom-more-{{ $section['id'] }}" class="px-3 pb-1 pt-1 text-caption font-bold text-ink-3">{{ $section['label'] }}</h3>
                        @endif
                        <ul class="grid gap-0.5">
                            @foreach ($section['rows'] as $row)
                                @if (isset($row['heading']))
                                    <li class="px-3 pb-0.5 pt-2 text-small font-bold text-ink-2" aria-hidden="true">{{ $row['heading'] }}</li>
                                @else
                                    <li @class(['ps-4' => isset($row['group'])])>
                                        <a href="{{ $row['href'] }}" wire:navigate x-on:click="close()" data-testid="bottom-more-{{ $row['id'] }}"
                                           @if ($row['current']) aria-current="page" @endif
                                           @class([$tile, 'relative bg-soft text-ink before:absolute before:inset-y-2 before:start-0 before:w-[3px] before:rounded-full before:bg-primary' => $row['current'], 'text-ink hover:bg-hover' => ! $row['current']])>
                                            <x-dynamic-component :component="'icons.'.$row['icon']" class="size-5 shrink-0 text-ink-2" aria-hidden="true" />
                                            <span class="min-w-0 flex-1 truncate">
                                                @isset($row['group'])<span class="sr-only">{{ $row['group'] }}: </span>@endisset{{ $row['label'] }}
                                            </span>
                                            @if ($row['count'] > 0)
                                                <span class="num min-w-6 rounded-full bg-primary px-1.5 text-center text-caption leading-5 text-on-brand" aria-hidden="true">{{ $row['count'] > 99 ? '99+' : $row['count'] }}</span>
                                                <span class="sr-only">{{ __('general.nav.work-inbox-count', ['count' => $row['count']]) }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    </template>
</nav>
