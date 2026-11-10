{{--
    The analogue clock (2026-11-09), drawn like a classic wall clock: a dark rim, a white
    face, sixty minute marks (heavier every five), the numbers 1–12, tapered black hour
    and minute hands, a thin red second hand with a short tail, a red centre. Its own
    fixed colours, so it reads the same in light and dark. The hands turn by the same
    rotate() the clock script sets (dashboard/clock.js hand()); $angles is the first paint.
--}}
@props(['angles'])

<svg {{ $attributes->merge(['class' => 'hc-wall']) }} viewBox="0 0 100 100" aria-hidden="true" focusable="false">
    <circle class="hc-wall-rim" cx="50" cy="50" r="49" />
    <circle class="hc-wall-face" cx="50" cy="50" r="44.5" />
    @for ($i = 0; $i < 60; $i++)
        <line @class(['hc-wall-tick', 'is-hour' => $i % 5 === 0]) x1="50" y1="7.5" x2="50" y2="{{ $i % 5 === 0 ? 11.5 : 9.8 }}" transform="rotate({{ $i * 6 }} 50 50)" />
    @endfor
    @for ($n = 1; $n <= 12; $n++)
        @php($a = deg2rad($n * 30))
        <text class="hc-wall-num" x="{{ round(50 + 30.5 * sin($a), 2) }}" y="{{ round(50 - 30.5 * cos($a), 2) }}">{{ $n }}</text>
    @endfor
    <polygon class="hand h" points="47.6,55 52.4,55 51.1,25 48.9,25" transform="rotate({{ $angles['h'] }} 50 50)" :transform="hand('h')" />
    <polygon class="hand m" points="48.4,57 51.6,57 50.7,10.5 49.3,10.5" transform="rotate({{ $angles['m'] }} 50 50)" :transform="hand('m')" />
    <line class="hand s" x1="50" y1="63" x2="50" y2="9" transform="rotate({{ $angles['s'] }} 50 50)" :transform="hand('s')" />
    <circle class="hc-wall-hub" cx="50" cy="50" r="2.6" />
</svg>
