{{--
    "Work inbox" tile (DecisionsCard): what waits on the viewer. First the
    decisions, oldest first: one card at a time (arrows, arrow keys or a
    swipe; the counter says where you are), so the oldest is in front;
    "Show all" lays them side by side instead (kept on this device). Then the
    rest of the inbox (items to hand over or take back) as a short list. It
    took over "For you now", so the home has one place for the inbox. It
    scrolls inside a small tile. Nothing: one quiet line, marked
    data-tile-empty so the grid closes it at the bottom. No pay, no leave
    reason (WaitingOnYou::card).
--}}
@php
    $locale = app()->getLocale();
    $date = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMM') : '';
    $range = fn (?string $from, ?string $to) => $from === $to ? $date($from) : $date($from).' – '.$date($to);
    $cards = $decisions['cards'];
    $items = $decisions['items'] ?? [];
    $n = count($cards);
@endphp
<div class="hc-decide-tile flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm"
     data-fit-rows="{{ $items === [] ? 3 : 4 }}">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif (($decisions['all'] ?? 0) === 0)
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.decide') }}</h2>
            <x-ic name="inbox" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>
        <p class="hc-nt-empty" data-tile-empty>{{ __('dashboard.decide-tile.none') }}</p>
    @else
        <section class="hc-decide" aria-labelledby="hc-decide-title" data-testid="work-inbox-tile"
                 x-data="{
                    i: 0, n: {{ $n }}, all: false, x0: null,
                    init() { try { this.all = localStorage.getItem('hc-decide') === 'all' } catch (e) {} },
                    show(k) { this.i = (k + this.n) % this.n },
                    step(d) { this.show(this.i + (getComputedStyle(this.$el).direction === 'rtl' ? -d : d)) },
                    toggle() { this.all = ! this.all; try { localStorage.setItem('hc-decide', this.all ? 'all' : 'one') } catch (e) {} },
                    swipe(x) { if (this.x0 !== null && Math.abs(x - this.x0) > 40) this.step(x < this.x0 ? 1 : -1); this.x0 = null },
                 }"
                 x-on:keydown.arrow-right="if (! all && n > 1) step(1)"
                 x-on:keydown.arrow-left="if (! all && n > 1) step(-1)">
            <header class="hc-decide-head">
                <h2 id="hc-decide-title">
                    <x-ic name="inbox" aria-hidden="true" />
                    <span>{{ __('dashboard.tiles.decide') }}</span>
                    {{-- A count only helps from two. --}}
                    <span @class(['num hc-decide-badge', 'sr-only' => $decisions['all'] <= 1]) data-testid="work-inbox-count">{{ $decisions['all'] }}</span>
                </h2>
                <x-button variant="link" size="sm" :href="$inboxUrl" class="hc-decide-open">{{ __('dashboard.decide-tile.open') }}</x-button>
            </header>

            @if ($n > 0)
                <div data-testid="decision-card" aria-roledescription="carousel" aria-label="{{ trans_choice('dashboard.header.decide.title', $decisions['total'], ['count' => $decisions['total']]) }}">
                    {{-- One decision needs no heading of its own: the card says it. --}}
                    <div @class(['hc-decide-sub', 'sr-only' => $n <= 1])>
                        <h3>{{ trans_choice('dashboard.header.decide.title', $decisions['total'], ['count' => $decisions['total']]) }}</h3>
                        @if ($n > 1)
                            <div class="hc-decide-nav">
                                <span class="hc-decide-pager" x-show="! all">
                                    <button type="button" class="hc-decide-arrow" x-on:click="show(i - 1)" aria-label="{{ __('dashboard.header.decide.prev') }}">
                                        <x-ic name="chevron" class="rotate-180 rtl:rotate-0" aria-hidden="true" />
                                    </button>
                                    <span class="num hc-decide-pos" aria-live="polite"><span x-text="i + 1">1</span> / {{ $n }}</span>
                                    <button type="button" class="hc-decide-arrow" x-on:click="show(i + 1)" aria-label="{{ __('dashboard.header.decide.next') }}">
                                        <x-ic name="chevron" class="rtl:rotate-180" aria-hidden="true" />
                                    </button>
                                </span>
                                <button type="button" class="hc-decide-toggle" x-on:click="toggle()" x-bind:aria-pressed="all.toString()">
                                    <x-ic name="layout" aria-hidden="true" />
                                    <span x-text="all ? @js(__('dashboard.header.decide.one')) : @js(__('dashboard.header.decide.all'))">{{ __('dashboard.header.decide.all') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="hc-decide-track" :class="{ all }"
                         x-on:touchstart.passive="x0 = $event.touches[0].clientX"
                         x-on:touchend="if (! all && n > 1) swipe($event.changedTouches[0].clientX)">
                        @foreach ($cards as $card)
                            <article @class(['hc-dcard', 'old' => $card['old']]) wire:key="decide-{{ $card['key'] }}" data-decision="{{ $card['key'] }}"
                                     :class="{ off: ! (all || i === {{ $loop->index }}) }" @unless ($loop->first) style="display:none" x-init="$el.style.removeProperty('display')" @endunless
                                     aria-roledescription="slide" aria-label="{{ $loop->iteration }} / {{ $n }}">
                                <div class="hc-dcard-top">
                                    <span class="hc-dcard-what"><x-ic :name="$card['action'] === 'approve' ? 'user' : 'calendar'" aria-hidden="true" />{{ __('inbox.action.'.$card['action']) }}</span>
                                    @if ($card['start_flag'])
                                        <x-tag :variant="$card['start_flag'] === 'started' ? 'late' : 'rush'">{{ __($card['start_flag'] === 'started' ? 'inbox.started' : 'inbox.urgent') }}</x-tag>
                                    @endif
                                </div>
                                <h4 class="hc-dcard-name" dir="auto">{{ $card['name'] }}</h4>
                                @if ($card['what'] || $card['from'] || $card['city'])
                                    <ul class="hc-dcard-meta">
                                        @if ($card['what'])<li dir="auto">{{ $card['what'] }}</li>@endif
                                        @if ($card['from'])<li class="num">{{ $range($card['from'], $card['to']) }}</li>@endif
                                        @if ($card['city'])<li dir="auto">{{ $card['city'] }}</li>@endif
                                    </ul>
                                @endif
                                <div class="hc-dcard-foot">
                                    @if ($card['since'])
                                        <span class="hc-dcard-since"><x-ic name="clock" aria-hidden="true" />{{ __('dashboard.header.decide.since', ['time' => $card['since']]) }}</span>
                                    @endif
                                    {{-- A red square with an arrow; on hover or focus it opens to say what it does (hc-btn--reveal). --}}
                                    <x-button size="sm" :href="$card['url']" class="hc-dcard-go hc-btn--reveal">
                                        <x-ic name="arrow" class="rtl:-scale-x-100" aria-hidden="true" /><span class="hc-reveal-label">{{ __('dashboard.header.decide.verb.'.$card['action']) }}</span>
                                    </x-button>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if ($decisions['total'] > $n)
                        <p class="hc-decide-more">
                            <x-button variant="link" size="sm" :href="route('inbox', ['show' => 'decide'])">{{ trans_choice('dashboard.header.decide.more', $decisions['total'] - $n, ['count' => $decisions['total'] - $n]) }}</x-button>
                        </p>
                    @endif
                </div>
            @endif

            @if ($items !== [])
                <div class="hc-inbox-items" data-testid="work-inbox-items">
                    <h3>{{ trans_choice('dashboard.decide-tile.items', $decisions['others'], ['count' => $decisions['others']]) }}</h3>
                    <ul>
                        @foreach ($items as $item)
                            <li wire:key="inbox-item-{{ $item['key'] }}">
                                <a href="{{ $item['url'] }}" wire:navigate>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink" dir="auto">{{ $item['name'] }}</span>
                                        <span class="block truncate text-caption text-ink-2">{{ $item['label'] ?? __('inbox.action.'.$item['action']) }}@if ($item['what']) · <span dir="auto">{{ $item['what'] }}</span>@endif</span>
                                    </span>
                                    <x-ic name="chevron" class="hc-inbox-go rtl:rotate-180" aria-hidden="true" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($decisions['others'] > count($items))
                        <x-button variant="link" size="sm" :href="route('inbox', ['show' => 'checklist'])">{{ trans_choice('dashboard.header.decide.more', $decisions['others'] - count($items), ['count' => $decisions['others'] - count($items)]) }}</x-button>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>
