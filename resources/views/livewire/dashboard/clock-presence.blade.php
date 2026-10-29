{{--
    "Online now" on a big clock (ClockPresence): faces and names of the people
    of the viewer's own scope who used the app in the last few minutes, and how
    many of that scope it is. Only a tall clock shows it (clock-card.blade.php);
    hidden, it is not "visible", so it never polls. Being listed is the status:
    no coloured dot.
--}}
<div class="hc-clock-presence" wire:poll.visible.60s data-clock-presence>
    <p class="hc-clock-presence-head">
        <span>{{ __('dashboard_clock.presence.title') }}</span>
        @if ($total > 0)
            <span class="num">{{ __('dashboard_clock.presence.count', ['n' => $count, 'all' => $total]) }}</span>
        @endif
    </p>

    @if ($count === 0)
        <p class="hc-clock-presence-empty">{{ __('dashboard_clock.presence.none') }}</p>
    @else
        <ul role="list">
            @foreach ($shown as $person)
                <li wire:key="online-{{ $person['id'] }}">
                    <img src="{{ $person['avatar'] }}" alt="" loading="lazy">
                    <span dir="auto">{{ $person['name'] }}</span>
                </li>
            @endforeach
            @if ($count > $shown->count())
                <li>
                    <span class="num" aria-hidden="true">{{ __('dashboard_clock.presence.more', ['n' => $count - $shown->count()]) }}</span>
                    <span class="sr-only">{{ __('dashboard_clock.presence.more-label', ['n' => $count - $shown->count()]) }}</span>
                </li>
            @endif
        </ul>
    @endif
</div>
