{{--
    "Needs your attention" tile (AttentionCard): the viewer's own contract
    running out with no renewal, and their own leave still waiting after its
    first day. Each line has a red mark (it waits on the viewer: one of red's
    three jobs) and one link to where it is dealt with. Nothing: one quiet
    line, marked data-tile-empty so the grid closes it at the bottom.
--}}
@php($locale = app()->getLocale())
<div class="hc-att flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.attention') }}</h2>
            <x-ic name="alert" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>

        @if ($notes === [])
            <p class="hc-nt-empty" data-tile-empty>{{ __('dashboard.attention.none') }}</p>
        @else
            <ul class="hc-att-list" data-testid="attention-list">
                @foreach ($notes as $note)
                    <li class="hc-att-item">
                        <span class="hc-att-mark" aria-hidden="true"></span>
                        <p class="hc-att-text">
                            @if ($note['kind'] === 'contract')
                                <b>{{ trans_choice('dashboard.attention.contract', $note['days'], ['count' => $note['days'], 'date' => $note['date']->locale($locale)->isoFormat('ddd D MMM')]) }}</b>
                                <span>{{ __('dashboard.attention.contract-hint') }}</span>
                            @else
                                <b>{{ __('dashboard.attention.leave', ['period' => $note['date']->isSameDay($note['last'])
                                    ? $note['date']->locale($locale)->isoFormat('D MMM')
                                    : $note['date']->locale($locale)->isoFormat('D MMM').' – '.$note['last']->locale($locale)->isoFormat('D MMM')]) }}</b>
                                <span>{{ __('dashboard.attention.leave-hint') }}</span>
                            @endif
                        </p>
                        <x-button variant="outline" size="sm" :href="$note['url']" class="shrink-0">
                            {{ $note['kind'] === 'contract' ? __('dashboard.attention.open-profile') : __('dashboard.attention.open-leave') }}
                        </x-button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
