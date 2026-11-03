{{--
    The hello (Dashboard\Header, like hc-tracking's Desk): the date, the
    greeting, the day in two figures across from it (they lead down to the
    work inbox panel when it is on the desk), then Customize (it opens the
    sheet in home-screen.js through the hc-desk-open event). The New actions
    are not repeated here: the sidebar's New menu has them.
--}}
<section class="hc-hello" data-testid="dashboard-header">
    <div class="hc-hello-main">
        <p class="hc-eyebrow">{{ now()->translatedFormat('l, j F Y') }}</p>
        <h1 class="text-h1 text-ink" data-testid="dashboard-headline">{{ rtrim($greeting, ',،.! ') }}</h1>
    </div>

    @if ($figures !== null)
        @if ($figures['waiting'] === 0 && $figures['late'] === 0)
            <p class="hc-hello-clear" data-testid="dashboard-clear">{{ __('dashboard.header.clear') }}</p>
        @else
            <{{ $inboxPlaced ? 'a href=#hc-tile-decide' : 'div' }} class="hc-hello-figs" data-testid="dashboard-figures"
                @if ($inboxPlaced) title="{{ __('dashboard.header.figures-link') }}" @endif>
                <span class="hc-hello-fig"><b class="num">{{ $figures['waiting'] }}</b><span>{{ trans_choice('dashboard.header.waiting', $figures['waiting']) }}</span></span>
                @if ($figures['late'] > 0)
                    <span class="hc-hello-fig hc-late"><b class="num">{{ $figures['late'] }}</b><span>{{ trans_choice('dashboard.header.late-figure', $figures['late']) }}</span></span>
                @endif
            </{{ $inboxPlaced ? 'a' : 'div' }}>
        @endif
    @endif

    <div class="">
        <button type="button" class="hc-start-btn" x-data x-on:click="$dispatch('hc-desk-open')"
                aria-haspopup="dialog" data-tour="dashboard.customise" data-testid="dashboard-customize">
            <x-ic name="layout" class="hc-start-ic" /><span>{{ __('dashboard.desk.open') }}</span>
        </button>
    </div>
</section>
