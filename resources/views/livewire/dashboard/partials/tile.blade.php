{{--
    One panel of the desk (index.blade.php), like hc-tracking's Desk panel.
    The tile is the grid cell: its size comes from the layout (C×R cells), and
    it is the size container "tile" for the card inside
    (resources/css/dashboard.css). The card is its Livewire component
    (DashboardLayout::COMPONENTS), mounted lazily: the card placeholder until
    it comes into view and its data arrives.

    On hover (or keyboard focus) the panel's tools show in its top inline-end
    corner: open in full (when the role's menu has its page) and the options
    menu (move, the clock's look, remove). The whole panel is carried by a
    press and move (mouse) or a press and hold (touch), and resized by its
    edges (home-screen.js).

    data-tour stays on the tile, so the product tour finds a card before it has
    loaded and wherever it has been moved.
--}}
@php
    $key = $item['key'];
    $label = $tiles->label($key);
    [$cols, $rows] = array_map('intval', explode('x', $item['size']));
    $k = \Illuminate\Support\Js::from($key);
    $looks = \App\Support\Dashboard\DashboardLayout::LOOKS[$key] ?? null;
    $page = $pages[$key] ?? null;
@endphp

<div class="hc-tile"
     id="hc-tile-{{ $key }}"
     role="listitem"
     aria-label="{{ $label }}"
     data-key="{{ $key }}"
     data-tour="dashboard.{{ $key }}"
     style="--c: {{ $cols }}; --r: {{ $rows }};"
     :style="span({{ $k }})"
     :class="{ resizing: resizing === {{ $k }} }"
     :data-cols="shown({{ $k }})[0]"
     :data-rows="shown({{ $k }})[1]"
     :data-look="styleOf({{ $k }}) || null"
     x-show="shownHere({{ $k }})"
     x-data="{ menu: false }"
     x-effect="if (carrying || resizing) menu = false"
     x-on:keydown.escape="menu = false"
     wire:key="tile-{{ $key }}">

    <div class="hc-ttools" :class="{ open: menu }">
        @if ($page)
            <a href="{{ $page }}" wire:navigate class="hc-tbtn" title="{{ __('dashboard.home.open-full') }}"
               aria-label="{{ __('dashboard.home.open-full-tile', ['tile' => $label]) }}">
                <x-ic name="expand" />
            </a>
        @endif
        <span class="hc-tmenu" x-on:click.outside="menu = false">
            <button type="button" class="hc-tbtn" x-on:click="menu = ! menu" :aria-expanded="menu.toString()"
                    aria-haspopup="menu" aria-label="{{ __('dashboard.home.options', ['tile' => $label]) }}">
                <x-ic name="more" />
            </button>
            <div class="hc-pop" role="menu" x-show="menu" x-cloak x-transition.opacity.duration.120ms>
                <button type="button" role="menuitem" x-show="canMove({{ $k }}, 'top')" x-on:click="menu = false; moveTo({{ $k }}, 'top')">
                    <x-ic name="arrow" class="-rotate-90" />{{ __('dashboard.home.move-top') }}
                </button>
                <button type="button" role="menuitem" x-show="canMove({{ $k }}, 'earlier')" x-on:click="menu = false; moveTo({{ $k }}, 'earlier')">
                    <x-ic name="chevron" class="rotate-180 rtl:rotate-0" />{{ __('dashboard.home.move-earlier') }}
                </button>
                <button type="button" role="menuitem" x-show="canMove({{ $k }}, 'later')" x-on:click="menu = false; moveTo({{ $k }}, 'later')">
                    <x-ic name="chevron" class="rtl:rotate-180" />{{ __('dashboard.home.move-later') }}
                </button>
                @if ($looks)
                    <button type="button" role="menuitem" x-on:click="menu = false; cycleStyle({{ $k }})">
                        <x-ic name="clock" />
                        @foreach ($looks as $look)
                            <span x-show="nextLook({{ $k }}) === @js($look)">{{ __('dashboard.home.looks.'.$look) }}</span>
                        @endforeach
                    </button>
                @endif
                <button type="button" role="menuitem" class="hc-pop-danger" x-on:click="menu = false; remove({{ $k }})">
                    <x-ic name="x" />{{ __('dashboard.home.remove') }}
                </button>
            </div>
        </span>
    </div>

    @if ($tiles->canResize($key))
        {{-- Pull an edge or the corner to resize (a computer). --}}
        @foreach (['end', 'start', 'top', 'bottom', 'corner'] as $edge)
            <span class="hc-rz hc-rz-{{ $edge }}" data-resize="{{ $edge }}" title="{{ __('dashboard.home.resize-hint') }}" aria-hidden="true"></span>
        @endforeach
        <span class="hc-sizebadge num" x-show="resizing === {{ $k }}" x-cloak x-text="resizeLabel" aria-hidden="true"></span>
    @endif

    <div class="hc-tile-body">
        @if ($key === 'my-leaves' && isset($failedSections['leaves']))
            <x-ui.load-error class="h-full min-h-0" />
        @else
            @livewire($tiles->component($key), ['lazy' => true], key('w-'.$key))
        @endif
    </div>
</div>
