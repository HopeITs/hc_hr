{{--
    One tile of the dashboard grid (index.blade.php). The tile is the grid
    cell: its size comes from the layout (C×R cells), and it is the size
    container "tile" for whatever is drawn inside (resources/css/dashboard.css).
    A widget is its Livewire component (DashboardLayout::COMPONENTS), mounted
    lazily: the card placeholder until it comes into view and its data
    arrives. A shortcut is a link to a page of the role's menu.

    Ported from media-request (2026-10-29), see home-screen.js:
     - nothing in it right now: the tile is not shown; Customise shows it whole;
     - made small: an icon (.hc-wmini) that opens the card in a side panel. The
       panel is the tile body itself (popover="manual"), so the card is never
       moved or mounted twice. While the tile is an icon its body is not
       displayed, so its lazy card does not load until the panel opens;
     - a shortcut is a link: one click opens its page;
     - a card is also a window (2026-11-02): minimise sends it to the strip
       under the grid, maximise opens it large in the panel, and the grip
       resizes it cell by cell without Customise (shown on hover or focus).

    data-tour stays on the tile, so the product tour finds a card before it has
    loaded and wherever it has been moved.
--}}
@php
    $key = $item['key'];
    $isApp = $tiles->isApp($key);
    $label = $tiles->label($key, $user);
    [$cols, $rows] = array_map('intval', explode('x', $item['size']));
    $k = \Illuminate\Support\Js::from($key);
    $looks = \App\Support\Dashboard\DashboardLayout::LOOKS[$key] ?? null;
    $inboxKey = \App\Support\Dashboard\DashboardLayout::APP_PREFIX.'nav.inbox';
    $page = $isApp ? null : ($pages[$key] ?? null);
    $hasCard = $tiles->hasCard($key);
    $panelId = 'hc-panel-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $key);
    $titleId = $panelId.'-title';
@endphp

<div @class(['hc-tile', 'is-app' => $isApp, 'is-widget' => ! $isApp, 'pinned' => $item['pinned']])
     role="listitem"
     data-key="{{ $key }}"
     @unless ($isApp) data-tour="dashboard.{{ $key }}" @endunless
     @if ($page) data-page="{{ $page }}" @endif
     style="--c: {{ $cols }}; --r: {{ $rows }};"
     :style="span({{ $k }})"
     :class="{ pinned: pinned({{ $k }}), resizing: resizing === {{ $k }}, out: outKey === {{ $k }}, mini: mini({{ $k }}), closed: closed({{ $k }}), parked: parked({{ $k }}), peek: peek === {{ $k }} }"
     :data-cols="shown({{ $k }})[0]"
     :data-rows="shown({{ $k }})[1]"
     :data-look="styleOf({{ $k }}) || null"
     x-show="shownHere({{ $k }}) && ! closed({{ $k }})"
     :tabindex="editing ? 0 : null"
     :aria-label="editing ? tileLabel({{ $k }}) : null"
     :aria-describedby="editing ? 'hc-tile-hint' : null"
     x-on:keydown="if (editing && $event.target === $el) moveKey($event, {{ $k }})"
     wire:key="tile-{{ $key }}">

    {{-- Edit controls: hide outside the top start corner (like a phone); pin, look and resize inside the tile. --}}
    <button type="button" class="hc-tbtn hc-t-remove" x-show="editing" x-cloak
            x-on:click="remove({{ $k }})" aria-label="{{ __('dashboard.home.hide', ['tile' => $label]) }}">
        <x-ic name="x" />
    </button>

    @unless ($isApp)
        <button type="button" class="hc-tbtn hc-t-pin" x-show="editing" x-cloak
                x-on:click="togglePin({{ $k }})" :aria-pressed="pinned({{ $k }})"
                aria-label="{{ __('dashboard.home.pin', ['tile' => $label]) }}">
            <x-ic name="pin" />
        </button>

        @if ($looks)
            <button type="button" class="hc-tbtn hc-t-look" x-show="editing" x-cloak
                    x-on:click="cycleStyle({{ $k }})" aria-label="{{ __('dashboard.home.look', ['tile' => $label]) }}">
                <x-ic name="clock" />
                @foreach ($looks as $look)
                    <span x-show="nextLook({{ $k }}) === @js($look)">{{ __('dashboard.home.looks.'.$look) }}</span>
                @endforeach
            </button>
        @endif

        {{-- Made small: an icon. One click opens the card in the side panel, two its page. --}}
        <button type="button" class="hc-wmini" :inert="editing"
                x-on:click="peekSoon($event, {{ $k }})" x-on:dblclick.stop="miniPage({{ $k }})"
                aria-haspopup="dialog" aria-controls="{{ $panelId }}" :aria-expanded="(peek === {{ $k }}).toString()"
                @if ($page) aria-describedby="hc-page-hint" @endif>
            <span class="hc-appicon"><x-dashboard.tile-icon :name="$tiles->icon($key, $user)" /></span>
            <span class="hc-appname">{{ $label }}</span>
        </button>

        {{-- Nothing in it right now: one closed line (the card's own words when it has them). --}}
        <div class="hc-tile-line" aria-hidden="true">
            <span class="hc-tile-line-ico"><x-dashboard.tile-icon :name="$tiles->icon($key, $user)" /></span>
            <b class="hc-tile-line-name">{{ $label }}</b>
            <span class="hc-tile-line-text" x-text="closedText({{ $k }})"></span>
        </div>
    @endunless

{{-- Window controls at rest, on every card (a widget, a record, a page from the menu): pin (its size
     and place stay), minimise to the strip, maximise in the panel, out of the browser. --}}
    <div class="hc-wctl" x-show="! editing && ! mini({{ $k }})" x-cloak>
        <button type="button" class="hc-w-pin" x-on:click="togglePin({{ $k }})" :aria-pressed="pinned({{ $k }}).toString()"
                aria-label="{{ __('dashboard.home.pin', ['tile' => $label]) }}"
                :title="pinned({{ $k }}) ? @js(__('dashboard.home.unpin-short')) : @js(__('dashboard.home.pin-short'))">
            <x-ic name="pin" />
        </button>
        <button type="button" class="hc-w-min" x-on:click="minimise({{ $k }})"
                aria-label="{{ __('dashboard.home.minimise', ['tile' => $label]) }}" title="{{ __('dashboard.home.minimise-short') }}">
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8h9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
        </button>
    {{-- Not for the weather, date and time card: it has a fixed size, and nothing more to show large. --}}
    @unless ($key === 'glance')
        <button type="button" class="hc-w-max" x-on:click="maximise({{ $k }})"
                aria-haspopup="dialog" aria-controls="{{ $panelId }}" :aria-expanded="(peek === {{ $k }} && maxKey === {{ $k }}).toString()"
                aria-label="{{ __('dashboard.home.maximise', ['tile' => $label]) }}" title="{{ __('dashboard.home.maximise-short') }}">
            <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="1.8" /></svg>
        </button>
    @endunless
        {{-- Out of the browser: floating on top of every program (Chrome, Edge), or a window of its own. --}}
        <button type="button" class="hc-w-out" x-on:click="popOut({{ $k }})"
                :aria-haspopup="canFloat ? 'menu' : null" :aria-expanded="canFloat ? (outKey === {{ $k }}).toString() : null"
                aria-label="{{ __('dashboard.window.out', ['tile' => $label]) }}" title="{{ __('dashboard.window.out-short') }}">
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 2.5h4v4M13.5 2.5 8 8M11.5 9.5v3a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <div class="hc-wout" role="menu" x-show="outKey === {{ $k }}" x-cloak
             aria-label="{{ __('dashboard.window.out-short') }}"
             x-on:click.outside="if (outKey === {{ $k }}) outKey = null"
             x-on:keydown.escape.stop="outKey = null">
            <button type="button" role="menuitem" x-on:click="floatOnTop({{ $k }})" data-out="float">
                {{ __('dashboard.window.float') }}<span>{{ __('dashboard.window.float-hint') }}</span>
            </button>
            <button type="button" role="menuitem" x-on:click="openWindow({{ $k }})" data-out="window">
                {{ __('dashboard.window.new') }}<span>{{ __('dashboard.window.new-hint') }}</span>
            </button>
        </div>
    </div>


    @if ($tiles->canResize($key))
        {{-- The bottom inline-end grip is the one control (keyboard). A pointer finds the same
             thin silver corner inside each of the four corners, at rest (shown as it comes near)
             and while customising (always shown); dragging away from that corner grows the card. --}}
        <button type="button" class="hc-grip" data-corner="end-end" x-show="! pinned({{ $k }}) && (editing || ! closed({{ $k }}))" x-cloak
                x-on:pointerdown="resizeStart($event, {{ $k }}, 'end-end')"
                x-on:keydown="resizeKey($event, {{ $k }})"
                :aria-label="resizeLabelFor({{ $k }})"
                title="{{ __('dashboard.home.resize-hint') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4v6a9 9 0 0 1-9 9H4" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        @foreach (['start-start', 'start-end', 'end-start'] as $corner)
            <span class="hc-grip hc-grip-more" data-corner="{{ $corner }}" aria-hidden="true"
                  x-show="! pinned({{ $k }}) && (editing || ! closed({{ $k }}))" x-cloak
                  x-on:pointerdown="resizeStart($event, {{ $k }}, @js($corner))"
                  title="{{ __('dashboard.home.resize-hint') }}">
                <svg viewBox="0 0 24 24"><path d="M19 4v6a9 9 0 0 1-9 9H4" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </span>
        @endforeach
        <span class="hc-sizebadge num" x-show="resizing === {{ $k }}" x-cloak x-text="resizeLabel" aria-hidden="true"></span>
    @endif

    {{-- The card. For a widget it is also its side panel (a popover, a dialog only while open). --}}
    <div class="hc-tile-body" :inert="editing"
         @unless ($isApp)
             :class="{ 'is-max': maxKey === {{ $k }} }"
             id="{{ $panelId }}" popover="manual" tabindex="-1"
             :role="peek === {{ $k }} ? 'dialog' : null"
             :aria-modal="peek === {{ $k }} ? 'false' : null"
             :aria-label="peek === {{ $k }} ? @js($label) : null"
         @endunless>
        @unless ($isApp)
            <button type="button" class="hc-panel-close" x-on:click="closePeek()" aria-label="{{ __('dashboard.home.panel-close', ['tile' => $label]) }}">
                <x-ic name="x" />
            </button>
        @endunless

        @if ($isApp)
            @php($app = $apps[$key])
            @php($href = route($app['route'], $app['params']))
            {{-- With a card, the page is opened by the script (appClick): wire:navigate would follow the link as the button comes up. --}}
            <a class="hc-app" href="{{ $href }}"
               @if ($hasCard)
                   aria-haspopup="dialog" aria-controls="{{ $panelId }}" :aria-expanded="(peek === {{ $k }}).toString()"
                   aria-describedby="hc-page-hint" x-on:dblclick.prevent="miniPage({{ $k }})"
               @else
                   wire:navigate
               @endif>
                <div class="hc-appicon">
                    <x-dashboard.tile-icon :name="$app['icon']" />
                    @if ($key === $inboxKey && $inboxCount > 0)
                        <span class="hc-cnt num" aria-hidden="true">{{ $inboxCount }}</span>
                        <span class="sr-only">{{ __('general.nav.work-inbox-count', ['count' => $inboxCount]) }}</span>
                    @elseif ($app['counter'])
                        <div class="hc-cnt-slot">@livewire($app['counter'], key('cnt-'.$key))</div>
                    @endif
                </div>
                <span class="hc-appname">{{ $label }}</span>
            </a>

            @if ($hasCard)
                {{-- Made big, the shortcut is a small card with its data; the same card is its side panel. --}}
                <div class="hc-appcard" id="{{ $panelId }}" popover="manual" tabindex="-1"
                     :role="peek === {{ $k }} ? 'dialog' : null"
                     :aria-modal="peek === {{ $k }} ? 'false' : null"
                     aria-labelledby="{{ $titleId }}">
                    <button type="button" class="hc-panel-close" x-on:click="closePeek()" aria-label="{{ __('dashboard.home.panel-close', ['tile' => $label]) }}">
                        <x-ic name="x" />
                    </button>
                    <a class="hc-ach" href="{{ $href }}" wire:navigate id="{{ $titleId }}">
                        <span class="hc-appicon"><x-dashboard.tile-icon :name="$app['icon']" /></span>
                        <span class="hc-ach-name">{{ $label }}</span>
                        <x-ic name="arrow" class="hc-ach-go rtl:-scale-x-100" aria-hidden="true" />
                        <span class="sr-only">{{ __('dashboard.home.page-open-link') }}</span>
                    </a>
                    @livewire('dashboard.app-card', ['tile' => $key, 'lazy' => true], key('ac-'.$key))
                </div>
            @endif
        @elseif ($key === 'my-leaves' && isset($failedSections['leaves']))
            <x-ui.load-error class="h-full min-h-0" />
        @else
            @livewire($tiles->component($key), ['lazy' => true] + $tiles->componentParams($key), key('w-'.$key))
        @endif
    </div>
</div>
