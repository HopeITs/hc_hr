{{--
    The shown month's name as a button that opens a year + twelve-month grid,
    so any month is one or two clicks away instead of many arrow presses.
    Picking a month calls the component's $method(year, month).

    value:  the month shown (any CarbonInterface inside it).
    method: the Livewire action to call, jumpTo by default.
    label:  the trigger's words; the month and year by default.

    Tiles clip what overflows them, so the panel lives on <body>, anchored
    to the trigger (wire:ignore keeps a re-render from morphing it there). The current month is the current page (red); this
    month gets an ink outline.
--}}
@props(['value', 'method' => 'jumpTo', 'label' => null])

@php
    $locale = app()->getLocale();
    $value = \Carbon\CarbonImmutable::instance($value)->locale($locale);
    $today = \Carbon\CarbonImmutable::today();
    $minYear = min($value->year, $today->year) - 15;
    $maxYear = max($value->year, $today->year) + 5;
    $months = collect(range(1, 12))->map(fn (int $m) => $value->setDate($value->year, $m, 1));
    // Stable across re-renders: the trigger's aria-controls must keep naming the panel.
    $panelId = 'month-picker-'.$value->format('Y-m').'-'.substr(md5($method), 0, 6);
@endphp

{{-- Keyed by month: a new month replaces the element, so Alpine starts fresh. --}}
<div class="inline-flex" wire:key="month-picker-{{ $value->format('Y-m') }}"
     x-data="{
        open: false,
        year: {{ $value->year }},
        shownYear: {{ $value->year }},
        shownMonth: {{ $value->month }},
        todayYear: {{ $today->year }},
        todayMonth: {{ $today->month }},
        minYear: {{ $minYear }},
        maxYear: {{ $maxYear }},
        toggle() {
            if (this.open) return this.close();
            this.year = this.shownYear;
            this.open = true;
            this.$nextTick(() => document.getElementById(@js($panelId))?.querySelector('[data-month=\'' + this.shownMonth + '\']')?.focus());
        },
        close(refocus = true) {
            this.open = false;
            refocus && this.$refs.trigger.focus();
        },
        shift(step) { this.year = Math.min(this.maxYear, Math.max(this.minYear, this.year + step)) },
        pick(month) {
            this.open = false;
            this.$wire[@js($method)](this.year, month);
        },
     }">
    <button type="button" x-ref="trigger" x-on:click="toggle()"
            aria-haspopup="dialog" :aria-expanded="open" aria-controls="{{ $panelId }}"
            title="{{ __('month_picker.open') }}" data-testid="month-picker"
            {{ $attributes->class('inline-flex items-center gap-1.5 rounded-control text-start transition hover:bg-hover') }}>
        <span class="truncate">{{ $label ?? $value->translatedFormat('F Y') }}</span>
        <x-ic name="chevron" class="size-4 shrink-0 rotate-90 text-ink-2 transition" x-bind:class="open && '-rotate-90'" />
    </button>

    {{-- wire:ignore: a re-render would morph away Alpine's display:none and anchor
         position, leaving the panel in the page flow. A new month replaces it (wire:key). --}}
    <template x-teleport="body">
        <div id="{{ $panelId }}" role="dialog" aria-label="{{ __('month_picker.label') }}" wire:ignore
             x-cloak x-show="open" x-transition.opacity.duration.100ms
             x-anchor.bottom-start.offset.6="$refs.trigger"
             x-on:click.outside="$refs.trigger.contains($event.target) || close(false)"
             x-on:keydown.escape.prevent.stop="close()"
             class="z-50 w-72 rounded-surface border border-line-soft bg-card p-3 text-ink shadow">
            <div class="flex items-center gap-1">
                <button type="button" class="hc-cal-nav disabled:opacity-40" x-on:click="shift(-1)" :disabled="year <= minYear"
                        aria-label="{{ __('month_picker.previous_year') }}" title="{{ __('month_picker.previous_year') }}">
                    <x-ic name="chevron" class="size-4 -scale-x-100 rtl:scale-x-100" />
                </button>
                <label class="sr-only" for="{{ $panelId }}-year">{{ __('month_picker.year') }}</label>
                <select id="{{ $panelId }}-year" x-model.number="year"
                        class="num min-h-9 flex-1 rounded-control border border-line bg-card px-2 text-center text-base font-bold text-ink">
                    @foreach (range($maxYear, $minYear) as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
                <button type="button" class="hc-cal-nav disabled:opacity-40" x-on:click="shift(1)" :disabled="year >= maxYear"
                        aria-label="{{ __('month_picker.next_year') }}" title="{{ __('month_picker.next_year') }}">
                    <x-ic name="chevron" class="size-4 rtl:-scale-x-100" />
                </button>
            </div>

            <div class="mt-3 grid grid-cols-3 gap-1.5">
                @foreach ($months as $month)
                    <button type="button" data-month="{{ $month->month }}" x-on:click="pick({{ $month->month }})"
                            :aria-current="year === shownYear && {{ $month->month }} === shownMonth ? 'date' : null"
                            :class="year === shownYear && {{ $month->month }} === shownMonth
                                ? 'bg-primary text-white hover:bg-primary-hover'
                                : (year === todayYear && {{ $month->month }} === todayMonth ? 'border-ink hover:bg-hover' : 'hover:bg-hover')"
                            class="min-h-10 rounded-control border border-transparent px-1 text-small font-semibold transition">
                        {{ $month->translatedFormat('F') }}
                    </button>
                @endforeach
            </div>
        </div>
    </template>
</div>
