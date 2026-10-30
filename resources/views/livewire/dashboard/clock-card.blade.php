{{--
    The clock tile (ClockCard). Both looks are drawn; the tile's data-look
    (its style, chosen in Customise) shows one of them (resources/css/dashboard.css,
    "clock tile"), and the tile's size decides how much of it shows: one row is
    the time only, two rows add the date, three rows the weekday and the HR line;
    taller, a bar of the working day, and taller still who is online
    (ClockPresence). On a phone it is one row, digital (dashboard.css, "phone clock").
    It ticks in the browser (resources/js/dashboard/clock.js); the server draws
    the first frame. The time is read out through the label, never announced
    as it changes.
--}}
<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <section class="hc-clock rounded-surface border border-line-soft bg-card text-ink shadow-sm"
                 x-data="hcClock(@js($config))"
                 aria-labelledby="hc-clock-title">
            <h2 id="hc-clock-title" class="sr-only">{{ __('dashboard.tiles.clock') }}</h2>

            <div class="hc-clock-main" role="img"
                 aria-label="{{ __('dashboard.clock.aria', ['time' => $time, 'weekday' => $weekday, 'date' => $date]) }}"
                 :aria-label="label()">
                <div class="hc-clock-digital" aria-hidden="true">
                    <span class="hc-clock-wd" x-text="wd()">{{ $weekday }}</span>
                    <span class="hc-clock-time num" x-text="t()">{{ $time }}</span>
                    <span class="hc-clock-date" x-text="d()">{{ $date }}</span>
                </div>

                <div class="hc-clock-analog" aria-hidden="true">
                    <svg class="hc-clock-face" viewBox="0 0 100 100" focusable="false">
                        <circle class="rim" cx="50" cy="50" r="47" />
                        @for ($i = 0; $i < 60; $i++)
                            @if ($i % 5 === 0)
                                <line class="tick major" x1="50" y1="7" x2="50" y2="14" transform="rotate({{ $i * 6 }} 50 50)" />
                            @else
                                <line class="tick" x1="50" y1="7" x2="50" y2="10" transform="rotate({{ $i * 6 }} 50 50)" />
                            @endif
                        @endfor
                        <line class="hand h" x1="50" y1="54" x2="50" y2="28" transform="rotate({{ $angles['h'] }} 50 50)" :transform="hand('h')" />
                        <line class="hand m" x1="50" y1="56" x2="50" y2="15" transform="rotate({{ $angles['m'] }} 50 50)" :transform="hand('m')" />
                        <line class="hand s" x1="50" y1="60" x2="50" y2="12" transform="rotate({{ $angles['s'] }} 50 50)" :transform="hand('s')" />
                        <circle class="hub" cx="50" cy="50" r="2.6" />
                    </svg>

                    <span class="hc-clock-aside">
                        <span class="hc-clock-wd" x-text="wd()">{{ $weekday }}</span>
                        <span class="hc-clock-time num" x-text="t()">{{ $time }}</span>
                        <span class="hc-clock-date" x-text="d()">{{ $date }}</span>
                    </span>
                </div>
            </div>

            {{-- Only with a known schedule (WorkdayClock): otherwise there is nothing to say. --}}
            @if ($config['day'] !== null)
                <p class="hc-clock-line num" data-clock-line x-show="line() !== null" x-text="line()">{{ $line }}</p>

                {{-- A big clock: how far through the working day (WorkdayClock::progress), or the holiday it is. --}}
                <div class="hc-clock-work" data-clock-work>
                    @if ($config['day']['off'])
                        @if ($config['day']['holiday'])
                            <p class="hc-clock-work-sub" data-clock-holiday>{{ __('dashboard_clock.work.holiday', ['name' => $config['day']['holiday']]) }}</p>
                        @endif
                    @else
                        <div x-show="progress() !== null">
                            <div class="hc-clock-bar" role="progressbar"
                                 aria-label="{{ __('dashboard_clock.work.progress') }}"
                                 aria-valuemin="0" aria-valuemax="100"
                                 aria-valuenow="{{ (int) round($progress['pct'] ?? 0) }}"
                                 :aria-valuenow="Math.round(progress()?.pct ?? 0)">
                                <i style="inline-size: {{ $progress['pct'] ?? 0 }}%" :style="{ inlineSize: (progress()?.pct ?? 0) + '%' }"></i>
                            </div>
                            <p class="hc-clock-work-sub">
                                <span class="num" data-clock-progress x-text="progressText()">{{ $progressText }}</span>
                                <span class="num" dir="ltr">{{ $hours }}</span>
                            </p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Taller still: who of the viewer's own scope is online now. It polls only while shown. --}}
            <livewire:dashboard.clock-presence wire:key="clock-presence" />
        </section>
    @endif

@assets
    {{-- The big clock (working day, who is online). The phone clock is in dashboard.css,
         next to the grid it changes; identity only (ink, neutrals, the red bar). --}}
    <style>
        .hc-clock-work,
        .hc-clock-presence { display: none; }
        .hc-clock-work { flex: none; }
        .hc-clock-bar { block-size: 6px; margin-block: 2px 6px; overflow: hidden; border-radius: 999px; background: var(--color-soft); }
        .hc-clock-bar > i { display: block; block-size: 100%; border-radius: inherit; background: var(--color-primary); }
        @media (prefers-reduced-motion: no-preference) { .hc-clock-bar > i { transition: inline-size .6s ease; } }
        .hc-clock-work-sub { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 10px; margin: 0; color: var(--color-ink-2); font-size: 13px; line-height: 1.35; }
        .hc-clock-presence { flex: 1.2 1 0; flex-direction: column; gap: 6px; min-block-size: 0; padding-block-start: 10px; border-block-start: 1px solid var(--color-line-soft); }
        .hc-clock-presence-head { display: flex; justify-content: space-between; gap: 8px; margin: 0; color: var(--color-ink-2); font-size: 12px; font-weight: 700; }
        .hc-clock-presence ul { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 4px 12px; min-block-size: 0; margin: 0; padding: 0; overflow-y: auto; list-style: none; }
        .hc-clock-presence li { display: flex; align-items: center; gap: 8px; min-inline-size: 0; font-size: 13px; }
        .hc-clock-presence li > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hc-clock-presence img { flex: none; inline-size: 26px; block-size: 26px; border-radius: 50%; object-fit: cover; box-shadow: 0 0 0 2px var(--color-card), 0 0 0 3px var(--color-ink); }
        .hc-clock-presence-empty { margin: 0; color: var(--color-ink-3); font-size: 13px; }

        @container tile (min-height: 300px) {
            .hc-clock-work { display: block; }
        }

        @container tile (min-height: 390px) and (min-width: 300px) {
            .hc-clock-presence { display: flex; }
            .hc-clock-main { flex: 1 1 0; }
            .hc-clock-time { font-size: clamp(34px, min(16cqh, 22cqw), 120px); }
        }

    </style>
@endassets
</div>
