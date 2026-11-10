{{--
    One tile of the dashboard grid (index.blade.php). The tile is the grid
    cell: its size comes from the layout (C×R cells), and it is the size
    container "tile" for whatever is drawn inside (resources/css/dashboard.css).
    A widget is its Livewire component (DashboardLayout::COMPONENTS), mounted
    lazily: the card placeholder until it comes into view and its data
    arrives. A shortcut is a link to a page of the role's menu.

    Ported from media-request (2026-10-29), see home-screen.js:
     - nothing in it right now: the tile is one closed line at the bottom
       (.hc-tile-line); Customise shows it whole;
     - made small: an icon (.hc-wmini) that opens the card in a side panel. The
       panel is the tile body itself (popover="manual"), so the card is never
       moved or mounted twice. While the tile is an icon its body is not
       displayed, so its lazy card does not load until the panel opens;
     - a widget with a page (data-page, only pages the role may open) opens it
       on double-click;
     - a shortcut made big shows its data (.hc-appcard, AppCard, lazy and
       hidden while the tile is an icon); on a computer one click shows that
       card in the panel, a double-click opens the page.

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
     @if ($page) data-page="{{ $page }}" x-on:dblclick="openPage($event, {{ $k }})" title="{{ __('dashboard.home.page-open') }}" @endif
     style="--c: {{ $cols }}; --r: {{ $rows }};"
     :style="span({{ $k }})"
     :class="{ pinned: pinned({{ $k }}), resizing: resizing === {{ $k }}, mini: mini({{ $k }}), closed: closed({{ $k }}), peek: peek === {{ $k }} }"
     :data-cols="shown({{ $k }})[0]"
     :data-rows="shown({{ $k }})[1]"
     :data-look="styleOf({{ $k }}) || null"
     x-show="shownHere({{ $k }})"
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

    @if ($tiles->canResize($key))
        <button type="button" class="hc-grip" x-show="editing" x-cloak
                x-on:pointerdown="resizeStart($event, {{ $k }})"
                x-on:keydown="resizeKey($event, {{ $k }})"
                :aria-label="resizeLabelFor({{ $k }})"
                title="{{ __('dashboard.home.resize-hint') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.5 8.5v9h-9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <span class="hc-sizebadge num" x-show="resizing === {{ $k }}" x-cloak x-text="resizeLabel" aria-hidden="true"></span>
    @endif

    {{-- The card. For a widget it is also its side panel (a popover, a dialog only while open). --}}
    <div class="hc-tile-body" :inert="editing"
         @unless ($isApp)
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
            @livewire($tiles->component($key), ['lazy' => true], key('w-'.$key))
        @endif
    </div>
</div>
