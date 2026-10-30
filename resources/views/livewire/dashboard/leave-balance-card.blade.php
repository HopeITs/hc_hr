{{--
    "Leave balance" tile (LeaveBalanceCard): an SVG ring of the annual leave
    left, brand red on the surface track, the number of days in its centre.
    The ring follows the tile (container units); from a medium width the words
    and the button sit beside it instead of under it. No CSS colour of its own:
    the track and the arc are the surface and primary tokens, so dark mode
    flips the track by itself.
--}}
<div class="hc-balance flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.leave-balance') }}</h2>
        </header>

        @if ($balance === null)
            <p class="hc-nt-empty">{{ __('dashboard.leave-balance.none') }}</p>
        @else
            <div class="hc-balance-body">
                <div class="hc-ring" role="img" aria-label="{{ trans_choice('dashboard.leave-balance.left-of', $balance['remaining'], ['count' => $balance['remaining'], 'total' => $balance['total']]) }}">
                    <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                        <circle class="hc-ring-track" cx="60" cy="60" r="50" pathLength="100" />
                        @if ($balance['share'] > 0)
                            <circle class="hc-ring-arc" cx="60" cy="60" r="50" pathLength="100"
                                    stroke-dasharray="{{ round($balance['share'] * 100, 2) }} 100" />
                        @endif
                    </svg>
                    <span class="hc-ring-value" aria-hidden="true">
                        <span><span class="num">{{ $balance['remaining'] }}</span><span class="hc-ring-unit">/<span class="num">{{ $balance['total'] }}</span></span></span>
                    </span>
                </div>

                <div class="hc-balance-side">
                    <p class="hc-balance-words text-small text-ink-2">{{ trans_choice('dashboard.leave-balance.days-left', $balance['remaining'], ['count' => $balance['remaining']]) }}</p>
                    @if ($requestUrl)
                        <x-button size="sm" :href="$requestUrl" icon="plus">{{ __('dashboard.leave-balance.request') }}</x-button>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
