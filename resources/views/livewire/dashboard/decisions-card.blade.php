{{--
    "Work inbox" tile (DecisionsCard): what waits on the viewer. First the
    decisions, oldest first: one card at a time with arrows and dots, so the
    oldest is in front; "Show all" lays them side by side instead (kept on
    this device). Then the rest of the inbox (items to hand over or take
    back) as a short list. It took over "For you now", so the home has one
    place for the inbox. It scrolls inside a small tile. Nothing: one quiet
    line, marked data-tile-empty so the grid closes it at the bottom. No pay,
    no leave reason (WaitingOnYou::card).
--}}
@php
    $locale = app()->getLocale();
    $date = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMM') : '';
    $range = fn (?string $from, ?string $to) => $from === $to ? $date($from) : $date($from).' – '.$date($to);
    $cards = $decisions['cards'];
    $items = $decisions['items'] ?? [];
    $n = count($cards);
@endphp
<div class="hc-decide-tile flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
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
                    i: 0, n: {{ $n }}, all: false,
                    init() { try { this.all = localStorage.getItem('hc-decide') === 'all' } catch (e) {} },
                    show(k) { this.i = (k + this.n) % this.n },
                    toggle() { this.all = ! this.all; try { localStorage.setItem('hc-decide', this.all ? 'all' : 'one') } catch (e) {} },
                 }"
                 x-on:keydown.arrow-right="if (! all && n > 1) show(i + (getComputedStyle($el).direction === 'rtl' ? -1 : 1))"
                 x-on:keydown.arrow-left="if (! all && n > 1) show(i - (getComputedStyle($el).direction === 'rtl' ? -1 : 1))">
            <div class="hc-decide-head">
                <h2 id="hc-decide-title">
                    <x-ic name="inbox" />{{ __('dashboard.tiles.decide') }}
                    <span class="num hc-decide-count" data-testid="work-inbox-count">{{ $decisions['all'] }}</span>
                </h2>
                <x-button variant="link" size="sm" :href="$inboxUrl">{{ __('dashboard.decide-tile.open') }}</x-button>
            </div>

            @if ($n > 0)
                <div data-testid="decision-card" aria-roledescription="carousel" aria-label="{{ trans_choice('dashboard.header.decide.title', $decisions['total'], ['count' => $decisions['total']]) }}">
                    <div class="hc-decide-sub">
                        <h3>{{ trans_choice('dashboard.header.decide.title', $decisions['total'], ['count' => $decisions['total']]) }}</h3>
                        @if ($n > 1)
                            <span class="flex flex-wrap items-center gap-1">
                                <span class="flex items-center gap-1" x-show="! all">
                                    <x-button variant="quiet" size="sm" :loading="false" class="hc-decide-arrow" x-on:click="show(i - 1)" aria-label="{{ __('dashboard.header.decide.prev') }}">
                                        <x-ic name="chevron" class="rotate-180 rtl:rotate-0" />
                                    </x-button>
                                    <span class="num hc-decide-count" aria-live="polite"><span x-text="i + 1">1</span> / {{ $n }}</span>
                                    <x-button variant="quiet" size="sm" :loading="false" class="hc-decide-arrow" x-on:click="show(i + 1)" aria-label="{{ __('dashboard.header.decide.next') }}">
                                        <x-ic name="chevron" class="rtl:rotate-180" />
                                    </x-button>
                                </span>
                                <x-button variant="link" size="sm" icon="layout" :loading="false" x-on:click="toggle()" x-bind:aria-pressed="all.toString()">
                                    <span x-text="all ? @js(__('dashboard.header.decide.one')) : @js(__('dashboard.header.decide.all'))">{{ __('dashboard.header.decide.all') }}</span>
                                </x-button>
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
                                <x-button size="sm" :href="$card['url']" class="hc-dcard-go">
                                    <x-ic name="arrow" class="rtl:-scale-x-100" />{{ __('dashboard.header.decide.verb.'.$card['action']) }}
                                </x-button>
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
