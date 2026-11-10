{{--
    "Request leave" tile (RequestLeaveCard): pick the type and the dates here,
    then the leave form opens on the leaves page with them filled in, where
    the reason, the cover and the cost are added and the request is sent. A
    plain GET form: it works before any script and changes nothing by itself.
--}}
<div class="hc-rl flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.request-leave') }}</h2>
            <x-ic name="plus" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>

        @if ($balances->isEmpty())
            <p class="hc-nt-empty">{{ __('dashboard.request-leave.none') }}</p>
        @else
            <form class="hc-rl-form" method="get" action="{{ route('leaves.index') }}" data-testid="request-leave-form"
                  x-data="{ from: @js($today), to: @js($today) }">
                <input type="hidden" name="new" value="1">
                <fieldset class="hc-rl-types">
                    <legend class="hc-rl-label">{{ trans_choice('general.leave-type', 1) }}</legend>
                    @foreach ($balances as $balance)
                        <label class="hc-rl-type">
                            <input type="radio" name="balance" value="{{ $balance->id }}" @checked($loop->first)>
                            <b class="truncate">{{ $balance->leaveType?->name }}</b>
                            <span class="num">{{ trans_choice('dashboard.request-leave.days', $balance->int_available, ['count' => $balance->int_available]) }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <div class="hc-rl-dates">
                    <label class="hc-rl-field">
                        <span class="hc-rl-label">{{ __('dashboard.request-leave.from') }}</span>
                        <input class="hc-rl-input num" type="date" name="date" min="{{ $today }}" value="{{ $today }}" required
                               x-model="from" x-on:change="if (to < from) to = from">
                    </label>
                    <label class="hc-rl-field">
                        <span class="hc-rl-label">{{ __('dashboard.request-leave.to') }}</span>
                        <input class="hc-rl-input num" type="date" name="to" min="{{ $today }}" value="{{ $today }}" required
                               x-model="to" x-bind:min="from">
                    </label>
                </div>
                <div class="hc-rl-foot">
                    <x-button type="submit" size="sm">{{ __('dashboard.request-leave.continue') }}</x-button>
                    <span class="text-small text-ink-2">{{ __('dashboard.request-leave.hint') }}</span>
                </div>
            </form>
        @endif
    @endif
</div>
