{{--
    "Out today" tile (WhoIsOutCard). It follows its own size (the tile is the
    size container "tile", resources/css/dashboard.css "new tiles"): one row is
    a stack of faces; from two rows a list of faces, names and leave types,
    as many as the height holds. The "+N" is drawn once per capacity and the
    CSS shows the one that matches, so it always counts exactly who is not on
    screen.
--}}
<div class="hc-out flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.who-is-out') }}</h2>
            @if ($total > 0)
                <span class="num text-small font-bold text-ink-2">{{ $total }}</span>
            @endif
            @if ($calendarUrl)
                <x-button variant="link" size="sm" :href="$calendarUrl" class="hc-nt-link ms-auto">{{ __('dashboard.out-today.calendar') }}</x-button>
            @endif
        </header>

        @if ($total === 0)
            <p class="hc-nt-empty">
                <x-ic name="check-circle" class="size-5 shrink-0 text-ink-3" aria-hidden="true" />
                <span>{{ __('dashboard.out-today.empty') }}</span>
            </p>
        @else
            <div class="hc-out-body">
                <ul class="hc-out-list" role="list">
                    @foreach ($shown as $person)
                        <li wire:key="{{ $person['key'] }}" title="{{ $person['name'] }}">
                            <img src="{{ $person['avatar'] }}" alt="" loading="lazy" class="hc-nt-av">
                            <span class="hc-out-text min-w-0">
                                <span class="block truncate text-small font-bold text-ink">{{ $person['name'] }}</span>
                                <span class="block truncate text-caption text-ink-2">
                                    {{ $person['type'] }}@if ($person['hours']) · <bdi class="num">{{ $person['hours'] }}</bdi>@endif
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                @foreach ([2, 4, 6] as $cap)
                    @if ($total > $cap)
                        <span class="hc-out-more" data-cap="{{ $cap }}" aria-label="{{ trans_choice('dashboard.out-today.more', $total - $cap, ['count' => $total - $cap]) }}">
                            <span aria-hidden="true" class="num">+{{ $total - $cap }}</span>
                        </span>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</div>
