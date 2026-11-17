{{--
    "Today at a glance" tile (GlanceCard): four small tiles on a soft field, as
    one card. The weather now (an ink line icon, the condition, the
    temperature, the city), an analogue clock, the date and the twelve-hour
    time. Damascus time ticks in the browser (hcClock, clock.js); the server
    draws the first frame. Red only for "now" and "today": the clock's second
    hand and the month over today's date. Weather unavailable: one quiet line
    in its tile, the clock and the date carry on.
--}}
@php
    $kind = $weather['kind'] ?? null;
    $icons = [
        'clear-day' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'clear-night' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'partly' => '<path d="M8 3.5v1.5M3.5 8H5M4.8 4.8l1 1M11.2 4.8l-1 1"/><path d="M5.6 10.3A3 3 0 0 1 10.9 7"/><path d="M7.5 20h10a3.5 3.5 0 0 0 .4-7 5 5 0 0 0-9.6 1.2A3 3 0 0 0 7.5 20z"/>',
        'cloudy' => '<path d="M6.5 19h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 19z"/>',
        'fog' => '<path d="M6.5 13h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 13z"/><path d="M4 17h16M6 20.5h12"/>',
        'drizzle' => '<path d="M6.5 14h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 14z"/><path d="M8 17.5v1M12 17.5v1M16 17.5v1"/>',
        'rain' => '<path d="M6.5 14h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 14z"/><path d="m8 17-1 3M12 17l-1 3M16 17l-1 3"/>',
        'snow' => '<path d="M6.5 14h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 14z"/><path d="M8 18h.01M12 20h.01M16 18h.01M10 21.5h.01M14 21.5h.01"/>',
        'storm' => '<path d="M6.5 14h11a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.25 3.25 0 0 0 6.5 14z"/><path d="m12.5 15-2.5 4h3l-2 3.5"/>',
    ];
    $icon = $kind === 'clear' ? ($weather['day'] ? 'clear-day' : 'clear-night') : $kind;
@endphp
<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <section class="hc-glance rounded-surface border border-line-soft bg-soft shadow-sm"
                 x-data="hcClock(@js($config))" aria-labelledby="hc-glance-title">
            <h2 id="hc-glance-title" class="sr-only">{{ __('dashboard.tiles.glance') }}</h2>

            {{-- The weather now, in the viewer's work city. --}}
            {{-- The credit both sources ask for (CC BY 4.0) is the tile's hover hint, not a line on the card. --}}
            <div class="hc-glance-tile hc-glance-weather" data-testid="glance-weather"
                 @if ($weather) title="{{ __('dashboard.glance.source', ['name' => __('dashboard.glance.sources.'.$weather['source'])]) }}" @endif>
                @if ($weather)
                    {{-- A quiet scene behind the words, in ink at low opacity (never a hue): turning
                         sun, twinkling stars, drifting clouds, sliding fog, falling rain or snow, a
                         storm's flash. Decorative only; still with prefers-reduced-motion. --}}
                    @php
                        $wet = in_array($kind, ['drizzle', 'rain', 'storm'], true);
                        $spots = [[8, 0.0], [17, 0.9], [26, 0.3], [35, 1.4], [44, 0.6], [53, 1.1], [62, 0.2], [71, 1.6], [80, 0.8], [89, 1.3], [12, 1.9], [58, 2.2]];
                    @endphp
                    <div class="hc-wx hc-wx--{{ $icon }}" aria-hidden="true" data-testid="glance-scene">
                        @if ($icon === 'clear-day' || $icon === 'partly')
                            <svg class="hc-wx-sun" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                <circle cx="50" cy="50" r="18" vector-effect="non-scaling-stroke" />
                                @for ($r = 0; $r < 12; $r++)
                                    <line x1="50" y1="14" x2="50" y2="24" transform="rotate({{ $r * 30 }} 50 50)" vector-effect="non-scaling-stroke" />
                                @endfor
                            </svg>
                        @endif
                        @if ($icon === 'clear-night')
                            {{-- Stars only: four-pointed sparkles, each twinkling in its own time. --}}
                            @foreach ([[48, 14, 0.0, 16], [80, 12, 1.1, 20], [66, 42, 2.3, 12], [88, 66, 0.6, 14], [56, 72, 1.8, 10]] as [$x, $y, $d, $size])
                                <svg class="hc-wx-spark" style="--x: {{ $x }}%; --y: {{ $y }}%; --d: {{ $d }}s; --s: {{ $size }}px" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2.5 13.6 10.4 21.5 12 13.6 13.6 12 21.5 10.4 13.6 2.5 12 10.4 10.4z" />
                                </svg>
                            @endforeach
                        @endif
                        @if (in_array($icon, ['partly', 'cloudy', 'fog', 'drizzle', 'rain', 'snow', 'storm'], true))
                            {{-- One cloud, high on the right, whole inside the tile even at the end of its drift:
                                 its drawing box holds the whole outline (nothing clipped). --}}
                            <svg class="hc-wx-cloud" style="--x: 40%; --y: 12%" viewBox="0 -4 64 40" fill="none" stroke="currentColor" stroke-linejoin="round">
                                <path d="M14 32h36a11 11 0 0 0 1.6-21.9A16 16 0 0 0 20.6 14 9 9 0 0 0 14 32z" vector-effect="non-scaling-stroke" />
                            </svg>
                        @endif
                        @if ($icon === 'fog')
                            @foreach ([0, 1, 2] as $f)
                                <span class="hc-wx-fog" style="--i: {{ $f }}"></span>
                            @endforeach
                        @endif
                        @if ($wet)
                            @foreach (array_slice($spots, 0, $kind === 'drizzle' ? 8 : 12) as [$x, $d])
                                <span class="hc-wx-drop" style="--x: {{ $x }}%; --d: {{ $d }}s"></span>
                            @endforeach
                        @endif
                        @if ($kind === 'snow')
                            @foreach ($spots as [$x, $d])
                                <span class="hc-wx-flake" style="--x: {{ $x }}%; --d: {{ $d * 2 }}s"></span>
                            @endforeach
                        @endif
                        @if ($kind === 'storm')
                            <span class="hc-wx-flash"></span>
                        @endif
                    </div>
                    <p class="hc-glance-sky">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$icon] !!}</svg>
                        <span>{{ __('dashboard.glance.sky.'.$kind) }}</span>
                    </p>
                    <p class="hc-glance-temp num" dir="ltr"><span>{{ $weather['temp'] }}</span><span aria-hidden="true">°</span><span class="sr-only">{{ __('dashboard.glance.degrees') }}</span></p>
                    <p class="hc-glance-city">{{ $city }}</p>
                @else
                    <p class="hc-glance-sky">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['cloudy'] !!}</svg>
                        <span>{{ $city ?? __('dashboard.tiles.glance') }}</span>
                    </p>
                    <p class="hc-glance-none">{{ __('dashboard.glance.no-weather') }}</p>
                @endif
            </div>

            {{-- An analogue clock, drawn like a wall clock (components/dashboard/wall-clock). --}}
            <div class="hc-glance-tile hc-glance-clock" role="img"
                 aria-label="{{ __('dashboard.clock.aria', ['time' => $now->format('H:i'), 'weekday' => $now->isoFormat('dddd'), 'date' => $now->isoFormat('D MMMM YYYY')]) }}"
                 :aria-label="label()">
                <x-dashboard.wall-clock class="hc-glance-face" :angles="$angles" />
            </div>

            {{-- Today's date: the month in red (today), the day large. --}}
            <div class="hc-glance-tile hc-glance-date" aria-hidden="true">
                <span class="hc-glance-mon" x-text="mon()">{{ $now->isoFormat('MMM') }}</span>
                <span class="hc-glance-dom num" x-text="dom()">{{ $now->format('d') }}</span>
            </div>

            {{-- The time, twelve-hour, its day period beside a thin rule. --}}
            <div class="hc-glance-tile hc-glance-time" aria-hidden="true">
                <span class="hc-glance-hm num" x-text="t12()">{{ $now->format('g:i') }}</span>
                <span class="hc-glance-rule"></span>
                <span class="hc-glance-period" x-text="period()">{{ $now->isoFormat('A') }}</span>
            </div>
        </section>
    @endif
</div>
