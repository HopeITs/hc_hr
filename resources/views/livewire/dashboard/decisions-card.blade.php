{{--
    "Decisions for you" tile (DecisionsCard): the decisions waiting on the
    viewer, oldest first. One card at a time with arrows and dots, so the
    oldest is in front; "Show all" lays them side by side instead (kept on
    this device). It scrolls inside a small tile. None: one quiet line,
    marked data-tile-empty so the grid closes it at the bottom. No pay, no
    leave reason (WaitingOnYou::card).
--}}
@php
    $locale = app()->getLocale();
    $date = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMM') : '';
    $range = fn (?string $from, ?string $to) => $from === $to ? $date($from) : $date($from).' – '.$date($to);
    $cards = $decisions['cards'];
    $n = count($cards);
@endphp
<div class="hc-decide-tile flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($n === 0)
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.decide') }}</h2>
            <x-ic name="alert" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>
        <p class="hc-nt-empty" data-tile-empty>{{ __('dashboard.decide-tile.none') }}</p>
    @else
        <section class="hc-decide" aria-labelledby="hc-decide-title" aria-roledescription="carousel" data-testid="decision-card"
                 x-data="{
                    i: 0, n: {{ $n }}, all: false,
                    init() { try { this.all = localStorage.getItem('hc-decide') === 'all' } catch (e) {} },
                    show(k) { this.i = (k + this.n) % this.n },
                    toggle() { this.all = ! this.all; try { localStorage.setItem('hc-decide', this.all ? 'all' : 'one') } catch (e) {} },
                 }"
                 x-on:keydown.arrow-right="if (! all && n > 1) show(i + (getComputedStyle($el).direction === 'rtl' ? -1 : 1))"
                 x-on:keydown.arrow-left="if (! all && n > 1) show(i - (getComputedStyle($el).direction === 'rtl' ? -1 : 1))">
            <div class="hc-decide-head">
                <h2 id="hc-decide-title"><x-ic name="alert" />{{ trans_choice('dashboard.header.decide.title', $decisions['total'], ['count' => $decisions['total']]) }}</h2>
                @if ($n > 1)
                    <span class="flex flex-wrap items-center gap-1">
                        <span class="flex items-center gap-1" x-show="! all">
                            <button type="button" class="hc-btn hc-btn--quiet hc-btn--sm hc-decide-arrow" x-on:click="show(i - 1)" aria-label="{{ __('dashboard.header.decide.prev') }}">
                                <x-ic name="chevron" class="rotate-180 rtl:rotate-0" />
                            </button>
                            <span class="num hc-decide-count" aria-live="polite"><span x-text="i + 1">1</span> / {{ $n }}</span>
                            <button type="button" class="hc-btn hc-btn--quiet hc-btn--sm hc-decide-arrow" x-on:click="show(i + 1)" aria-label="{{ __('dashboard.header.decide.next') }}">
                                <x-ic name="chevron" class="rtl:rotate-180" />
                            </button>
                        </span>
                        <button type="button" class="hc-btn hc-btn--link hc-btn--sm" x-on:click="toggle()" :aria-pressed="all.toString()">
                            <x-ic name="layout" /><span x-text="all ? @js(__('dashboard.header.decide.one')) : @js(__('dashboard.header.decide.all'))">{{ __('dashboard.header.decide.all') }}</span>
                        </button>
                    </span>
                @endif
            </div>

            <div class="hc-decide-track" :class="{ all }">
                @foreach ($cards as $card)
                    <article @class(['hc-dcard', 'old' => $card['old']]) wire:key="decide-{{ $card['key'] }}" data-decision="{{ $card['key'] }}"
                             :class="{ off: ! (all || i === {{ $loop->index }}) }" @unless ($loop->first) style="display:none" x-init="$el.style.removeProperty('display')" @endunless
                             aria-roledescription="slide" aria-label="{{ $loop->iteration }} / {{ $n }}">
                        <span class="hc-dcard-what"><x-ic :name="$card['action'] === 'approve' ? 'user' : 'calendar'" />{{ __('inbox.action.'.$card['action']) }}</span>
                        <span class="hc-dcard-name" dir="auto">{{ $card['name'] }}</span>
                        <span class="hc-dcard-meta">
                            @if ($card['what'])<span dir="auto">{{ $card['what'] }}</span>@endif
                            @if ($card['from'])<span class="num">{{ $range($card['from'], $card['to']) }}</span>@endif
                            @if ($card['city'])<span dir="auto">{{ $card['city'] }}</span>@endif
                            @if ($card['since'])<span class="hc-dcard-since">{{ __('dashboard.header.decide.since', ['time' => $card['since']]) }}</span>@endif
                            @if ($card['start_flag'])
                                <x-tag :variant="$card['start_flag'] === 'started' ? 'late' : 'rush'">{{ __($card['start_flag'] === 'started' ? 'inbox.started' : 'inbox.urgent') }}</x-tag>
                            @endif
                        </span>
                        <a href="{{ $card['url'] }}" wire:navigate class="hc-btn hc-btn--primary hc-btn--sm hc-dcard-go">
                            <x-ic name="arrow" class="rtl:-scale-x-100" />{{ __('dashboard.header.decide.verb.'.$card['action']) }}
                        </a>
                    </article>
                @endforeach
            </div>

            @if ($n > 1)
                <div class="hc-ddots" x-show="! all" role="tablist" aria-label="{{ __('dashboard.header.decide.pick') }}">
                    @foreach ($cards as $card)
                        <button type="button" role="tab" x-on:click="show({{ $loop->index }})" :aria-selected="(i === {{ $loop->index }}).toString()" :class="{ on: i === {{ $loop->index }} }" aria-label="{{ $loop->iteration }} / {{ $n }}"></button>
                    @endforeach
                </div>
            @endif

            @if ($decisions['total'] > $n)
                <p class="mt-2 text-hint text-ink-2">
                    <a href="{{ $inboxUrl }}" wire:navigate class="hc-btn hc-btn--link hc-btn--sm">{{ trans_choice('dashboard.header.decide.more', $decisions['total'] - $n, ['count' => $decisions['total'] - $n]) }}</a>
                </p>
            @endif
        </section>
    @endif
</div>
